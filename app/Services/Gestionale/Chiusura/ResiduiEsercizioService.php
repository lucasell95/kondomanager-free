<?php

namespace App\Services\Gestionale\Chiusura;

use App\Models\Esercizio;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestione;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * I residui per condòmino a fine esercizio, per unità e per gestione. **Sola lettura.**
 *
 * ## Che cosa è il residuo
 *
 * È il saldo con cui l'estratto conto dell'esercizio si chiude
 * (`EstrattoContoAnagraficaController::buildLedger`): saldo iniziale dell'esercizio più i
 * movimenti dell'esercizio sui conti del condòmino (Crediti v/condòmini e Anticipi), con la
 * stessa regola sulle emissioni — conta la quota pura, perché il riporto che la quota porta
 * dentro (`saldo_usato`) sta già nel saldo iniziale. Se la chiusura scrivesse nel nuovo esercizio
 * un saldo iniziale diverso da questo numero, l'estratto conto del nuovo esercizio partirebbe
 * sbagliato. Il calcolo qui deve quindi restare allineato a quello: se cambia uno, cambia l'altro.
 *
 * Le scritture di riporto di una chiusura precedente (`tipo_movimento = chiusura`) non contano:
 * il loro effetto è già nel saldo iniziale, come per l'estratto conto.
 *
 * ## Le parti del residuo
 *
 * Per decidere che cosa si può riportare serve sapere da dove viene il residuo:
 *
 *  - `debito_quote`: le quote **emesse** ancora da pagare. Sono le uniche che la chiusura può
 *    «riportare»: una scrittura le chiude e il loro importo diventa saldo iniziale libero;
 *  - `credito_quote`: il credito che sta sulle quote (strapagate, o negative non consumate).
 *    Resta lì, dove `CreditoService` lo propone in compensazione;
 *  - `extra`: i movimenti senza rata (anticipi su 2101, conguagli registrati senza quota),
 *    per conto. Non c'è una quota su cui lasciarli, quindi si riportano quando la gestione
 *    prosegue;
 *  - `saldi_liberi`: saldi iniziali dell'esercizio mai assorbiti da un piano;
 *  - `non_emesse`: la parte che vive su quote non ancora emesse (il saldo iniziale che hanno
 *    assorbito, meno quanto è già stato incassato su di loro).
 *
 * La differenza fra il residuo e le parti è, nei dati sani, il solo credito sulle quote. Se non
 * lo è, quote e giornale divergono (codice.md §2.4) e l'anteprima lo segnala.
 */
class ResiduiEsercizioService
{
    /** Tipi di scrittura che l'estratto conto e i residui ignorano: riporti già nel saldo iniziale. */
    public const TIPI_ESCLUSI = ['chiusura'];

    /** Tipo delle scritture di riparto/conguaglio a consuntivo (se qualcuno le registra). */
    public const TIPO_CONGUAGLIO = 'riparto';

    /**
     * @return Collection<string, array{
     *     chiave: string, anagrafica_id: ?int, immobile_id: ?int, gestione_id: ?int,
     *     saldo_iniziale: int, movimenti: int, residuo: int, conguaglio: int,
     *     debito_quote: int, credito_quote: int, non_emesse: int, quote_debito: list<array{quota_id:int, rata_id:int, importo:int}>,
     *     extra: array<int,int>, extra_totale: int, saldi_liberi: list<int>, saldi_liberi_totale: int
     * }>
     */
    public function calcola(Esercizio $esercizio): Collection
    {
        $condominioId = (int) $esercizio->condominio_id;
        $righe = [];

        $conti = ContoContabile::where('condominio_id', $condominioId)
            ->whereIn('ruolo', ['crediti_condomini', 'anticipi_condomini'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $gestioneDiRiserva = $this->gestioneOrdinariaDi($esercizio);

        // ── 1. Movimenti dell'esercizio sui conti del condòmino ──────────────────────────────
        $movimenti = DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', 'sc.id', '=', 'rs.scrittura_id')
            ->leftJoin('rate as r', 'r.id', '=', 'rs.rata_id')
            ->leftJoin('piani_rate as pr', 'pr.id', '=', 'r.piano_rate_id')
            ->where('sc.condominio_id', $condominioId)
            ->where('sc.esercizio_id', $esercizio->id)
            ->whereNull('sc.deleted_at')
            ->whereNotIn('sc.tipo_movimento', self::TIPI_ESCLUSI)
            ->whereIn('rs.conto_contabile_id', $conti ?: [0])
            ->whereNull('rs.cassa_id')
            ->whereNotNull('rs.anagrafica_id')
            ->select([
                'rs.id', 'rs.tipo_riga', 'rs.importo', 'rs.anagrafica_id', 'rs.immobile_id', 'rs.rata_id',
                'rs.conto_contabile_id', 'sc.tipo_movimento', 'sc.gestione_id as gestione_scrittura',
                'pr.gestione_id as gestione_rata',
            ])
            ->get();

        // Lo snapshot delle quote, per togliere dalle emissioni il riporto che portano dentro.
        $quoteSnapshot = RataQuote::whereIn('rata_id', $movimenti->pluck('rata_id')->filter()->unique()->values())
            ->get(['id', 'rata_id', 'anagrafica_id', 'immobile_id', 'importo', 'regole_calcolo'])
            ->keyBy(fn ($q) => $q->rata_id.'_'.$q->immobile_id.'_'.$q->anagrafica_id);

        foreach ($movimenti as $m) {
            $gestioneId = $m->rata_id ? ($m->gestione_rata ?? $m->gestione_scrittura) : $m->gestione_scrittura;
            $gestioneId = $gestioneId ? (int) $gestioneId : $gestioneDiRiserva;

            $chiave = $this->chiave($m->anagrafica_id, $m->immobile_id, $gestioneId);
            $riga = &$this->riga($righe, $chiave, $m->anagrafica_id, $m->immobile_id, $gestioneId);

            $importo = (int) $m->importo;
            $contributo = $m->tipo_riga === 'dare'
                ? $this->dareComeEstrattoConto($m, $importo, $quoteSnapshot)
                : -$importo;

            $riga['movimenti'] += $contributo;

            if ($m->tipo_movimento === self::TIPO_CONGUAGLIO) {
                $riga['conguaglio'] += $contributo;
            }

            if (! $m->rata_id) {
                $conto = (int) $m->conto_contabile_id;
                $riga['extra'][$conto] = ($riga['extra'][$conto] ?? 0) + ($m->tipo_riga === 'dare' ? $importo : -$importo);
            }

            unset($riga);
        }

        // ── 2. Saldi iniziali dell'esercizio (solo condòmini, non debiti verso fornitori) ────
        $saldi = DB::table('saldi')
            ->where('condominio_id', $condominioId)
            ->where('esercizio_id', $esercizio->id)
            ->whereNull('fornitore_id')
            ->get(['id', 'anagrafica_id', 'immobile_id', 'gestione_id', 'saldo_iniziale', 'is_applicato']);

        foreach ($saldi as $s) {
            $gestioneId = $s->gestione_id ? (int) $s->gestione_id : $gestioneDiRiserva;
            $chiave = $this->chiave($s->anagrafica_id, $s->immobile_id, $gestioneId);
            $riga = &$this->riga($righe, $chiave, $s->anagrafica_id, $s->immobile_id, $gestioneId);

            $riga['saldo_iniziale'] += (int) $s->saldo_iniziale;

            if (! $s->is_applicato && (int) $s->saldo_iniziale !== 0) {
                $riga['saldi_liberi'][] = (int) $s->id;
                $riga['saldi_liberi_totale'] += (int) $s->saldo_iniziale;
            }

            unset($riga);
        }

        // ── 3. Le quote: debito ancora da pagare (emesse) e credito disponibile ─────────────
        $gestioni = collect($righe)->pluck('gestione_id')
            ->merge($esercizio->gestioni()->pluck('gestioni.id'))
            ->filter()->unique()->values()->all();

        $quote = RataQuote::query()
            ->join('rate as r', 'r.id', '=', 'rate_quote.rata_id')
            ->join('piani_rate as pr', 'pr.id', '=', 'r.piano_rate_id')
            ->where('pr.condominio_id', $condominioId)
            ->whereIn('pr.gestione_id', $gestioni ?: [0])
            ->whereNotNull('rate_quote.anagrafica_id')
            ->select('rate_quote.*', 'pr.gestione_id as gestione_piano')
            ->get();

        foreach ($quote as $q) {
            $debito = ($q->importo > 0 && $q->scrittura_contabile_id) ? max(0, $q->importo - $q->importo_pagato) : 0;
            $credito = $q->credito_disponibile;

            // Una quota positiva non ancora emessa non è un debito a giornale, ma l'estratto conto
            // conta già il saldo iniziale che porta dentro e gli eventuali incassi ricevuti in
            // anticipo: è la parte del residuo che vive su quote che verranno emesse più avanti.
            $nonEmessa = 0;
            if ($q->importo > 0 && ! $q->scrittura_contabile_id) {
                $regole = is_string($q->regole_calcolo) ? json_decode($q->regole_calcolo, true) : (array) $q->regole_calcolo;
                $nonEmessa = (int) (($regole['importi']['saldo_usato'] ?? 0)) - min((int) $q->importo_pagato, (int) $q->importo);
            }

            if ($debito === 0 && $credito === 0 && $nonEmessa === 0) {
                continue;
            }

            $gestioneId = (int) $q->gestione_piano;
            $chiave = $this->chiave($q->anagrafica_id, $q->immobile_id, $gestioneId);
            $riga = &$this->riga($righe, $chiave, $q->anagrafica_id, $q->immobile_id, $gestioneId);

            if ($debito > 0) {
                $riga['debito_quote'] += $debito;
                $riga['quote_debito'][] = ['quota_id' => (int) $q->id, 'rata_id' => (int) $q->rata_id, 'importo' => $debito];
            }

            $riga['credito_quote'] += $credito;
            $riga['non_emesse'] += $nonEmessa;

            unset($riga);
        }

        return collect($righe)
            ->map(function (array $r) {
                $r['residuo'] = $r['saldo_iniziale'] + $r['movimenti'];
                $r['extra_totale'] = array_sum($r['extra']);

                return $r;
            })
            ->filter(fn (array $r) => $r['residuo'] !== 0 || $r['debito_quote'] !== 0 || $r['credito_quote'] !== 0
                || $r['saldi_liberi_totale'] !== 0 || $r['extra_totale'] !== 0)
            ->sortBy(fn (array $r) => sprintf('%010d-%010d-%010d', $r['anagrafica_id'] ?? 0, $r['immobile_id'] ?? 0, $r['gestione_id'] ?? 0))
            ->values()
            ->keyBy('chiave');
    }

    /**
     * Le gestioni coinvolte nella chiusura: quelle dell'esercizio e quelle da cui viene un residuo
     * (una straordinaria di un esercizio precedente con rate ancora aperte, per esempio).
     *
     * @return Collection<int, array{id:int, nome:string, tipo:string, nell_esercizio:bool, quote_non_emesse:int, piani_in_bozza:int}>
     */
    public function gestioniCoinvolte(Esercizio $esercizio, Collection $residui): Collection
    {
        $nellEsercizio = $esercizio->gestioni()->pluck('gestioni.id')->map(fn ($id) => (int) $id)->all();

        $ids = collect($nellEsercizio)
            ->merge($residui->pluck('gestione_id'))
            ->filter()->unique()->values();

        return Gestione::whereIn('id', $ids)
            ->where('condominio_id', $esercizio->condominio_id)
            ->orderByRaw("CASE WHEN tipo = 'ordinaria' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->get()
            ->map(fn (Gestione $g) => [
                'id'               => (int) $g->id,
                'nome'             => $g->nome,
                'tipo'             => $g->tipo,
                'nell_esercizio'   => in_array((int) $g->id, $nellEsercizio, true),
                'quote_non_emesse' => $this->quoteNonEmesse($g),
                'piani_in_bozza'   => (int) DB::table('piani_rate')->where('gestione_id', $g->id)
                    ->where('attivo', true)->where('stato', 'bozza')->count(),
            ])
            ->values();
    }

    /** Quote di piani attivi della gestione non ancora emesse a giornale. */
    public function quoteNonEmesse(Gestione $gestione): int
    {
        return (int) DB::table('rate_quote')
            ->join('rate', 'rate.id', '=', 'rate_quote.rata_id')
            ->join('piani_rate', 'piani_rate.id', '=', 'rate.piano_rate_id')
            ->where('piani_rate.gestione_id', $gestione->id)
            ->where('piani_rate.attivo', true)
            ->whereNull('rate_quote.scrittura_contabile_id')
            ->where('rate_quote.importo', '>', 0)
            ->count();
    }

    /**
     * Il DARE di una riga come lo conta l'estratto conto: storni e prelievi da credito per
     * l'importo, le emissioni per la quota pura (senza il riporto già nel saldo iniziale).
     */
    private function dareComeEstrattoConto(object $m, int $importo, Collection $quoteSnapshot): int
    {
        if (in_array($m->tipo_movimento, ['rettifica', 'storno_credito'], true) || ! $m->rata_id) {
            return $importo;
        }

        $quota = $quoteSnapshot->get($m->rata_id.'_'.$m->immobile_id.'_'.$m->anagrafica_id);
        $regole = $quota?->regole_calcolo;

        if (is_string($regole)) {
            $regole = json_decode($regole, true);
        }

        if (empty($regole)) {
            return $importo;
        }

        $importi = ((array) $regole)['importi'] ?? [];
        $saldoUsato = (int) ($importi['saldo_usato'] ?? 0);

        return (int) ($importi['quota_pura_gestione'] ?? ($importo - $saldoUsato));
    }

    private function gestioneOrdinariaDi(Esercizio $esercizio): ?int
    {
        $id = $esercizio->gestioni()->where('gestioni.tipo', 'ordinaria')->orderBy('gestioni.id')->value('gestioni.id')
            ?? $esercizio->gestioni()->orderBy('gestioni.id')->value('gestioni.id');

        return $id ? (int) $id : null;
    }

    private function chiave($anagraficaId, $immobileId, $gestioneId): string
    {
        return sprintf('a%d|i%d|g%d', (int) $anagraficaId, (int) $immobileId, (int) $gestioneId);
    }

    private function &riga(array &$righe, string $chiave, $anagraficaId, $immobileId, $gestioneId): array
    {
        if (! isset($righe[$chiave])) {
            $righe[$chiave] = [
                'chiave'              => $chiave,
                'anagrafica_id'       => $anagraficaId ? (int) $anagraficaId : null,
                'immobile_id'         => $immobileId ? (int) $immobileId : null,
                'gestione_id'         => $gestioneId ? (int) $gestioneId : null,
                'saldo_iniziale'      => 0,
                'movimenti'           => 0,
                'residuo'             => 0,
                'conguaglio'          => 0,
                'debito_quote'        => 0,
                'credito_quote'       => 0,
                'non_emesse'          => 0,
                'quote_debito'        => [],
                'extra'               => [],
                'extra_totale'        => 0,
                'saldi_liberi'        => [],
                'saldi_liberi_totale' => 0,
            ];
        }

        return $righe[$chiave];
    }
}
