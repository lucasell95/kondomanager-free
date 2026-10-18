<?php

namespace App\Services\Gestionale\Rendiconto;

use App\Helpers\MoneyHelper;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContributoVersato;
use App\Services\CalcoloQuoteService;
use Illuminate\Support\Facades\DB;

/**
 * Riparto consuntivo e conguaglio per condòmino, gestione per gestione.
 *
 * Le spese effettive di ogni voce si ripartiscono con le tabelle e i criteri della voce, con lo
 * stesso motore del preventivo (`CalcoloQuoteService::ripartisciConsuntivo()`): stessi pesi,
 * stessa cascata del ruolo proprietario/inquilino/usufruttuario, stessi resti maggiori al
 * centesimo. Le spese ad personam vanno ai titolari di diritto reale dell'unità.
 *
 * Per ogni condòmino:
 *
 *     conguaglio = saldo iniziale + quota consuntiva − già versato (contributi) − versato
 *
 * positivo = a debito, negativo = a credito. Il saldo iniziale è quello caricato per l'esercizio
 * (`saldi`, positivo = debito); il versato è letto dal giornale: avere − dare su Crediti
 * v/condòmini e Anticipi da condòmini nelle scritture d'incasso, compensazione e storno
 * dell'esercizio, imputato alla gestione del piano rate della rata pagata. Il saldo iniziale
 * eventualmente fatto pagare con la Rata 0 sta quindi sia nel saldo iniziale sia nel versato, e
 * si elide.
 *
 * Per le gestioni straordinarie un saldo a credito è, di norma, quanto versato e non ancora
 * speso (fondo lavori, art. 1135 n. 4): si riporta sulla stessa gestione, non si restituisce.
 *
 * Sola lettura, importi in centesimi.
 */
class RipartoConsuntivoService
{
    /** Le scritture che muovono il versato dei condòmini. Le emissioni ne sono escluse. */
    private const TIPI_VERSATO = ['incasso_rata', 'storno_credito', 'rettifica', 'rimborso_condomino'];

    public function __construct(
        private readonly CalcoloQuoteService $motore,
        private readonly ConsuntivoGestioniService $consuntivo,
    ) {}

    /**
     * @param  array  $consuntivo  il risultato di `ConsuntivoGestioniService::calcola()`
     * @return list<array<string,mixed>> una voce per gestione, nello stesso ordine del consuntivo
     */
    public function calcola(Condominio $condominio, Esercizio $esercizio, array $consuntivo): array
    {
        $gestioni = $consuntivo['gestioni'];
        $idsGestioni = array_map(fn ($g) => (int) $g['id'], $gestioni);
        $ordinariaId = null;
        foreach ($gestioni as $g) {
            if ($g['tipo'] === 'ordinaria') { $ordinariaId = (int) $g['id']; break; }
        }
        $ordinariaId ??= $idsGestioni[0] ?? null;

        $versato = $this->versato($condominio, $esercizio, $idsGestioni, $ordinariaId);
        $saldi = $this->saldiIniziali($condominio, $esercizio, $idsGestioni, $ordinariaId);

        $risultato = [];

        foreach ($gestioni as $g) {
            $risultato[] = $this->perGestione(
                $condominio,
                $g,
                $versato[$g['id']] ?? [],
                $saldi[$g['id']] ?? [],
            );
        }

        return $risultato;
    }

    /**
     * @param  array<string,int>  $versato  `anagrafica|immobile => centesimi`
     * @param  array<string,int>  $saldi  `anagrafica|immobile => centesimi`
     */
    private function perGestione(Condominio $condominio, array $gestione, array $versato, array $saldi): array
    {
        $spesePerVoce = $gestione['spese_per_voce'];
        $addebiti = $gestione['addebiti_per_immobile'];
        $radici = $gestione['radice_per_voce'];

        $esito = $this->motore->ripartisciConsuntivo($spesePerVoce, $addebiti);

        // ─── Quote per (anagrafica, immobile) e per capitolo ───────────────────────────────
        $quote = [];          // chiave => centesimi
        $perCapitolo = [];    // chiave => [colonna => centesimi]
        $ripartitoPerVoce = [];
        $totaliColonne = [];
        $quotePerVoce = [];   // conto_id => [chiave => centesimi], per il già versato

        foreach ($esito['per_voce'] as $contoId => $perAnagrafica) {
            $colonna = 'c'.($radici[$contoId] ?? $contoId);
            foreach ($perAnagrafica as $aid => $perImmobile) {
                foreach ($perImmobile as $iid => $importo) {
                    $chiave = $aid.'|'.$iid;
                    $quote[$chiave] = ($quote[$chiave] ?? 0) + $importo;
                    $perCapitolo[$chiave][$colonna] = ($perCapitolo[$chiave][$colonna] ?? 0) + $importo;
                    $ripartitoPerVoce[$contoId] = ($ripartitoPerVoce[$contoId] ?? 0) + $importo;
                    $totaliColonne[$colonna] = ($totaliColonne[$colonna] ?? 0) + $importo;
                    $quotePerVoce[$contoId][$chiave] = ($quotePerVoce[$contoId][$chiave] ?? 0) + $importo;
                }
            }
        }

        $ripartitoDiretti = 0;
        foreach ($esito['diretti'] as $perAnagrafica) {
            foreach ($perAnagrafica as $aid => $perImmobile) {
                foreach ($perImmobile as $iid => $importo) {
                    $chiave = $aid.'|'.$iid;
                    $quote[$chiave] = ($quote[$chiave] ?? 0) + $importo;
                    $perCapitolo[$chiave]['diretto'] = ($perCapitolo[$chiave]['diretto'] ?? 0) + $importo;
                    $totaliColonne['diretto'] = ($totaliColonne['diretto'] ?? 0) + $importo;
                    $ripartitoDiretti += $importo;
                }
            }
        }

        // ─── Già versato (contributi dichiarati per voce) ──────────────────────────────────
        [$contributi, $contributiNonAttribuiti] = $this->contributiGiaVersati($condominio, array_keys($spesePerVoce), $quotePerVoce);

        // ─── Spese non ripartite ───────────────────────────────────────────────────────────
        $nomiVoci = Conto::whereIn('id', array_keys($spesePerVoce) ?: [0])->pluck('nome', 'id');
        $nonRipartite = [];
        foreach ($spesePerVoce as $contoId => $importo) {
            $differenza = $importo - ($ripartitoPerVoce[$contoId] ?? 0);
            if ($differenza !== 0) {
                $nonRipartite[] = ['voce' => (string) ($nomiVoci[$contoId] ?? "Voce {$contoId}"), 'importo' => $differenza];
            }
        }
        $differenzaDiretti = array_sum($addebiti) - $ripartitoDiretti;
        if ($differenzaDiretti !== 0) {
            $nonRipartite[] = ['voce' => 'Spese ad personam senza titolare', 'importo' => $differenzaDiretti];
        }

        // ─── Righe per anagrafica ──────────────────────────────────────────────────────────
        $chiavi = array_unique(array_merge(array_keys($quote), array_keys($versato), array_keys($saldi), array_keys($contributi)));

        $aids = [];
        $iids = [];
        foreach ($chiavi as $chiave) {
            [$aid, $iid] = array_map('intval', explode('|', $chiave));
            $aids[$aid] = true;
            if ($iid > 0) $iids[$iid] = true;
        }

        $anagrafiche = DB::table('anagrafiche')->whereIn('id', array_keys($aids) ?: [0])->pluck('nome', 'id');
        $immobili = DB::table('immobili')->whereIn('id', array_keys($iids) ?: [0])->get(['id', 'nome', 'interno'])->keyBy('id');
        $ruoli = DB::table('anagrafica_immobile')
            ->whereIn('anagrafica_id', array_keys($aids) ?: [0])
            ->whereIn('immobile_id', array_keys($iids) ?: [0])
            ->orderByDesc('attivo')
            ->get(['anagrafica_id', 'immobile_id', 'tipologia']);
        $ruoloDi = [];
        foreach ($ruoli as $r) {
            $ruoloDi[$r->anagrafica_id.'|'.$r->immobile_id] ??= (string) $r->tipologia;
        }

        $righe = [];
        foreach ($chiavi as $chiave) {
            [$aid, $iid] = array_map('intval', explode('|', $chiave));

            if (! isset($righe[$aid])) {
                $righe[$aid] = [
                    'anagrafica_id'  => $aid,
                    'nome'           => (string) ($anagrafiche[$aid] ?? "Anagrafica {$aid}"),
                    'unita'          => [],
                    'saldo_iniziale' => 0,
                    'quota'          => 0,
                    'per_capitolo'   => [],
                    'contributi'     => 0,
                    'versato'        => 0,
                    'conguaglio'     => 0,
                ];
            }

            $saldo = (int) ($saldi[$chiave] ?? 0);
            $q = (int) ($quote[$chiave] ?? 0);
            $c = (int) ($contributi[$chiave] ?? 0);
            $v = (int) ($versato[$chiave] ?? 0);

            $immobile = $iid > 0 ? $immobili->get($iid) : null;
            $righe[$aid]['unita'][] = [
                'immobile_id'    => $iid ?: null,
                'nome'           => $immobile ? $this->consuntivo->nomeUnita($immobile->nome, $immobile->interno) : 'senza unità',
                'ruolo'          => $ruoloDi[$chiave] ?? null,
                'saldo_iniziale' => $saldo,
                'quota'          => $q,
                'contributi'     => $c,
                'versato'        => $v,
                'conguaglio'     => $saldo + $q - $c - $v,
            ];

            $righe[$aid]['saldo_iniziale'] += $saldo;
            $righe[$aid]['quota'] += $q;
            $righe[$aid]['contributi'] += $c;
            $righe[$aid]['versato'] += $v;
            $righe[$aid]['conguaglio'] += $saldo + $q - $c - $v;
            foreach ($perCapitolo[$chiave] ?? [] as $colonna => $importo) {
                $righe[$aid]['per_capitolo'][$colonna] = ($righe[$aid]['per_capitolo'][$colonna] ?? 0) + $importo;
            }
        }

        $righe = array_values($righe);
        usort($righe, fn ($a, $b) => strcasecmp($a['nome'], $b['nome']) ?: $a['anagrafica_id'] <=> $b['anagrafica_id']);

        // ─── Colonne (capitoli) ────────────────────────────────────────────────────────────
        $nomiCapitoli = Conto::whereIn('id', array_values(array_unique(array_values($radici))) ?: [0])->pluck('nome', 'id');
        $colonne = [];
        foreach ($totaliColonne as $colonna => $totale) {
            if ($colonna === 'diretto') continue;
            $id = (int) substr($colonna, 1);
            $colonne[] = ['chiave' => $colonna, 'nome' => (string) ($nomiCapitoli[$id] ?? "Voce {$id}"), 'totale' => $totale];
        }
        usort($colonne, fn ($a, $b) => strcasecmp($a['nome'], $b['nome']));
        if (isset($totaliColonne['diretto'])) {
            $colonne[] = ['chiave' => 'diretto', 'nome' => 'Spese individuali', 'totale' => $totaliColonne['diretto']];
        }

        $totaleSpese = array_sum($spesePerVoce) + array_sum($addebiti);
        $totali = [
            'spese'          => $totaleSpese,
            'ripartito'      => array_sum($quote),
            'non_ripartito'  => $totaleSpese - array_sum($quote),
            'saldo_iniziale' => array_sum($saldi),
            'contributi'     => array_sum($contributi),
            'versato'        => array_sum($versato),
            'conguaglio'     => array_sum(array_column($righe, 'conguaglio')),
            'a_debito'       => array_sum(array_map(fn ($r) => max(0, $r['conguaglio']), $righe)),
            'a_credito'      => array_sum(array_map(fn ($r) => min(0, $r['conguaglio']), $righe)),
        ];

        return [
            'gestione_id'     => (int) $gestione['id'],
            'gestione'        => $gestione['nome'],
            'tipo'            => $gestione['tipo'],
            'colonne'         => $colonne,
            'righe'           => $righe,
            'totali'          => $totali,
            'non_ripartite'   => $nonRipartite,
            'contributi_non_attribuiti' => $contributiNonAttribuiti,
            'scoperti'        => $esito['scoperti'],
            'millesimi_non_compilati' => count($esito['millesimi_non_compilati']),
        ];
    }

    /**
     * Versato dei condòmini nell'esercizio, per gestione: `gestione_id => [anagrafica|immobile => centesimi]`.
     *
     * @return array<int, array<string,int>>
     */
    private function versato(Condominio $condominio, Esercizio $esercizio, array $idsGestioni, ?int $ordinariaId): array
    {
        $righe = DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', function ($join) {
                $join->on('sc.id', '=', 'rs.scrittura_id')->whereNull('sc.deleted_at');
            })
            ->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')
            ->leftJoin('rate as ra', 'ra.id', '=', 'rs.rata_id')
            ->leftJoin('piani_rate as pr', 'pr.id', '=', 'ra.piano_rate_id')
            ->where('sc.condominio_id', $condominio->id)
            ->where('sc.esercizio_id', $esercizio->id)
            ->whereIn('sc.tipo_movimento', self::TIPI_VERSATO)
            ->whereIn('cc.ruolo', ['crediti_condomini', 'anticipi_condomini'])
            ->whereNotNull('rs.anagrafica_id')
            ->get(['rs.anagrafica_id', 'rs.immobile_id', 'rs.tipo_riga', 'rs.importo', 'sc.gestione_id', 'pr.gestione_id as gestione_piano']);

        $perGestione = [];
        foreach ($righe as $r) {
            $g = ($r->gestione_piano !== null && in_array((int) $r->gestione_piano, $idsGestioni, true))
                ? (int) $r->gestione_piano
                : $this->consuntivo->gestioneDi($r->gestione_id, $idsGestioni, $ordinariaId);
            if ($g === null) continue;

            $chiave = (int) $r->anagrafica_id.'|'.(int) ($r->immobile_id ?? 0);
            $importo = $r->tipo_riga === 'avere' ? (int) $r->importo : -(int) $r->importo;
            $perGestione[$g][$chiave] = ($perGestione[$g][$chiave] ?? 0) + $importo;
        }

        return $perGestione;
    }

    /**
     * Saldi iniziali dei condòmini caricati per l'esercizio: `gestione_id => [anagrafica|immobile => centesimi]`.
     * Un saldo senza gestione va sull'ordinaria.
     *
     * @return array<int, array<string,int>>
     */
    private function saldiIniziali(Condominio $condominio, Esercizio $esercizio, array $idsGestioni, ?int $ordinariaId): array
    {
        $righe = DB::table('saldi')
            ->where('condominio_id', $condominio->id)
            ->where('esercizio_id', $esercizio->id)
            ->whereNotNull('anagrafica_id')
            ->get(['anagrafica_id', 'immobile_id', 'gestione_id', 'saldo_iniziale']);

        $perGestione = [];
        foreach ($righe as $r) {
            $g = $this->consuntivo->gestioneDi($r->gestione_id, $idsGestioni, $ordinariaId);
            if ($g === null) continue;

            $chiave = (int) $r->anagrafica_id.'|'.(int) ($r->immobile_id ?? 0);
            $perGestione[$g][$chiave] = ($perGestione[$g][$chiave] ?? 0) + (int) $r->saldo_iniziale;
        }

        return $perGestione;
    }

    /**
     * Contributi già versati per le voci spese nell'esercizio (`contributi_versati`), attribuiti
     * alle anagrafiche: a quella indicata nel contributo se c'è, altrimenti a chi ha una quota
     * della voce su quell'unità, in proporzione.
     *
     * @param  list<int>  $vociIds
     * @param  array<int, array<string,int>>  $quotePerVoce
     * @return array{0: array<string,int>, 1: int}
     */
    private function contributiGiaVersati(Condominio $condominio, array $vociIds, array $quotePerVoce): array
    {
        if ($vociIds === []) {
            return [[], 0];
        }

        $contributi = ContributoVersato::where('condominio_id', $condominio->id)
            ->where('target_type', Conto::class)
            ->whereIn('target_id', $vociIds)
            ->get(['target_id', 'immobile_id', 'anagrafica_id', 'importo_cents']);

        $perChiave = [];
        $nonAttribuiti = 0;

        foreach ($contributi as $c) {
            $importo = (int) $c->importo_cents;
            if ($importo === 0) continue;

            $iid = (int) ($c->immobile_id ?? 0);

            if ($c->anagrafica_id) {
                $chiave = (int) $c->anagrafica_id.'|'.$iid;
                $perChiave[$chiave] = ($perChiave[$chiave] ?? 0) + $importo;
                continue;
            }

            $pesi = [];
            foreach ($quotePerVoce[(int) $c->target_id] ?? [] as $chiave => $quota) {
                [, $iidQuota] = array_map('intval', explode('|', $chiave));
                if ($iidQuota === $iid && $quota > 0) {
                    $pesi[$chiave] = $quota;
                }
            }

            if ($pesi === []) {
                $nonAttribuiti += $importo;
                continue;
            }

            foreach (MoneyHelper::ripartisciPerQuote($importo, $pesi) as $chiave => $parte) {
                $perChiave[$chiave] = ($perChiave[$chiave] ?? 0) + $parte;
            }
        }

        return [$perChiave, $nonAttribuiti];
    }
}
