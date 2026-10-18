<?php

namespace App\Services\Gestionale\Banca;

use App\Actions\Gestionale\Movimenti\RegistraRegolazioneImmediataAction;
use App\Actions\Gestionale\Movimenti\StoreIncassoRateAction;
use App\Enums\MetodoPagamento;
use App\Enums\TipoAllocazioneFattura;
use App\Exceptions\Gestionale\AbbinamentoBancarioException;
use App\Exceptions\Gestionale\IncassoNonRegistrabileException;
use App\Exceptions\Gestionale\RegolazioneImmediataNonAmmessaException;
use App\Exceptions\Pagamenti\IbanDiscrepanzaException;
use App\Exceptions\Pagamenti\InsufficientFundsException;
use App\Exceptions\Pagamenti\PagamentoException;
use App\Exceptions\Pagamenti\PossibilePagamentoDuplicatoException;
use App\Helpers\MoneyHelper;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Banca\MovimentoBancario;
use App\Models\Gestionale\Banca\RegolaBancaria;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\PagamentoFornitoreService;
use App\Services\Gestionale\SincronizzaScadenziarioIncasso;
use App\Traits\HasEsercizio;
use Illuminate\Support\Facades\DB;

/**
 * Conferma degli abbinamenti: dal movimento della banca alla contabilità, **sempre attraverso i
 * flussi che esistono già**.
 *
 * - Entrata → `StoreIncassoRateAction` + `SincronizzaScadenziarioIncasso`: la stessa scrittura,
 *   le stesse quote pagate e lo stesso scadenziario della maschera incassi.
 * - Uscita verso un fornitore → `PagamentoFornitoreService::registraPagamento`: ritenuta pro
 *   quota, commissioni, stato delle fatture, evento per l'F24, sentinella IBAN, controllo di
 *   capienza e doppioni restano quelli della maschera pagamenti. La chiave di idempotenza è
 *   quella del movimento: una doppia conferma restituisce lo stesso pagamento.
 * - Commissioni, bolli e simili → `RegistraRegolazioneImmediataAction`.
 * - Già a giornale → solo il collegamento.
 *
 * Qui non si scrive nessuna riga contabile. Ogni operazione è in una transazione: se il
 * collegamento fallisce, la scrittura appena creata non resta orfana.
 */
final class AbbinamentoBancarioService
{
    use HasEsercizio;

    public function __construct(
        private readonly ScrittureGiaRegistrate $giaRegistrate,
        private readonly MemoriaControparti $memoria,
        private readonly ProposteAbbinamentoService $proposte,
    ) {}

    // ── Incasso rate ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  list<array{quota_id: int, importo: int}>  $righe  importi in centesimi, per quota «faro»
     */
    public function registraIncasso(
        MovimentoBancario $m,
        int $paganteId,
        array $righe,
        int $eccedenza,
        ?string $descrizione,
        ?int $userId,
    ): ScritturaContabile {
        $this->assicuraDaAbbinare($m);

        if ($m->importo <= 0) {
            throw new AbbinamentoBancarioException('Un incasso rate si abbina solo a un accredito.');
        }

        $righe = array_values(array_filter($righe, fn ($r) => (int) $r['importo'] > 0));
        $somma = array_sum(array_map(fn ($r) => (int) $r['importo'], $righe)) + $eccedenza;

        if ($somma !== $m->importo) {
            throw new AbbinamentoBancarioException(sprintf(
                'Le rate scelte e l\'eccedenza fanno € %s, il movimento è di € %s.',
                number_format($somma / 100, 2, ',', '.'),
                number_format($m->importo / 100, 2, ',', '.')
            ));
        }

        if ($righe === [] && $eccedenza > 0) {
            throw new AbbinamentoBancarioException('Scegli almeno una rata: un incasso senza rate non ha dove andare.');
        }

        $condominio = $m->condominio;

        if (! $this->proposte->anagraficheDelCondominio($condominio->id)->has($paganteId)) {
            throw new AbbinamentoBancarioException('Il pagante scelto non è un condòmino di questo stabile.');
        }

        $quoteDelCondominio = RataQuote::whereIn('id', array_column($righe, 'quota_id'))
            ->whereHas('rata.pianoRate', fn ($q) => $q->where('condominio_id', $condominio->id))
            ->count();

        if ($quoteDelCondominio !== count(array_unique(array_column($righe, 'quota_id')))) {
            throw new AbbinamentoBancarioException('Una delle rate scelte non appartiene a questo condominio.');
        }

        $esercizio = $this->esercizioPer($condominio, $m);

        $validated = [
            'pagante_id' => $paganteId,
            'cassa_id' => $m->cassa_id,
            'gestione_id' => null,
            'data_pagamento' => $m->data_contabile->toDateString(),
            'importo_totale' => MoneyHelper::fromCents($m->importo),
            'descrizione' => mb_substr($descrizione ?: $this->causaleDi($m, 'Incasso rate'), 0, 255),
            'eccedenza' => MoneyHelper::fromCents($eccedenza),
            'dettaglio_pagamenti' => array_map(fn ($r) => [
                'rata_id' => (int) $r['quota_id'],
                'importo' => MoneyHelper::fromCents((int) $r['importo']),
            ], $righe),
        ];

        try {
            return DB::transaction(function () use ($m, $validated, $condominio, $esercizio, $paganteId, $userId) {
                $scrittura = app(StoreIncassoRateAction::class)->execute($validated, $condominio, $esercizio);

                app(SincronizzaScadenziarioIncasso::class)->dopoIncasso($paganteId, $validated['dettaglio_pagamenti']);

                $this->collega($m, $scrittura->id, MovimentoBancario::ABBINAMENTO_INCASSO, $userId);
                $this->memoria->ricorda($m->condominio_id, $m->iban_controparte, $m->controparte, $paganteId, null);

                return $scrittura;
            });
        } catch (IncassoNonRegistrabileException $e) {
            throw new AbbinamentoBancarioException($e->getMessage(), null, $e);
        }
    }

    // ── Pagamento fornitore ──────────────────────────────────────────────────────────────────

    /**
     * @param  array<int, int>  $allocazioni  fattura_id → centesimi pagati (netto bonificato)
     * @param  array{iban_confermato_manualmente?: bool, allow_overdraft?: bool, conferma_duplicato_verificato?: bool, nota_override?: string|null}  $conferme
     */
    public function registraPagamento(MovimentoBancario $m, array $allocazioni, int $commissioni, array $conferme, ?int $userId): ScritturaContabile
    {
        $this->assicuraDaAbbinare($m);

        if ($m->importo >= 0) {
            throw new AbbinamentoBancarioException('Un pagamento fornitore si abbina solo a un addebito.');
        }

        $allocazioni = array_filter($allocazioni, fn ($c) => (int) $c > 0);
        $totale = array_sum($allocazioni) + $commissioni;

        if ($allocazioni === []) {
            throw new AbbinamentoBancarioException('Scegli almeno una fattura da pagare.');
        }

        if ($totale !== abs($m->importo)) {
            throw new AbbinamentoBancarioException(sprintf(
                'Fatture e commissioni fanno € %s, l\'addebito è di € %s.',
                number_format($totale / 100, 2, ',', '.'),
                number_format(abs($m->importo) / 100, 2, ',', '.')
            ));
        }

        $fatture = FatturaPassiva::where('condominio_id', $m->condominio_id)
            ->whereIn('id', array_keys($allocazioni))
            ->get();

        if ($fatture->count() !== count($allocazioni)) {
            throw new AbbinamentoBancarioException('Una delle fatture scelte non appartiene a questo condominio.');
        }

        if ($fatture->pluck('fornitore_id')->unique()->count() !== 1) {
            throw new AbbinamentoBancarioException('Un bonifico paga un solo fornitore: le fatture scelte sono di fornitori diversi.');
        }

        $m->loadMissing('cassa');
        $esercizio = $this->esercizioPer($m->condominio, $m);

        $data = [
            'fornitore_id' => (int) $fatture->first()->fornitore_id,
            'condominio_id' => $m->condominio_id,
            'esercizio_id' => $esercizio->id,
            'conto_corrente_id' => (int) $m->cassa->conto_contabile_id,
            'data_pagamento' => $m->data_contabile->toDateString(),
            'data_valuta' => $m->data_valuta?->toDateString(),
            'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
            'allocazioni' => collect($allocazioni)->map(fn ($c, $id) => [
                'fattura_id' => (int) $id,
                'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
                'importo_allocato_cents' => (int) $c,
            ])->values()->all(),
            'importo_commissioni_cents' => $commissioni,
            'iban_beneficiario' => $m->iban_controparte,
            'riferimento_bancario' => $m->riferimento,
            'idempotency_key' => $m->idempotency_key,
            'iban_confermato_manualmente' => (bool) ($conferme['iban_confermato_manualmente'] ?? false),
            'allow_overdraft' => (bool) ($conferme['allow_overdraft'] ?? false),
            'conferma_duplicato_verificato' => (bool) ($conferme['conferma_duplicato_verificato'] ?? false),
            'nota_override' => $conferme['nota_override'] ?? null,
        ];

        try {
            return DB::transaction(function () use ($m, $data, $userId) {
                $pagamento = app(PagamentoFornitoreService::class)->registraPagamento($data);
                $scrittura = ScritturaContabile::findOrFail($pagamento->scrittura_contabile_id);

                $this->collega($m, $scrittura->id, MovimentoBancario::ABBINAMENTO_PAGAMENTO, $userId);
                $this->memoria->ricorda($m->condominio_id, $m->iban_controparte, $m->controparte, null, (int) $data['fornitore_id']);

                return $scrittura;
            });
        } catch (IbanDiscrepanzaException $e) {
            throw new AbbinamentoBancarioException($e->getMessage(), 'iban_discrepanza', $e);
        } catch (InsufficientFundsException $e) {
            throw new AbbinamentoBancarioException($e->getMessage(), 'saldo_insufficiente', $e);
        } catch (PossibilePagamentoDuplicatoException $e) {
            throw new AbbinamentoBancarioException($e->getMessage(), 'possibile_duplicato', $e);
        } catch (PagamentoException $e) {
            throw new AbbinamentoBancarioException($e->getMessage(), null, $e);
        }
    }

    // ── Regolazione immediata (commissioni, bolli…) ─────────────────────────────────────────

    public function registraRegolazione(MovimentoBancario $m, int $contoId, int $gestioneId, ?string $causale, ?int $userId): ScritturaContabile
    {
        $this->assicuraDaAbbinare($m);

        if ($m->importo >= 0) {
            throw new AbbinamentoBancarioException('Una spesa sul conto di costo si abbina solo a un addebito.');
        }

        $condominio = $m->condominio;

        if (! $condominio->gestioni()->whereKey($gestioneId)->exists()) {
            throw new AbbinamentoBancarioException('La gestione scelta non appartiene a questo condominio.');
        }

        $esercizio = $this->esercizioPer($condominio, $m);

        $validated = [
            'conto_id' => $contoId,
            'gestione_id' => $gestioneId,
            'cassa_id' => $m->cassa_id,
            'fornitore_id' => null,
            'data_operazione' => $m->data_contabile->toDateString(),
            'causale' => mb_substr($causale ?: $this->causaleDi($m, 'Spese bancarie'), 0, 255),
            'importo' => MoneyHelper::fromCents(abs($m->importo)),
        ];

        try {
            return DB::transaction(function () use ($m, $validated, $condominio, $esercizio, $userId) {
                $scrittura = app(RegistraRegolazioneImmediataAction::class)->execute($validated, $condominio, $esercizio);
                $this->collega($m, $scrittura->id, MovimentoBancario::ABBINAMENTO_REGOLAZIONE, $userId);

                return $scrittura;
            });
        } catch (RegolazioneImmediataNonAmmessaException $e) {
            throw new AbbinamentoBancarioException($e->getMessage(), null, $e);
        }
    }

    public function applicaRegola(MovimentoBancario $m, RegolaBancaria $regola, ?int $userId): ?ScritturaContabile
    {
        if ($regola->condominio_id !== $m->condominio_id) {
            throw new AbbinamentoBancarioException('La regola non appartiene a questo condominio.');
        }

        if ($regola->azione === RegolaBancaria::AZIONE_IGNORA) {
            $this->ignora($m, 'Regola «'.$regola->nome.'»', $userId);

            return null;
        }

        if (! $regola->conto_id || ! $regola->gestione_id) {
            throw new AbbinamentoBancarioException("La regola «{$regola->nome}» non ha un capitolo e una gestione: completala prima di usarla.");
        }

        return $this->registraRegolazione($m, $regola->conto_id, $regola->gestione_id, $regola->causale, $userId);
    }

    // ── Collegamento a una scrittura esistente ──────────────────────────────────────────────

    /**
     * Collega il movimento a una scrittura già a giornale.
     *
     * Senza `forza` la scrittura deve muovere sulla cassa esattamente l'importo della banca e non
     * essere già collegata a un altro movimento. Con `forza` si ammette il caso del bonifico
     * registrato con le commissioni in una sola scrittura, che in banca sono due righe.
     */
    public function collegaEsistente(MovimentoBancario $m, int $scritturaId, bool $forza, ?int $userId): ScritturaContabile
    {
        $this->assicuraDaAbbinare($m);
        $m->loadMissing('cassa');

        $scrittura = ScritturaContabile::where('condominio_id', $m->condominio_id)->find($scritturaId);

        if (! $scrittura) {
            throw new AbbinamentoBancarioException('Scrittura non trovata in questo condominio.');
        }

        if ($scrittura->stato === 'annullata') {
            throw new AbbinamentoBancarioException('La scrittura è stata stornata: non può rappresentare un movimento della banca.');
        }

        if (! $forza) {
            $netto = $this->giaRegistrate->nettoSullaCassa($scrittura->id, $m->cassa);

            if ($netto !== $m->importo) {
                throw new AbbinamentoBancarioException(sprintf(
                    'La scrittura muove € %s sul conto, la banca € %s. Conferma il collegamento solo se la differenza è voluta (es. commissioni registrate insieme al bonifico).',
                    number_format($netto / 100, 2, ',', '.'),
                    number_format($m->importo / 100, 2, ',', '.')
                ), 'importo_diverso');
            }

            if (MovimentoBancario::where('scrittura_contabile_id', $scrittura->id)->exists()) {
                throw new AbbinamentoBancarioException('La scrittura è già collegata a un altro movimento della banca.', 'gia_collegata');
            }
        }

        return DB::transaction(function () use ($m, $scrittura, $userId) {
            $this->collega($m, $scrittura->id, MovimentoBancario::ABBINAMENTO_ESISTENTE, $userId);
            $this->impara($m, $scrittura);

            return $scrittura;
        });
    }

    /**
     * Il collegamento in sé: stato del movimento e stato `riconciliata` della scrittura, che il
     * gestionale prevede da sempre e non scriveva mai.
     */
    public function collega(MovimentoBancario $m, int $scritturaId, string $tipo, ?int $userId): void
    {
        $m->forceFill([
            'stato' => MovimentoBancario::STATO_ABBINATO,
            'tipo_abbinamento' => $tipo,
            'scrittura_contabile_id' => $scritturaId,
            'abbinato_da' => $userId,
            'abbinato_at' => now(),
        ])->save();

        ScritturaContabile::whereKey($scritturaId)
            ->where('stato', 'registrata')
            ->update(['stato' => 'riconciliata']);
    }

    /**
     * Toglie l'abbinamento. **Non storna** la scrittura: se era sbagliata si storna dal suo flusso
     * (incassi, pagamenti, regolazioni), che sa come farlo senza cancellare il giornale.
     */
    public function scollega(MovimentoBancario $m): void
    {
        DB::transaction(function () use ($m) {
            $scritturaId = $m->scrittura_contabile_id;

            $m->forceFill([
                'stato' => MovimentoBancario::STATO_DA_ABBINARE,
                'tipo_abbinamento' => null,
                'scrittura_contabile_id' => null,
                'abbinato_da' => null,
                'abbinato_at' => null,
                'note' => null,
            ])->save();

            if ($scritturaId && ! MovimentoBancario::where('scrittura_contabile_id', $scritturaId)->exists()) {
                ScritturaContabile::whereKey($scritturaId)
                    ->where('stato', 'riconciliata')
                    ->update(['stato' => 'registrata']);
            }
        });
    }

    public function ignora(MovimentoBancario $m, ?string $nota, ?int $userId): void
    {
        $this->assicuraDaAbbinare($m);

        $m->forceFill([
            'stato' => MovimentoBancario::STATO_IGNORATO,
            'tipo_abbinamento' => null,
            'note' => $nota ? mb_substr($nota, 0, 1000) : null,
            'abbinato_da' => $userId,
            'abbinato_at' => now(),
        ])->save();
    }

    // ── Conferma multipla ───────────────────────────────────────────────────────────────────

    /**
     * Applica la proposta migliore, se è abbastanza sicura. Restituisce l'esito per il riepilogo.
     *
     * @return array{esito: 'abbinato'|'ignorato'|'saltato'|'errore', messaggio: string|null}
     */
    public function applicaMigliore(MovimentoBancario $m, ?int $userId): array
    {
        if ($m->stato !== MovimentoBancario::STATO_DA_ABBINARE) {
            return ['esito' => 'saltato', 'messaggio' => 'già trattato'];
        }

        $p = $this->proposte->proponi($m);
        $migliore = $p['migliore'];

        if (! $migliore || ! $migliore['automatica']) {
            return ['esito' => 'saltato', 'messaggio' => 'nessuna proposta abbastanza sicura'];
        }

        try {
            switch ($migliore['tipo']) {
                case 'esistente':
                    $this->collegaEsistente($m, $migliore['scrittura_id'], false, $userId);
                    break;

                case 'regola':
                    $regola = RegolaBancaria::findOrFail($migliore['regola_id']);
                    $this->applicaRegola($m, $regola, $userId);

                    return ['esito' => $regola->azione === RegolaBancaria::AZIONE_IGNORA ? 'ignorato' : 'abbinato', 'messaggio' => null];

                case 'incasso':
                    $this->registraIncasso(
                        $m,
                        $migliore['anagrafica_id'],
                        array_map(fn ($q) => ['quota_id' => $q['quota_id'], 'importo' => $q['da_pagare']], $p['incasso']['quote']),
                        $p['incasso']['eccedenza'],
                        null,
                        $userId,
                    );
                    break;

                case 'pagamento':
                    $this->registraPagamento($m, [$migliore['fattura_id'] => abs($m->importo)], 0, [], $userId);
                    break;

                default:
                    return ['esito' => 'saltato', 'messaggio' => null];
            }
        } catch (AbbinamentoBancarioException $e) {
            return ['esito' => 'errore', 'messaggio' => $e->getMessage()];
        }

        return ['esito' => 'abbinato', 'messaggio' => null];
    }

    // ── Supporto ────────────────────────────────────────────────────────────────────────────

    /** Dal collegamento a una scrittura esistente si impara chi è la controparte. */
    private function impara(MovimentoBancario $m, ScritturaContabile $scrittura): void
    {
        if ($m->importo > 0) {
            $contoCrediti = ContoContabile::where('condominio_id', $m->condominio_id)
                ->where('ruolo', 'crediti_condomini')
                ->value('id');

            $anagrafiche = $scrittura->righe()
                ->where('conto_contabile_id', $contoCrediti)
                ->whereNotNull('anagrafica_id')
                ->distinct()
                ->pluck('anagrafica_id');

            if ($anagrafiche->count() === 1) {
                $this->memoria->ricorda($m->condominio_id, $m->iban_controparte, $m->controparte, (int) $anagrafiche->first(), null);
            }

            return;
        }

        $fornitoreId = $scrittura->pagamentoFornitore?->fornitore_id;

        if ($fornitoreId) {
            $this->memoria->ricorda($m->condominio_id, $m->iban_controparte, $m->controparte, null, (int) $fornitoreId);
        }
    }

    private function assicuraDaAbbinare(MovimentoBancario $m): void
    {
        if ($m->stato !== MovimentoBancario::STATO_DA_ABBINARE) {
            throw new AbbinamentoBancarioException('Il movimento è già stato abbinato o ignorato: annulla prima l\'abbinamento.');
        }
    }

    /**
     * L'esercizio in cui registrare: quello aperto che contiene la data della banca, altrimenti
     * quello corrente come fa la maschera incassi. Senza esercizi aperti non si registra niente.
     */
    public function esercizioPer(Condominio $condominio, MovimentoBancario $m): Esercizio
    {
        $data = $m->data_contabile->toDateString();

        $esercizio = $condominio->esercizi()
            ->where('stato', 'aperto')
            ->whereDate('data_inizio', '<=', $data)
            ->where(fn ($q) => $q->whereNull('data_fine')->orWhereDate('data_fine', '>=', $data))
            ->orderByDesc('data_inizio')
            ->first()
            ?? $this->getEsercizioCorrente($condominio);

        if (! $esercizio) {
            throw new AbbinamentoBancarioException('Non c\'è un esercizio aperto in cui registrare il movimento. Apri l\'esercizio dalla sezione Esercizi e riprova.');
        }

        return $esercizio;
    }

    private function causaleDi(MovimentoBancario $m, string $prefisso): string
    {
        $chi = $m->controparte ?: mb_substr((string) $m->descrizione, 0, 120);

        return trim($prefisso.' — '.$chi, ' —');
    }
}
