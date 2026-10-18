<?php

namespace App\Services\Gestionale\Banca;

use App\Models\Anagrafica;
use App\Models\Fornitore;
use App\Models\Gestionale\Banca\MovimentoBancario;
use App\Models\Gestionale\Banca\RegolaBancaria;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\RataQuote;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per un movimento della banca, che cosa potrebbe essere. Solo proposte: niente si scrive qui.
 *
 * - **Già registrato**: una scrittura a giornale con lo stesso importo sulla cassa, in date vicine.
 * - **Entrata → incasso rate**: il condòmino (memoria IBAN/nome, poi somiglianza del nome con
 *   l'anagrafica) e le sue rate aperte, coperte dalla più vecchia (come si farebbe a mano nella
 *   maschera incassi). L'eventuale resto è eccedenza, che l'incasso gestisce come sempre.
 * - **Uscita → pagamento fornitore**: le fatture pagabili ordinate per somiglianza (importo uguale
 *   al residuo, IBAN o nome del fornitore, numero della fattura nella causale).
 * - **Regole**: commissioni, bolli e simili riconosciuti dal testo.
 *
 * `migliore` dice quale proposta usare con la conferma multipla, e se è abbastanza sicura da
 * applicarla senza guardarla (`automatica`).
 */
final class ProposteAbbinamentoService
{
    public function __construct(
        private readonly ScrittureGiaRegistrate $giaRegistrate,
        private readonly MemoriaControparti $memoria,
    ) {}

    /** @return array<string, mixed> */
    public function proponi(MovimentoBancario $m): array
    {
        $m->loadMissing('cassa');

        $gia = $this->giaRegistrate->candidate($m->cassa, $m->data_contabile, $m->importo)
            ->take(3)
            ->map(fn ($s) => [
                'id' => $s->id,
                'protocollo' => $s->numero_protocollo,
                'causale' => $s->causale,
                'data' => $s->data_competenza,
                'tipo' => $s->tipo_movimento,
            ])->values()->all();

        $regole = $this->regole($m);

        $proposte = [
            'gia_registrate' => $gia,
            'incasso' => $m->importo > 0 ? $this->incasso($m) : null,
            'pagamenti' => $m->importo < 0 ? $this->pagamenti($m) : [],
            'regole' => $regole,
        ];

        $proposte['migliore'] = $this->migliore($proposte, $m);

        return $proposte;
    }

    /** @return array<string, mixed>|null */
    private function migliore(array $p, MovimentoBancario $m): ?array
    {
        if (count($p['gia_registrate']) === 1) {
            return ['tipo' => 'esistente', 'scrittura_id' => $p['gia_registrate'][0]['id'], 'automatica' => true];
        }

        if ($p['gia_registrate'] !== []) {
            return ['tipo' => 'esistente', 'scrittura_id' => $p['gia_registrate'][0]['id'], 'automatica' => false];
        }

        if ($p['regole'] !== []) {
            return ['tipo' => 'regola', 'regola_id' => $p['regole'][0]['id'], 'automatica' => true];
        }

        if ($p['incasso'] && $p['incasso']['quote'] !== []) {
            $sicuro = $p['incasso']['punteggio'] >= 90
                && $p['incasso']['eccedenza'] === 0
                && collect($p['incasso']['quote'])->every(fn ($q) => $q['emessa']);

            return ['tipo' => 'incasso', 'anagrafica_id' => $p['incasso']['anagrafica']['id'], 'automatica' => $sicuro];
        }

        if ($p['pagamenti'] !== []) {
            $primo = $p['pagamenti'][0];
            $sicuro = $primo['importo_esatto'] && $primo['punteggio'] >= 80
                && (count($p['pagamenti']) === 1 || $p['pagamenti'][1]['punteggio'] < $primo['punteggio']);

            return ['tipo' => 'pagamento', 'fattura_id' => $primo['fattura_id'], 'automatica' => $sicuro];
        }

        return null;
    }

    // ── Entrate ──────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed>|null */
    private function incasso(MovimentoBancario $m): ?array
    {
        $candidati = $this->candidatiCondomini($m);

        if ($candidati === []) {
            return null;
        }

        $primo = $candidati[0];
        [$quote, $eccedenza] = $this->allocaFifo($primo['id'], $m->condominio_id, $m->importo);

        return [
            'anagrafica' => ['id' => $primo['id'], 'nome' => $primo['nome']],
            'motivo' => $primo['motivo'],
            'punteggio' => $primo['punteggio'],
            'altre_anagrafiche' => array_slice($candidati, 1, 4),
            'quote' => $quote,
            'eccedenza' => $eccedenza,
        ];
    }

    /**
     * I condòmini che potrebbero aver fatto il bonifico, dal più probabile.
     *
     * @return list<array{id:int, nome:string, punteggio:int, motivo:string}>
     */
    public function candidatiCondomini(MovimentoBancario $m): array
    {
        $anagrafiche = $this->anagraficheDelCondominio($m->condominio_id);
        $punteggi = [];

        $ricordo = $this->memoria->riconosci($m->condominio_id, $m->iban_controparte, $m->controparte);

        if ($ricordo && $ricordo['anagrafica_id'] && $anagrafiche->has($ricordo['anagrafica_id'])) {
            $punteggi[$ricordo['anagrafica_id']] = [
                $ricordo['tramite'] === 'iban' ? 100 : 90,
                $ricordo['tramite'] === 'iban' ? 'IBAN già abbinato in passato' : 'Nome già abbinato in passato',
            ];
        }

        $testo = $m->testoCompleto();

        foreach ($anagrafiche as $id => $nome) {
            if (isset($punteggi[$id])) {
                continue;
            }

            $simile = Testo::somiglianzaNome($nome, $testo);

            if ($simile >= 50) {
                $punteggi[$id] = [min($simile, 85), "Nome simile all'anagrafica ({$simile}%)"];
            }
        }

        arsort($punteggi);

        $risultato = [];

        foreach ($punteggi as $id => [$punteggio, $motivo]) {
            $risultato[] = ['id' => (int) $id, 'nome' => $anagrafiche[$id], 'punteggio' => $punteggio, 'motivo' => $motivo];
        }

        usort($risultato, fn ($a, $b) => $b['punteggio'] <=> $a['punteggio']);

        return $risultato;
    }

    /**
     * Chi è condòmino di questo stabile: nel pivot o titolare di un'unità. Stesso criterio di
     * `StoreIncassoRateRequest::pagante_id`, così la proposta non offre mai un pagante che
     * l'incasso rifiuterebbe.
     *
     * @return Collection<int, string> id → nome
     */
    public function anagraficheDelCondominio(int $condominioId): Collection
    {
        return Anagrafica::query()
            ->whereIn('id', fn ($q) => $q->select('anagrafica_id')->from('anagrafica_condominio')->where('condominio_id', $condominioId))
            ->orWhereIn('id', fn ($q) => $q->select('anagrafica_immobile.anagrafica_id')
                ->from('anagrafica_immobile')
                ->join('immobili', 'immobili.id', '=', 'anagrafica_immobile.immobile_id')
                ->where('immobili.condominio_id', $condominioId))
            ->orderBy('nome')
            ->pluck('nome', 'id');
    }

    /**
     * Le rate aperte del condòmino, dalla scadenza più vecchia, coperte fino a esaurire l'importo.
     *
     * Una riga per **rata** (come nella maschera incassi): `quota_id` è la quota «faro» che
     * `StoreIncassoRateAction` usa per ritrovare tutte le quote del pagante su quella rata, e
     * `residuo` è la somma dei residui del pagante su quella rata. Prima le rate emesse; quelle di
     * piani non ancora emessi vengono dopo e sono segnalate.
     *
     * @return array{0: list<array<string, mixed>>, 1: int}  righe e eccedenza (centesimi)
     */
    public function allocaFifo(int $anagraficaId, int $condominioId, int $importo): array
    {
        $righe = $this->rateAperte($anagraficaId, $condominioId);
        $resto = $importo;

        foreach ($righe as &$r) {
            $r['da_pagare'] = $resto > 0 ? min($resto, $r['residuo']) : 0;
            $resto -= $r['da_pagare'];
        }
        unset($r);

        return [array_values(array_filter($righe, fn ($r) => $r['da_pagare'] > 0)), max(0, $resto)];
    }

    /** @return list<array<string, mixed>> */
    public function rateAperte(int $anagraficaId, int $condominioId): array
    {
        $quote = RataQuote::query()
            ->whereHas('rata.pianoRate', fn ($q) => $q->where('condominio_id', $condominioId))
            ->where('anagrafica_id', $anagraficaId)
            ->where('importo', '>', 0)
            ->whereColumn('importo', '>', 'importo_pagato')
            ->with(['rata.pianoRate.gestione', 'immobile'])
            ->orderBy('data_scadenza')
            ->orderBy('id')
            ->get();

        return $quote->groupBy('rata_id')
            ->map(function (Collection $gruppo) {
                $faro = $gruppo->first();
                $rata = $faro->rata;

                return [
                    'quota_id' => $faro->id,
                    'rata_id' => $faro->rata_id,
                    'descrizione' => $rata?->descrizione ?: ('Rata n.'.($rata?->numero_rata ?? '-')),
                    'gestione' => $rata?->pianoRate?->gestione?->nome,
                    'gestione_id' => $rata?->pianoRate?->gestione_id,
                    'scadenza' => $faro->data_scadenza?->toDateString(),
                    'unita' => $gruppo->map(fn ($q) => $q->immobile?->etichetta)->filter()->unique()->implode(', '),
                    'residuo' => (int) $gruppo->sum(fn ($q) => $q->importo - $q->importo_pagato),
                    'emessa' => $gruppo->contains(fn ($q) => $q->scrittura_contabile_id !== null),
                ];
            })
            ->sortBy([['emessa', 'desc'], ['scadenza', 'asc'], ['rata_id', 'asc']])
            ->values()
            ->all();
    }

    // ── Uscite ───────────────────────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function pagamenti(MovimentoBancario $m): array
    {
        $importo = abs($m->importo);
        $testo = $m->testoCompleto();
        $testoNorm = ' '.Testo::normalizza($testo).' ';
        $ricordo = $this->memoria->riconosci($m->condominio_id, $m->iban_controparte, $m->controparte);
        $iban = Testo::normalizzaIban($m->iban_controparte);

        $fatture = FatturaPassiva::where('condominio_id', $m->condominio_id)
            ->pagabili()
            ->where(fn ($q) => $q->whereNull('tipo_documento')->orWhere('tipo_documento', '!=', 'nota_credito'))
            ->with('fornitore')
            ->get();

        $proposte = [];

        foreach ($fatture as $f) {
            $residuo = $f->residuo;

            if ($residuo <= 0) {
                continue;
            }

            $punteggio = 0;
            $motivi = [];
            $esatto = $residuo === $importo;

            if ($esatto) {
                $punteggio += 50;
                $motivi[] = 'importo uguale al residuo';
            } elseif ($importo < $residuo) {
                $punteggio += 10;
                $motivi[] = 'pagamento parziale possibile';
            } else {
                // Si paga più del residuo: possibile solo con più fatture o con commissioni.
                $punteggio -= 20;
            }

            $fornitore = $f->fornitore;
            $ibanFornitore = Testo::normalizzaIban($fornitore?->iban_principale);

            if ($iban && $ibanFornitore && $iban === $ibanFornitore) {
                $punteggio += 40;
                $motivi[] = 'IBAN del fornitore';
            } elseif ($ricordo && $ricordo['fornitore_id'] === $f->fornitore_id) {
                $punteggio += 35;
                $motivi[] = $ricordo['tramite'] === 'iban' ? 'IBAN già abbinato al fornitore' : 'nome già abbinato al fornitore';
            } elseif ($fornitore && ($simile = Testo::somiglianzaNome((string) $fornitore->ragione_sociale, $testo)) >= 50) {
                $punteggio += (int) round($simile * 0.3);
                $motivi[] = "nome del fornitore ({$simile}%)";
            }

            $numero = Testo::normalizza((string) $f->numero_documento);

            if (strlen($numero) >= 2 && str_contains($testoNorm, ' '.$numero.' ')) {
                $punteggio += 20;
                $motivi[] = 'numero della fattura nella causale';
            }

            if ($punteggio < 30) {
                continue;
            }

            $proposte[] = [
                'fattura_id' => $f->id,
                'numero' => $f->numero_documento,
                'data' => $f->data_documento?->toDateString(),
                'scadenza' => $f->data_scadenza?->toDateString(),
                'fornitore' => ['id' => $f->fornitore_id, 'nome' => $fornitore?->ragione_sociale],
                'residuo' => $residuo,
                'ritenuta' => (int) ($f->importo_ritenuta ?? 0),
                'importo_esatto' => $esatto,
                'punteggio' => $punteggio,
                'motivi' => $motivi,
            ];
        }

        usort($proposte, fn ($a, $b) => [$b['punteggio'], $a['fattura_id']] <=> [$a['punteggio'], $b['fattura_id']]);

        return array_slice($proposte, 0, 5);
    }

    // ── Regole ───────────────────────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function regole(MovimentoBancario $m): array
    {
        return RegolaBancaria::where('condominio_id', $m->condominio_id)
            ->where('attiva', true)
            ->with('conto:id,nome')
            ->orderBy('id')
            ->get()
            ->filter(fn (RegolaBancaria $r) => $r->siApplicaA($m))
            ->map(fn (RegolaBancaria $r) => [
                'id' => $r->id,
                'nome' => $r->nome,
                'azione' => $r->azione,
                'conto_id' => $r->conto_id,
                'conto_nome' => $r->conto?->nome,
                'gestione_id' => $r->gestione_id,
                'causale' => $r->causale,
            ])
            ->values()
            ->all();
    }
}
