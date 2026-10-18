<?php

namespace App\Services\Gestionale\Fiscale;

use App\Actions\Gestionale\Movimenti\GeneraDelegheF24Action;
use App\Enums\Fiscale\NaturaPercipiente;
use App\Enums\Fiscale\NaturaRigaRitenuta;
use App\Enums\Fiscale\StatoDelegaF24;
use App\Enums\Fiscale\TipoRitenuta;
use App\Enums\Fiscale\TitoloRitenuta;
use App\Enums\StatoPagamentoFornitore;
use App\Enums\TipoAllocazioneFattura;
use App\Models\Condominio;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PagamentoFornitore;
use App\Models\Gestionale\RavvedimentoF24;
use App\Services\Gestionale\CalendarioFiscaleService;
use App\Services\Gestionale\PlafondRitenuteService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Il prospetto annuale del condominio come sostituto d'imposta: i dati per la CU di ciascun
 * percipiente e per il quadro ST del 770, con la quadratura fra ritenute operate e versate.
 *
 * ## Il principio di cassa
 *
 * L'anno è quello **solare dei pagamenti**, non l'esercizio condominiale e non la data delle
 * fatture: la ritenuta si opera «all'atto del pagamento» (artt. 25 e 25-ter DPR 600/1973) e la
 * CU certifica le somme corrisposte nell'anno. Una fattura di dicembre pagata a gennaio va nella
 * CU dell'anno dopo; una fattura pagata metà a dicembre e metà a gennaio si divide fra due CU.
 *
 * ## Da dove vengono i numeri (nessun dato duplicato)
 *
 * Il pagamento salva solo il lordo **con l'IVA** e la ritenuta. Il compenso al netto dell'IVA
 * (punto 4), le somme non soggette (punti 6-7) e l'imponibile (punto 8) si **ricavano** dalle
 * fatture che il pagamento copre, riga per riga secondo `natura_riga_ritenuta`, nella stessa
 * proporzione con cui `PagamentoFornitoreService::calcolaRitenutaProQuota()` ripartisce la
 * ritenuta (quota = allocato / netto a pagare):
 *
 *   - corrispettivo e rivalsa INPS 4% → compenso e imponibile;
 *   - contributo integrativo della cassa professionale → **fuori** dal compenso (non va indicato);
 *   - rimborsi di spese anticipate (art. 15 DPR 633/72) → compenso e somme non soggette, codice 22;
 *   - bollo, fornitura con posa, altre righe escluse dalla base → somme non soggette, codice 21;
 *   - quota non imponibile delle provvigioni → somme non soggette, codice 21.
 *
 * I versamenti vengono dalle deleghe F24 **versate** e dalla pivot `riga_f24_pagamento`.
 *
 * Le regole e i codici non confermati su fonte ufficiale sono marcati in `config/fiscale.php`
 * e nelle `avvertenze` del risultato.
 */
class ProspettoSostitutoService
{
    public function __construct(
        private GeneraDelegheF24Action $f24,
        private PlafondRitenuteService $plafond,
        private CalendarioFiscaleService $calendario,
        private RavvedimentoService $ravvedimento,
        private AnagraficaPercipiente $anagrafica,
    ) {}

    /**
     * Gli anni solari in cui il condominio ha pagato compensi rilevanti, più l'anno corrente.
     *
     * @return array<int,int>
     */
    public function anniDisponibili(Condominio $condominio): array
    {
        $anni = PagamentoFornitore::where('condominio_id', $condominio->id)
            ->whereNull('pagamento_padre_id')
            ->pluck('data_pagamento')
            ->map(fn ($d) => (int) CarbonImmutable::parse($d)->year)
            ->push((int) now()->year)
            ->unique()
            ->sortDesc()
            ->values()
            ->all();

        return $anni;
    }

    /**
     * Il prospetto dell'anno.
     *
     * @return array<string,mixed>
     */
    public function prospetto(Condominio $condominio, int $anno, ?CarbonInterface $oggi = null): array
    {
        $oggi = CarbonImmutable::parse(($oggi ?? now())->toDateString());

        $pagamenti = PagamentoFornitore::query()
            ->with(['fornitore', 'scrittura.fatture.righe'])
            ->where('condominio_id', $condominio->id)
            ->whereNull('pagamento_padre_id')
            ->whereHas('scrittura')
            ->whereDate('data_pagamento', '>=', "{$anno}-01-01")
            ->whereDate('data_pagamento', '<=', "{$anno}-12-31")
            ->orderBy('data_pagamento')
            ->orderBy('id')
            ->get();

        $stornati = $pagamenti->filter(fn ($p) => $this->stornato($p));
        $vivi = $pagamenti->reject(fn ($p) => $this->stornato($p));

        $conRitenuta = $vivi->filter(fn (PagamentoFornitore $p) => (int) $p->importo_ritenuta > 0);
        $forfetari = $vivi->filter(fn (PagamentoFornitore $p) => (int) $p->importo_ritenuta === 0
            && ! $p->bonifico_parlante
            && $this->forfetario($p));

        $versamenti = $this->versamentiDeiPagamenti($pagamenti->pluck('id')->all());
        $ravvedimenti = RavvedimentoF24::where('condominio_id', $condominio->id)->get()->keyBy('riga_f24_id');
        $scadenze = $this->scadenzeLegali($conRitenuta);

        $righePagamento = $conRitenuta->map(fn (PagamentoFornitore $p) => $this->rigaPagamento(
            $p, $versamenti[$p->id] ?? [], $scadenze[$p->id] ?? null, $ravvedimenti, $oggi
        ));

        $percipienti = $this->percipienti($conRitenuta, $righePagamento);
        $quadratura = $this->quadratura($righePagamento, $stornati, $versamenti, $anno);
        $st = $this->quadroSt($condominio, $anno, $ravvedimenti, $righePagamento);

        $sostituto = $this->sostituto($condominio);

        return [
            'anno' => $anno,
            'generato_il' => $oggi->toDateString(),
            'sostituto' => $sostituto,
            'percipienti' => $percipienti,
            'forfetari' => $this->riepilogoForfetari($forfetari),
            'quadratura' => $quadratura,
            'st' => $st['righe'],
            'sx' => $st['eccedenze'],
            'totali' => $this->totali($percipienti, $st['righe']),
            'scadenze' => $this->scadenzeAdempimenti($anno),
            'avvertenze' => $this->avvertenze(),
        ];
    }

    /**
     * Il quadro di un solo percipiente, per la sua CU.
     *
     * @return array<int,array<string,mixed>>  una voce per causale (di solito una sola)
     */
    public function certificazioniDi(Condominio $condominio, int $anno, int $fornitoreId): array
    {
        $prospetto = $this->prospetto($condominio, $anno);

        return array_values(array_filter(
            $prospetto['percipienti'],
            fn ($p) => (int) $p['fornitore_id'] === $fornitoreId
        ));
    }

    // ── Pagamenti ────────────────────────────────────────────────────────────

    private function stornato(PagamentoFornitore $p): bool
    {
        $stato = $p->stato instanceof \BackedEnum ? $p->stato->value : (string) $p->stato;

        return $stato === StatoPagamentoFornitore::STORNATO->value;
    }

    private function forfetario(PagamentoFornitore $p): bool
    {
        $snapshot = $p->fornitore_snapshot ?? [];

        if (array_key_exists('regime_forfetario', $snapshot)) {
            return (bool) $snapshot['regime_forfetario'];
        }

        return (bool) $p->fornitore?->regime_forfetario;
    }

    /**
     * Le fatture che il pagamento salda, con la quota pagata di ciascuna.
     *
     * Solo le allocazioni di tipo PAGAMENTO: le compensazioni con note di credito non escono
     * dalla cassa e non generano ritenuta, esattamente come nel calcolo pro quota.
     *
     * @return array<int,array{fattura:FatturaPassiva, quota:float, allocato:int}>
     */
    private function fatturePagate(PagamentoFornitore $p): array
    {
        $out = [];

        foreach ($p->scrittura?->fatture ?? [] as $fattura) {
            $tipo = $fattura->pivot->tipo instanceof \BackedEnum ? $fattura->pivot->tipo->value : (string) $fattura->pivot->tipo;

            if ($tipo !== TipoAllocazioneFattura::PAGAMENTO->value) {
                continue;
            }

            $allocato = (int) $fattura->pivot->importo_allocato;
            $netto = (int) $fattura->netto_a_pagare;

            if ($netto <= 0 || $allocato <= 0) {
                continue;
            }

            $out[] = ['fattura' => $fattura, 'quota' => min($allocato / $netto, 1), 'allocato' => $allocato];
        }

        return $out;
    }

    /**
     * Compenso, somme non soggette e imponibile di una fattura intera, prima della quota.
     *
     * @return array{compenso:int, non_soggette:array<string,int>, imponibile:int, aliquota:?float}
     */
    private function scomponiFattura(FatturaPassiva $fattura, TipoRitenuta $tipo): array
    {
        $codici = config('fiscale.certificazione_unica.codici_somme_non_soggette', []);
        $compenso = 0;
        $base = 0;
        $nonSoggette = [];

        $righe = $fattura->righe ?? collect();

        if ($righe->isEmpty()) {
            // Fattura pregressa, senza righe di dettaglio: il compenso è l'imponibile IVA.
            $compenso = (int) $fattura->importo_imponibile;
            $base = $compenso;
        }

        foreach ($righe as $riga) {
            $importo = (int) $riga->importo_imponibile;
            $natura = $riga->natura_riga_ritenuta;
            $natura = $natura instanceof NaturaRigaRitenuta ? $natura : ($natura ? NaturaRigaRitenuta::tryFrom($natura) : null);

            // Il contributo integrativo alle casse non fa parte del compenso e non va indicato.
            if ($natura === NaturaRigaRitenuta::CASSA_PROFESSIONALE) {
                continue;
            }

            $compenso += $importo;

            if ($riga->concorre_base_ritenuta ?? true) {
                $base += $importo;

                continue;
            }

            $codice = $codici[$natura?->value ?? 'altro'] ?? ($codici['altro'] ?? '21');
            $nonSoggette[$codice] = ($nonSoggette[$codice] ?? 0) + $importo;
        }

        // La base effettiva della ritenuta: quella salvata alla registrazione, se c'è (tiene
        // conto della percentuale di base delle provvigioni e delle anagrafiche legacy).
        $dettagli = $fattura->dati_extra['fiscal']['ritenuta_details'] ?? null;
        $imponibile = isset($dettagli['imponibile_calcolo'])
            ? (int) $dettagli['imponibile_calcolo']
            : (int) round($base * $tipo->percentualeBase() / 100);

        $imponibile = min($imponibile, $base);

        if ($base - $imponibile > 0) {
            $codice = $codici['provvigioni_quota_esclusa'] ?? '21';
            $nonSoggette[$codice] = ($nonSoggette[$codice] ?? 0) + ($base - $imponibile);
        }

        return [
            'compenso' => $compenso,
            'non_soggette' => $nonSoggette,
            'imponibile' => $imponibile,
            'aliquota' => isset($dettagli['aliquota']) ? (float) $dettagli['aliquota'] : null,
        ];
    }

    /**
     * La riga del prospetto per un pagamento con ritenuta.
     *
     * @param  array<int,array<string,mixed>>  $versamenti
     * @return array<string,mixed>
     */
    private function rigaPagamento(
        PagamentoFornitore $p,
        array $versamenti,
        ?CarbonImmutable $scadenza,
        Collection $ravvedimenti,
        CarbonImmutable $oggi,
    ): array {
        $tipo = $this->f24->tipoRitenuta($p);
        $naturaDichiarata = $this->f24->naturaDichiarata($p);
        $codiceTributo = $tipo->codiceTributo($naturaDichiarata ?? NaturaPercipiente::PERSONA_FISICA_IRPEF);
        $data = CarbonImmutable::parse($p->data_pagamento->toDateString());

        $compenso = 0;
        $nonSoggette = [];
        $imponibileAtteso = 0.0;
        $ritenutaAttesa = 0.0;
        $fatture = [];
        $anomalie = [];

        foreach ($this->fatturePagate($p) as ['fattura' => $fattura, 'quota' => $quota]) {
            // Una fattura senza ritenuta pagata insieme ad altre (es. la vendita di un bene
            // dallo stesso fornitore) non entra nella CU.
            if ((int) $fattura->importo_ritenuta <= 0) {
                continue;
            }

            $parti = $this->scomponiFattura($fattura, $tipo);

            $compensoQ = (int) round($parti['compenso'] * $quota);
            $nonSoggQ = array_map(fn ($v) => (int) round($v * $quota), $parti['non_soggette']);

            $compenso += $compensoQ;
            foreach ($nonSoggQ as $codice => $importo) {
                $nonSoggette[$codice] = ($nonSoggette[$codice] ?? 0) + $importo;
            }

            $aliquota = $parti['aliquota'] ?? $tipo->aliquota($data);
            $imponibileAtteso += $parti['imponibile'] * $quota;
            $ritenutaAttesa += $parti['imponibile'] * $quota * $aliquota / 100;

            $fatture[] = [
                'id' => $fattura->id,
                'numero' => $fattura->numero_documento,
                'data' => $fattura->data_documento?->toDateString(),
                'quota' => round($quota, 4),
            ];
        }

        ksort($nonSoggette);
        $totaleNonSoggette = array_sum($nonSoggette);
        $imponibile = $compenso - $totaleNonSoggette;
        $ritenuta = (int) $p->importo_ritenuta;

        if ($fatture === []) {
            $anomalie[] = 'Il pagamento ha una ritenuta ma nessuna fattura con ritenuta collegata: compenso e imponibile non sono ricavabili.';
        } elseif (abs($ritenuta - $ritenutaAttesa) > 1 + count($fatture)) {
            $anomalie[] = sprintf(
                'La ritenuta del pagamento (€ %s) non corrisponde all\'imponibile ricavato dalle fatture (atteso circa € %s): verificare la fattura.',
                $this->euro($ritenuta),
                $this->euro((int) round($ritenutaAttesa))
            );
        }

        if ($naturaDichiarata === null && $tipo === TipoRitenuta::APPALTO_4) {
            $anomalie[] = 'Natura del percipiente non indicata: il codice tributo 1019 è assunto (1020 per le società di capitali).';
        }

        // ── Versamenti ──
        $versati = array_values(array_filter($versamenti, fn ($v) => $v['stato'] === StatoDelegaF24::VERSATA->value));
        $versato = (int) array_sum(array_column($versati, 'importo'));
        $dataVersamento = $versati !== [] ? max(array_column($versati, 'data_versamento')) : null;

        foreach ($versati as $v) {
            if ($v['codice_tributo'] !== $codiceTributo) {
                $anomalie[] = "Versata con codice tributo {$v['codice_tributo']}, ma il codice che risulta oggi è {$codiceTributo}: verificare (eventuale istanza di correzione F24).";
            }
            if ($v['periodo'] !== $data->format('m/Y')) {
                $anomalie[] = "Versata con periodo di riferimento {$v['periodo']} invece di ".$data->format('m/Y').'.';
            }
        }

        $ravvedimento = null;
        foreach ($versati as $v) {
            if ($ravvedimenti->has($v['riga_id'])) {
                $r = $ravvedimenti[$v['riga_id']];
                $ravvedimento = [
                    'id' => $r->id,
                    'interessi' => (int) $r->interessi,
                    'sanzione' => (int) $r->sanzione,
                    'codice_tributo_sanzione' => $r->codice_tributo_sanzione,
                    'data_versamento' => $r->data_versamento?->toDateString(),
                ];
            }
        }

        [$stato, $giorniRitardo, $stima] = $this->statoVersamento($ritenuta, $versato, $scadenza, $dataVersamento, $oggi);

        return [
            'id' => $p->id,
            'uuid' => $p->uuid,
            'fornitore_id' => $p->fornitore_id,
            'data_pagamento' => $data->toDateString(),
            'mese' => $data->format('Y-m'),
            'tipo_ritenuta' => $tipo->value,
            'causale' => $tipo->causaleCU(),
            'titolo' => $tipo->titolo()->value,
            'codice_tributo' => $codiceTributo,
            'codice_tributo_assunto' => $naturaDichiarata === null && $tipo === TipoRitenuta::APPALTO_4,
            'fatture' => $fatture,
            'lordo_con_iva' => (int) $p->importo_lordo,
            'compenso' => $compenso,
            'non_soggette' => $nonSoggette,
            'non_soggette_totale' => $totaleNonSoggette,
            'imponibile' => $imponibile,
            'ritenuta' => $ritenuta,
            'scadenza_legale' => $scadenza?->toDateString(),
            'versamenti' => $versamenti,
            'versato' => $versato,
            'data_versamento' => $dataVersamento,
            'stato_versamento' => $stato,
            'giorni_ritardo' => $giorniRitardo,
            'ravvedimento' => $ravvedimento,
            'stima_ravvedimento' => $stima,
            'anomalie' => $anomalie,
        ];
    }

    /**
     * Regolare, tardivo, omesso, da versare o in eccesso; con giorni di ritardo e stima.
     *
     * @return array{0:string,1:int,2:?array}
     */
    private function statoVersamento(int $ritenuta, int $versato, ?CarbonImmutable $scadenza, ?string $dataVersamento, CarbonImmutable $oggi): array
    {
        if ($versato > $ritenuta) {
            return ['eccedenza', 0, null];
        }

        if ($versato === $ritenuta && $versato > 0) {
            if ($scadenza && $dataVersamento && CarbonImmutable::parse($dataVersamento)->greaterThan($scadenza)) {
                $stima = $this->ravvedimento->stima($ritenuta, $scadenza, CarbonImmutable::parse($dataVersamento));

                return ['tardivo', $stima['giorni'], $stima];
            }

            return ['regolare', 0, null];
        }

        // Non versata, o versata solo in parte.
        if ($scadenza && $oggi->greaterThan($scadenza)) {
            $stima = $this->ravvedimento->stima($ritenuta - $versato, $scadenza, $oggi);

            return ['omesso', $stima['giorni'], $stima];
        }

        return ['da_versare', 0, null];
    }

    /**
     * Per ogni pagamento, le righe F24 che ne versano la ritenuta.
     *
     * @param  array<int,int>  $ids
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function versamentiDeiPagamenti(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('riga_f24_pagamento')
            ->join('righe_f24', 'righe_f24.id', '=', 'riga_f24_pagamento.riga_f24_id')
            ->join('deleghe_f24', 'deleghe_f24.id', '=', 'righe_f24.delega_f24_id')
            ->whereIn('riga_f24_pagamento.pagamento_fornitore_id', $ids)
            ->whereNotIn('deleghe_f24.stato', [StatoDelegaF24::ANNULLATA->value])
            ->orderBy('deleghe_f24.id')
            ->get([
                'riga_f24_pagamento.pagamento_fornitore_id as pagamento_id',
                'riga_f24_pagamento.importo',
                'righe_f24.id as riga_id',
                'righe_f24.codice_tributo',
                'righe_f24.rateazione_mese_rif',
                'righe_f24.anno_riferimento',
                'deleghe_f24.id as delega_id',
                'deleghe_f24.protocollo',
                'deleghe_f24.stato',
                'deleghe_f24.data_scadenza',
                'deleghe_f24.data_versamento',
            ])
            ->groupBy('pagamento_id')
            ->map(fn ($righe) => $righe->map(fn ($r) => [
                'riga_id' => (int) $r->riga_id,
                'delega_id' => (int) $r->delega_id,
                'protocollo' => $r->protocollo,
                'stato' => $r->stato,
                'codice_tributo' => $r->codice_tributo,
                'periodo' => substr($r->rateazione_mese_rif, 2, 2).'/'.$r->anno_riferimento,
                'importo' => (int) $r->importo,
                'data_scadenza_delega' => $r->data_scadenza ? substr((string) $r->data_scadenza, 0, 10) : null,
                'data_versamento' => $r->data_versamento ? substr((string) $r->data_versamento, 0, 10) : null,
            ])->values()->all())
            ->all();
    }

    /**
     * La scadenza di legge del versamento di ciascuna ritenuta, con soglie e finestre.
     *
     * Si ricalcola da capo con `PlafondRitenuteService`, sulle sole ritenute operate dell'anno:
     * le finestre del 16/6, 16/12 e 16/1 non attraversano l'anno, quindi il risultato è lo
     * stesso che si avrebbe partendo da tutte. Non si usa la `data_scadenza` della delega: se la
     * delega fosse stata calcolata male, il controllo ne erediterebbe l'errore.
     *
     * @return array<int,CarbonImmutable>
     */
    private function scadenzeLegali(Collection $pagamenti): array
    {
        $input = $pagamenti->map(fn (PagamentoFornitore $p) => [
            'data_pagamento' => $p->data_pagamento,
            'importo' => (int) $p->importo_ritenuta,
            'tipo_ritenuta' => $this->f24->tipoRitenuta($p),
            'pagamento_id' => $p->id,
        ])->values()->all();

        $mappa = [];

        foreach ($this->plafond->raggruppaPerScadenza($input) as $gruppo) {
            foreach ($gruppo['ritenute'] as $r) {
                $mappa[$r['pagamento_id']] = $gruppo['scadenza'];
            }
        }

        return $mappa;
    }

    // ── Percipienti ──────────────────────────────────────────────────────────

    /**
     * Raggruppa per fornitore e causale: una CU per percipiente, una certificazione per causale.
     *
     * @return array<int,array<string,mixed>>
     */
    private function percipienti(Collection $pagamenti, Collection $righe): array
    {
        $perChiave = [];
        $modelli = $pagamenti->keyBy('id');

        foreach ($righe as $riga) {
            $chiave = $riga['fornitore_id'].'-'.($riga['causale'] ?? 'X');
            $perChiave[$chiave][] = $riga;
        }

        $out = [];

        foreach ($perChiave as $chiave => $righeP) {
            $primo = $modelli[$righeP[0]['id']];
            $anagrafica = $this->anagrafica->quadro($primo->fornitore, $primo->fornitore_snapshot ?? []);
            $tipo = TipoRitenuta::from($righeP[0]['tipo_ritenuta']);

            $nonSoggette = [];
            foreach ($righeP as $r) {
                foreach ($r['non_soggette'] as $codice => $importo) {
                    $nonSoggette[$codice] = ($nonSoggette[$codice] ?? 0) + $importo;
                }
            }
            ksort($nonSoggette);

            $compenso = array_sum(array_column($righeP, 'compenso'));
            $totNonSogg = array_sum($nonSoggette);
            $ritenute = array_sum(array_column($righeP, 'ritenuta'));
            $versate = array_sum(array_map(fn ($r) => min($r['versato'], $r['ritenuta']), $righeP));
            $imposta = $tipo->titolo() === TitoloRitenuta::IMPOSTA;

            $anomalie = $anagrafica['anomalie'];
            if ($righeP[0]['causale'] === null) {
                $anomalie[] = "Regime «{$tipo->label()}» senza causale CU gestita dal programma: compilare a mano.";
            }
            if (count($nonSoggette) > 1) {
                $anomalie[] = 'Somme non soggette con più codici ('.implode(', ', array_keys($nonSoggette)).'): il punto 6 ne accoglie uno; verificare sulle istruzioni come ripartirle (di norma più certificazioni).';
            }
            foreach ($righeP as $r) {
                foreach ($r['anomalie'] as $a) {
                    $anomalie[] = 'Pagamento del '.CarbonImmutable::parse($r['data_pagamento'])->format('d/m/Y').': '.$a;
                }
            }

            $out[] = [
                'chiave' => $chiave,
                'fornitore_id' => (int) $righeP[0]['fornitore_id'],
                'causale' => $righeP[0]['causale'],
                'causale_descrizione' => config('fiscale.certificazione_unica.causali.'.($righeP[0]['causale'] ?? '-')),
                'tipo_ritenuta' => $tipo->value,
                'tipo_ritenuta_label' => $tipo->label(),
                'titolo' => $tipo->titolo()->value,
                'anagrafica' => $anagrafica,
                'punti' => [
                    '1' => $righeP[0]['causale'],
                    '4' => $compenso,
                    '5' => 0,
                    '6' => array_key_first($nonSoggette),
                    '7' => $totNonSogg,
                    '8' => $compenso - $totNonSogg,
                    '9' => $imposta ? 0 : $ritenute,
                    '10' => $imposta ? $ritenute : 0,
                ],
                'non_soggette_per_codice' => $nonSoggette,
                'ritenute_operate' => $ritenute,
                'ritenute_versate' => $versate,
                'differenza' => $ritenute - $versate,
                'pagamenti' => $righeP,
                'anomalie' => array_values(array_unique($anomalie)),
            ];
        }

        usort($out, fn ($a, $b) => strcmp((string) $a['anagrafica']['denominazione'], (string) $b['anagrafica']['denominazione']));

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function riepilogoForfetari(Collection $pagamenti): array
    {
        return $pagamenti->groupBy('fornitore_id')->map(function (Collection $gruppo) {
            $primo = $gruppo->first();
            $compenso = 0;

            foreach ($gruppo as $p) {
                foreach ($this->fatturePagate($p) as ['fattura' => $f, 'quota' => $q]) {
                    $imponibile = $f->righe->isEmpty()
                        ? (int) $f->importo_imponibile
                        : (int) $f->righe
                            ->reject(fn ($r) => $r->natura_riga_ritenuta === NaturaRigaRitenuta::CASSA_PROFESSIONALE)
                            ->sum('importo_imponibile');
                    $compenso += (int) round($imponibile * $q);
                }
            }

            return [
                'fornitore_id' => (int) $primo->fornitore_id,
                'denominazione' => $primo->fornitore?->ragione_sociale ?? ($primo->fornitore_snapshot['ragione_sociale'] ?? null),
                'codice_fiscale' => $primo->fornitore?->codice_fiscale ?? ($primo->fornitore_snapshot['codice_fiscale'] ?? null),
                'pagamenti' => $gruppo->count(),
                'compenso' => $compenso,
                'pagato' => (int) $gruppo->sum('importo_netto'),
            ];
        })->values()->all();
    }

    // ── Quadratura ───────────────────────────────────────────────────────────

    /**
     * Ritenute operate contro versate, per mese e codice tributo, con le differenze e i
     * ravvedimenti necessari.
     *
     * @return array<string,mixed>
     */
    private function quadratura(Collection $righe, Collection $stornati, array $versamenti, int $anno): array
    {
        $celle = [];

        foreach ($righe as $r) {
            $k = $r['mese'].'|'.$r['codice_tributo'];
            $celle[$k] ??= ['mese' => $r['mese'], 'codice_tributo' => $r['codice_tributo'], 'operate' => 0, 'versate' => 0];
            $celle[$k]['operate'] += $r['ritenuta'];
            $celle[$k]['versate'] += $r['versato'];
        }

        // Ritenute versate su pagamenti poi stornati: sono versamenti senza ritenuta operata.
        $eccedenze = [];
        foreach ($stornati as $p) {
            foreach ($versamenti[$p->id] ?? [] as $v) {
                if ($v['stato'] !== StatoDelegaF24::VERSATA->value) {
                    continue;
                }
                $mese = CarbonImmutable::parse($p->data_pagamento->toDateString())->format('Y-m');
                $k = $mese.'|'.$v['codice_tributo'];
                $celle[$k] ??= ['mese' => $mese, 'codice_tributo' => $v['codice_tributo'], 'operate' => 0, 'versate' => 0];
                $celle[$k]['versate'] += $v['importo'];
                $eccedenze[] = [
                    'pagamento_id' => $p->id,
                    'fornitore' => $p->fornitore?->ragione_sociale,
                    'data_pagamento' => $p->data_pagamento->toDateString(),
                    'codice_tributo' => $v['codice_tributo'],
                    'importo' => $v['importo'],
                    'motivo' => 'Ritenuta versata su un pagamento poi stornato.',
                ];
            }
        }

        ksort($celle);
        $celle = array_values(array_map(function ($c) {
            $c['periodo'] = CarbonImmutable::parse($c['mese'].'-01')->format('m/Y');
            $c['differenza'] = $c['operate'] - $c['versate'];

            return $c;
        }, $celle));

        $differenze = array_values(array_filter($celle, fn ($c) => $c['differenza'] !== 0));

        $ravvedimenti = $righe
            ->filter(fn ($r) => in_array($r['stato_versamento'], ['tardivo', 'omesso'], true))
            ->map(fn ($r) => [
                'pagamento_id' => $r['id'],
                'fornitore_id' => $r['fornitore_id'],
                'data_pagamento' => $r['data_pagamento'],
                'codice_tributo' => $r['codice_tributo'],
                'periodo' => CarbonImmutable::parse($r['data_pagamento'])->format('m/Y'),
                'ritenuta' => $r['ritenuta'],
                'da_versare' => $r['ritenuta'] - min($r['versato'], $r['ritenuta']),
                'scadenza_legale' => $r['scadenza_legale'],
                'data_versamento' => $r['data_versamento'],
                'stato' => $r['stato_versamento'],
                'giorni_ritardo' => $r['giorni_ritardo'],
                'stima' => $r['stima_ravvedimento'],
                'ravvedimento_registrato' => $r['ravvedimento'],
                'riga_f24_id' => collect($r['versamenti'])->firstWhere('stato', StatoDelegaF24::VERSATA->value)['riga_id'] ?? null,
            ])
            ->values()
            ->all();

        $daVersare = $righe
            ->filter(fn ($r) => $r['stato_versamento'] === 'da_versare')
            ->map(fn ($r) => [
                'pagamento_id' => $r['id'],
                'data_pagamento' => $r['data_pagamento'],
                'codice_tributo' => $r['codice_tributo'],
                'importo' => $r['ritenuta'] - $r['versato'],
                'scadenza_legale' => $r['scadenza_legale'],
            ])->values()->all();

        return [
            'righe' => $celle,
            'differenze' => $differenze,
            'ravvedimenti' => $ravvedimenti,
            'da_versare' => $daVersare,
            'eccedenze' => $eccedenze,
            'in_quadratura' => $differenze === [] && $ravvedimenti === [],
        ];
    }

    // ── 770, quadro ST ───────────────────────────────────────────────────────

    /**
     * Le righe del quadro ST, sezione I (erario), dai versamenti F24 dell'anno di riferimento.
     *
     * Una riga per versamento: periodo di riferimento (mese e anno in cui la ritenuta è stata
     * operata), ritenute operate, interessi, casella del ravvedimento, importo versato, codice
     * tributo e data. I versamenti con dati identici si espongono in modo unitario, quindi le
     * righe F24 con stesso periodo, codice, data e ravvedimento si sommano.
     *
     * [DA VERIFICARE] la numerazione delle colonne del quadro ST sulle istruzioni 770 dell'anno:
     * qui le colonne si chiamano per contenuto, non per numero.
     *
     * @return array{righe:array<int,array<string,mixed>>, eccedenze:array<int,array<string,mixed>>}
     */
    private function quadroSt(Condominio $condominio, int $anno, Collection $ravvedimenti, Collection $righePagamento): array
    {
        $tardivi = $righePagamento
            ->filter(fn ($r) => $r['stato_versamento'] === 'tardivo')
            ->flatMap(fn ($r) => collect($r['versamenti'])->pluck('riga_id'))
            ->unique()
            ->all();

        $righeF24 = DB::table('righe_f24')
            ->join('deleghe_f24', 'deleghe_f24.id', '=', 'righe_f24.delega_f24_id')
            ->where('deleghe_f24.condominio_id', $condominio->id)
            ->where('deleghe_f24.stato', StatoDelegaF24::VERSATA->value)
            ->where('righe_f24.anno_riferimento', (string) $anno)
            ->orderBy('righe_f24.rateazione_mese_rif')
            ->orderBy('righe_f24.codice_tributo')
            ->get([
                'righe_f24.id', 'righe_f24.codice_tributo', 'righe_f24.rateazione_mese_rif',
                'righe_f24.anno_riferimento', 'righe_f24.importo_debito',
                'deleghe_f24.id as delega_id', 'deleghe_f24.protocollo', 'deleghe_f24.data_versamento',
            ]);

        // Ritenute davvero operate coperte da ciascuna riga: esclusi i pagamenti stornati.
        $operatePerRiga = DB::table('riga_f24_pagamento')
            ->join('pagamenti_fornitori', 'pagamenti_fornitori.id', '=', 'riga_f24_pagamento.pagamento_fornitore_id')
            ->whereIn('riga_f24_pagamento.riga_f24_id', $righeF24->pluck('id'))
            ->where('pagamenti_fornitori.stato', '!=', StatoPagamentoFornitore::STORNATO->value)
            ->groupBy('riga_f24_pagamento.riga_f24_id')
            ->select('riga_f24_pagamento.riga_f24_id', DB::raw('SUM(riga_f24_pagamento.importo) as totale'))
            ->pluck('totale', 'riga_f24_id');

        $aggregate = [];
        $eccedenze = [];

        foreach ($righeF24 as $riga) {
            $rigaId = (int) $riga->id;
            $ravv = $ravvedimenti->get($rigaId);
            $interessi = (int) ($ravv?->interessi ?? 0);
            $ravvedimento = $ravv !== null || in_array($rigaId, $tardivi, true);
            $operate = (int) ($operatePerRiga[$rigaId] ?? $operatePerRiga[(string) $rigaId] ?? 0);
            $versato = (int) $riga->importo_debito + $interessi;
            $data = substr((string) $riga->data_versamento, 0, 10);
            $periodo = substr($riga->rateazione_mese_rif, 2, 2).'/'.$riga->anno_riferimento;

            if ((int) $riga->importo_debito > $operate) {
                $eccedenze[] = [
                    'periodo' => $periodo,
                    'codice_tributo' => $riga->codice_tributo,
                    'versato' => (int) $riga->importo_debito,
                    'operate' => $operate,
                    'eccedenza' => (int) $riga->importo_debito - $operate,
                    'data_versamento' => $data,
                    'delega_id' => (int) $riga->delega_id,
                ];
            }

            $k = implode('|', [$riga->anno_riferimento.$riga->rateazione_mese_rif, $riga->codice_tributo, $data, (int) $ravvedimento]);
            $aggregate[$k] ??= [
                'periodo' => $periodo,
                'codice_tributo' => $riga->codice_tributo,
                'ritenute_operate' => 0,
                'interessi' => 0,
                'ravvedimento' => $ravvedimento,
                'importo_versato' => 0,
                'data_versamento' => $data,
                'deleghe' => [],
                'righe_f24' => [],
            ];
            $aggregate[$k]['ritenute_operate'] += $operate;
            $aggregate[$k]['interessi'] += $interessi;
            $aggregate[$k]['importo_versato'] += $versato;
            $aggregate[$k]['deleghe'][] = $riga->protocollo ?: '#'.$riga->delega_id;
            $aggregate[$k]['righe_f24'][] = $rigaId;
        }

        ksort($aggregate);

        return [
            'righe' => array_values(array_map(function ($r) {
                $r['deleghe'] = array_values(array_unique($r['deleghe']));

                return $r;
            }, $aggregate)),
            'eccedenze' => $eccedenze,
        ];
    }

    // ── Sostituto, totali, scadenze ──────────────────────────────────────────

    /** @return array<string,mixed> */
    public function sostituto(Condominio $condominio): array
    {
        $attr = $condominio->getAttributes();
        $data = $attr['firmatario_data_carica'] ?? null;

        $quadro = [
            'codice_fiscale' => $condominio->codice_fiscale,
            'denominazione' => $condominio->nome,
            'indirizzo' => $condominio->indirizzo,
            'cap' => $condominio->cap,
            'comune' => $condominio->comune,
            'provincia' => $condominio->provincia,
            'email' => $condominio->email,
            'telefono' => $attr['sostituto_telefono'] ?? null,
            'firmatario' => [
                'codice_fiscale' => $attr['firmatario_codice_fiscale'] ?? null,
                'cognome' => $attr['firmatario_cognome'] ?? null,
                'nome' => $attr['firmatario_nome'] ?? null,
                'codice_carica' => $attr['firmatario_codice_carica'] ?? null,
                'descrizione_carica' => config('fiscale.codici_carica.'.($attr['firmatario_codice_carica'] ?? '-')),
                'data_carica' => $data ? substr((string) $data, 0, 10) : null,
            ],
            'anomalie' => [],
        ];

        $anomalie = [];
        $cf = (string) $condominio->codice_fiscale;
        if ($cf === '') {
            $anomalie[] = 'Manca il codice fiscale del condominio (sostituto d\'imposta).';
        } elseif (! preg_match('/^[0-9]{11}$/', $cf)) {
            $anomalie[] = "Il codice fiscale del condominio «{$cf}» non è di 11 cifre: verificare.";
        }
        if (blank($condominio->comune) || blank($condominio->provincia)) {
            $anomalie[] = 'Mancano comune e provincia del condominio (domicilio fiscale del sostituto).';
        }
        $f = $quadro['firmatario'];
        if (blank($f['codice_fiscale']) || blank($f['cognome']) || blank($f['nome'])) {
            $anomalie[] = 'Mancano i dati del firmatario (cognome, nome, codice fiscale).';
        } elseif (! AnagraficaPercipiente::cfPersonaFisica($f['codice_fiscale'])) {
            $anomalie[] = 'Il codice fiscale del firmatario non ha la forma di una persona fisica.';
        }
        if (blank($f['codice_carica'])) {
            $anomalie[] = 'Manca il codice carica del firmatario (13 = amministratore di condominio, da verificare sulle istruzioni).';
        }

        $quadro['anomalie'] = $anomalie;

        return $quadro;
    }

    /** @return array<string,int> */
    private function totali(array $percipienti, array $st): array
    {
        return [
            'percipienti' => count($percipienti),
            'compenso' => array_sum(array_map(fn ($p) => $p['punti']['4'], $percipienti)),
            'non_soggette' => array_sum(array_map(fn ($p) => $p['punti']['7'], $percipienti)),
            'imponibile' => array_sum(array_map(fn ($p) => $p['punti']['8'], $percipienti)),
            'ritenute_operate' => array_sum(array_column($percipienti, 'ritenute_operate')),
            'ritenute_versate' => array_sum(array_column($percipienti, 'ritenute_versate')),
            'st_versato' => array_sum(array_column($st, 'importo_versato')),
            'st_interessi' => array_sum(array_column($st, 'interessi')),
            'anomalie' => array_sum(array_map(fn ($p) => count($p['anomalie']), $percipienti)),
        ];
    }

    /**
     * Le scadenze degli adempimenti dell'anno successivo, slittate al primo giorno lavorativo.
     *
     * [DA VERIFICARE] sul provvedimento CU e sul modello 770 dell'anno: la CU per le ritenute del
     * 4% è prudentemente al 16/03 (le fonti divergono sul 30/04 per le ditte individuali e sul
     * 31/10 per le società); quella dei professionisti è ammessa al 30/04 (D.Lgs. 81/2025).
     *
     * @return array<int,array{adempimento:string, data:string, nota:string}>
     */
    public function scadenzeAdempimenti(int $anno): array
    {
        $succ = $anno + 1;
        $d = fn (int $m, int $g) => $this->calendario->primoGiornoLavorativo(CarbonImmutable::create($succ, $m, $g))->toDateString();

        return [
            ['adempimento' => 'Consegna della CU ai percipienti', 'data' => $d(3, 16), 'nota' => 'art. 4 c. 6-quater DPR 322/1998'],
            ['adempimento' => 'Trasmissione CU all\'Agenzia (termine prudente per tutte)', 'data' => $d(3, 16), 'nota' => 'professionisti ammessi al 30/04; da verificare'],
            ['adempimento' => 'Trasmissione CU dei soli professionisti (causale A)', 'data' => $d(4, 30), 'nota' => 'art. 4 D.Lgs. 81/2025; da verificare'],
            ['adempimento' => 'Presentazione del modello 770', 'data' => $d(10, 31), 'nota' => 'art. 4 c. 4-bis DPR 322/1998; da verificare sul modello dell\'anno'],
        ];
    }

    /** @return array<int,string> */
    private function avvertenze(): array
    {
        return [
            'Prospetto prodotto dal programma sui dati registrati: non sostituisce il controllo del sostituto né le istruzioni ufficiali dell\'anno.',
            'Codici del punto 6 (21, 22) ricavati da fonti secondarie: da verificare sulle istruzioni CU dell\'anno.',
            'Compensi ai forfetari e ai minimi: dal periodo d\'imposta 2024 esonerati dalla CU (art. 4 c. 6-septies DPR 322/1998, D.Lgs. 1/2024) salvo casi residui: da verificare.',
            'Numerazione delle colonne del quadro ST e uso del quadro SX: da verificare sulle istruzioni 770 dell\'anno.',
            'Stima di sanzioni e interessi da ravvedimento indicativa: le percentuali vanno verificate prima di versare.',
        ];
    }

    private function euro(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.');
    }
}
