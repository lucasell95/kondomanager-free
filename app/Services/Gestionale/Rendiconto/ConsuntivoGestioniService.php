<?php

namespace App\Services\Gestionale\Rendiconto;

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestione;
use App\Services\Gestionale\SpesaPerVoceService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Consuntivo per voce di spesa, confrontato con il preventivo, **gestione per gestione**.
 *
 * Ogni gestione (l'ordinaria e ciascuna straordinaria) ha il suo piano dei conti e il suo
 * consuntivo: le straordinarie non entrano mai nei totali dell'ordinaria.
 *
 * Fonti:
 * - preventivo: `conti.importo` delle voci non tecniche (il budget deliberato, IVA inclusa);
 *   le voci tecniche (sopravvenienze nate da fatture fuori preventivo) hanno preventivo zero;
 * - consuntivo: lo speso per voce letto dal giornale (`SpesaPerVoceService`, criterio di
 *   competenza: una fattura registrata e non pagata è già spesa);
 * - spese ad personam: righe di fattura su una sola unità (DARE Crediti v/condòmini con
 *   l'immobile, senza voce), addebitate direttamente all'unità;
 * - costi senza voce: righe su conti di costo senza voce di spesa (per esempio le commissioni
 *   bancarie dei pagamenti). Sono spese vere ma nessuna tabella le ripartisce: vanno segnalate.
 *
 * Sola lettura, importi in centesimi con segno (positivo = spesa).
 */
class ConsuntivoGestioniService
{
    private const TIPI_FATTURA = ['fattura_acquisto', 'storno_fattura', 'nota_credito_fornitore'];

    public function __construct(private readonly SpesaPerVoceService $spesaPerVoce) {}

    /**
     * Le gestioni del rendiconto: quelle legate all'esercizio più quelle a cui appartengono voci
     * con spese registrate nell'esercizio. Prima le ordinarie.
     *
     * @return Collection<int, Gestione>
     */
    public function gestioni(Condominio $condominio, Esercizio $esercizio, array $speso = []): Collection
    {
        $ids = $esercizio->gestioni()->pluck('gestioni.id')->map(fn ($id) => (int) $id)->all();

        if ($speso !== []) {
            $daVoci = DB::table('conti as c')
                ->join('piani_conti as pc', 'pc.id', '=', 'c.piano_conto_id')
                ->whereIn('c.id', array_keys($speso))
                ->pluck('pc.gestione_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $ids = array_values(array_unique(array_merge($ids, $daVoci)));
        }

        return Gestione::where('condominio_id', $condominio->id)
            ->whereIn('id', $ids ?: [0])
            ->get()
            ->sortBy(fn (Gestione $g) => ($g->tipo === 'ordinaria' ? '0' : '1').mb_strtolower((string) $g->nome).str_pad((string) $g->id, 10, '0', STR_PAD_LEFT))
            ->values();
    }

    /**
     * @return array{
     *     speso_per_voce: array<int,int>,
     *     gestioni: list<array<string,mixed>>
     * }
     */
    public function calcola(Condominio $condominio, Esercizio $esercizio): array
    {
        $speso = $this->spesaPerVoce->perEsercizio($esercizio);
        $gestioni = $this->gestioni($condominio, $esercizio, $speso);
        $ordinariaId = $gestioni->firstWhere('tipo', 'ordinaria')?->id ?? $gestioni->first()?->id;
        $idsGestioni = $gestioni->pluck('id')->map(fn ($id) => (int) $id)->all();

        $adPersonam = $this->spesePerImmobile($condominio, $esercizio, $idsGestioni, $ordinariaId);
        $senzaVoce = $this->costiSenzaVoce($condominio, $esercizio, $idsGestioni, $ordinariaId);

        $risultato = [];

        foreach ($gestioni as $gestione) {
            $pianoConto = $gestione->pianoConto;
            $conti = $pianoConto
                ? Conto::where('piano_conto_id', $pianoConto->id)->orderBy('codice')->orderBy('nome')->orderBy('id')->get()
                : collect();

            $albero = $this->albero($conti, $speso);
            $voci = array_values(array_filter($albero, fn ($n) => ! $n['tecnico']));
            $sopravvenienze = array_values(array_filter($albero, fn ($n) => $n['tecnico']));

            $immobiliAdPersonam = $adPersonam[$gestione->id] ?? [];
            $righeSenzaVoce = $senzaVoce[$gestione->id] ?? [];

            $totaleVoci = array_sum(array_column($voci, 'consuntivo'));
            $totaleSopravvenienze = array_sum(array_column($sopravvenienze, 'consuntivo'));
            $totaleAdPersonam = array_sum(array_column($immobiliAdPersonam, 'importo'));
            $totaleSenzaVoce = array_sum(array_column($righeSenzaVoce, 'importo'));
            $preventivo = array_sum(array_column($voci, 'preventivo'));

            $spesePerVoce = [];
            foreach ($conti as $conto) {
                $importo = (int) ($speso[$conto->id] ?? 0);
                if ($importo !== 0) {
                    $spesePerVoce[(int) $conto->id] = $importo;
                }
            }

            $consuntivo = $totaleVoci + $totaleSopravvenienze + $totaleAdPersonam + $totaleSenzaVoce;

            $risultato[] = [
                'id'              => (int) $gestione->id,
                'nome'            => (string) $gestione->nome,
                'tipo'            => (string) $gestione->tipo,
                'piano_conto_id'  => $pianoConto?->id,
                'voci'            => $this->appiattisci($voci),
                'sopravvenienze'  => $this->appiattisci($sopravvenienze),
                'ad_personam'     => array_values($immobiliAdPersonam),
                'senza_voce'      => array_values($righeSenzaVoce),
                'spese_per_voce'  => $spesePerVoce,
                'addebiti_per_immobile' => array_map(fn ($r) => $r['importo'], $immobiliAdPersonam),
                'radice_per_voce' => $this->radici($conti),
                'totali'          => [
                    'preventivo'      => $preventivo,
                    'voci'            => $totaleVoci,
                    'sopravvenienze'  => $totaleSopravvenienze,
                    'ad_personam'     => $totaleAdPersonam,
                    'senza_voce'      => $totaleSenzaVoce,
                    'consuntivo'      => $consuntivo,
                    // Lo scostamento confronta il preventivo con tutto lo speso della gestione:
                    // le sopravvenienze e le spese individuali sono spese anche loro.
                    'scostamento'     => $consuntivo - $preventivo,
                    'scostamento_perc' => $this->percentuale($consuntivo - $preventivo, $preventivo),
                    'ripartibile'     => $totaleVoci + $totaleSopravvenienze + $totaleAdPersonam,
                ],
            ];
        }

        return [
            'speso_per_voce' => $speso,
            'gestioni'       => $risultato,
        ];
    }

    /**
     * Albero capitoli → sottoconti con preventivo e consuntivo aggregati verso l'alto. Restano
     * solo i rami con qualcosa da dire (preventivo o spesa diversi da zero).
     *
     * @param  Collection<int, Conto>  $conti
     * @param  array<int,int>  $speso
     * @return list<array<string,mixed>>
     */
    private function albero(Collection $conti, array $speso): array
    {
        $figli = $conti->groupBy(fn ($c) => (int) ($c->parent_id ?? 0));
        $ids = $conti->pluck('id')->map(fn ($id) => (int) $id)->flip();

        $costruisci = function (Conto $conto, bool $tecnicoEreditato, int $livello, array $visti) use (&$costruisci, $figli, $speso): ?array {
            $tecnico = $tecnicoEreditato || (bool) $conto->is_tecnico;
            $segno = in_array($conto->tipo ?? 'spesa', ['spesa', 'uscita'], true) ? 1 : -1;
            $preventivoProprio = $tecnico ? 0 : $segno * abs((int) $conto->importo);
            $consuntivoProprio = (int) ($speso[$conto->id] ?? 0);

            $nodi = [];
            $visti[$conto->id] = true;
            foreach ($figli->get((int) $conto->id, collect()) as $figlio) {
                if (isset($visti[$figlio->id])) continue;
                $nodo = $costruisci($figlio, $tecnico, $livello + 1, $visti);
                if ($nodo !== null) $nodi[] = $nodo;
            }

            $preventivo = $preventivoProprio + array_sum(array_column($nodi, 'preventivo'));
            $consuntivo = $consuntivoProprio + array_sum(array_column($nodi, 'consuntivo'));

            if ($preventivo === 0 && $consuntivo === 0 && $nodi === []) {
                return null;
            }

            return [
                'id'               => (int) $conto->id,
                'codice'           => $conto->codice,
                'nome'             => (string) $conto->nome,
                'livello'          => $livello,
                'tecnico'          => $tecnico,
                'preventivo'       => $preventivo,
                'consuntivo'       => $consuntivo,
                'consuntivo_proprio' => $consuntivoProprio,
                'scostamento'      => $consuntivo - $preventivo,
                'scostamento_perc' => $this->percentuale($consuntivo - $preventivo, $preventivo),
                'figli'            => $nodi,
            ];
        };

        $radici = [];
        foreach ($conti as $conto) {
            // Radice: senza padre, o con un padre fuori da questo piano dei conti.
            if ($conto->parent_id && $ids->has((int) $conto->parent_id)) continue;
            $nodo = $costruisci($conto, false, 0, []);
            if ($nodo !== null) $radici[] = $nodo;
        }

        return $radici;
    }

    /** @return list<array<string,mixed>> l'albero in ordine di stampa, con `livello` e `ha_figli`. */
    private function appiattisci(array $nodi): array
    {
        $righe = [];
        $visita = function (array $nodi) use (&$visita, &$righe): void {
            foreach ($nodi as $nodo) {
                $figli = $nodo['figli'];
                unset($nodo['figli']);
                $nodo['ha_figli'] = $figli !== [];
                $righe[] = $nodo;
                $visita($figli);
            }
        };
        $visita($nodi);

        return $righe;
    }

    /**
     * Il capitolo (radice) di ogni voce: le colonne del riparto stampato sono per capitolo.
     *
     * @return array<int,int>
     */
    private function radici(Collection $conti): array
    {
        $perId = $conti->keyBy('id');
        $radici = [];

        foreach ($conti as $conto) {
            $corrente = $conto;
            $visti = [];
            while ($corrente->parent_id && $perId->has($corrente->parent_id) && ! isset($visti[$corrente->id])) {
                $visti[$corrente->id] = true;
                $corrente = $perId->get($corrente->parent_id);
            }
            $radici[(int) $conto->id] = (int) $corrente->id;
        }

        return $radici;
    }

    /**
     * Spese ad personam dell'esercizio per gestione e unità: righe di fattura su una sola unità
     * (DARE Crediti v/condòmini con l'immobile e senza anagrafica), al netto di storni e note di
     * credito. Le fatture contestate restano fuori, come nello speso per voce.
     *
     * @return array<int, array<int, array{immobile_id:int, nome:string, importo:int}>>
     */
    private function spesePerImmobile(Condominio $condominio, Esercizio $esercizio, array $idsGestioni, ?int $ordinariaId): array
    {
        $righe = DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', function ($join) {
                $join->on('sc.id', '=', 'rs.scrittura_id')->whereNull('sc.deleted_at');
            })
            ->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')
            ->leftJoin('immobili as im', 'im.id', '=', 'rs.immobile_id')
            ->where('sc.condominio_id', $condominio->id)
            ->where('sc.esercizio_id', $esercizio->id)
            ->whereIn('sc.tipo_movimento', self::TIPI_FATTURA)
            ->where('cc.ruolo', 'crediti_condomini')
            ->whereNotNull('rs.immobile_id')
            ->whereNull('rs.anagrafica_id')
            ->whereNull('rs.voce_spesa_id')
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('fattura_scrittura as fs')
                    ->join('fatture_passive as fp', 'fp.id', '=', 'fs.fattura_passiva_id')
                    ->whereColumn('fs.scrittura_contabile_id', 'sc.id')
                    ->where('fp.stato_approvazione', 'contestata');
            })
            ->get(['rs.immobile_id', 'rs.tipo_riga', 'rs.importo', 'sc.gestione_id', 'im.nome', 'im.interno']);

        $perGestione = [];
        foreach ($righe as $r) {
            $g = $this->gestioneDi($r->gestione_id, $idsGestioni, $ordinariaId);
            if ($g === null) continue;

            $iid = (int) $r->immobile_id;
            if (! isset($perGestione[$g][$iid])) {
                $perGestione[$g][$iid] = ['immobile_id' => $iid, 'nome' => $this->nomeUnita($r->nome, $r->interno), 'importo' => 0];
            }
            $perGestione[$g][$iid]['importo'] += $r->tipo_riga === 'dare' ? (int) $r->importo : -(int) $r->importo;
        }

        foreach ($perGestione as $g => $unita) {
            $perGestione[$g] = array_filter($unita, fn ($u) => $u['importo'] !== 0);
        }

        return $perGestione;
    }

    /**
     * Costi registrati su conti di costo senza voce di spesa, per gestione e conto.
     *
     * @return array<int, array<int, array{conto_contabile_id:int, conto:string, importo:int}>>
     */
    private function costiSenzaVoce(Condominio $condominio, Esercizio $esercizio, array $idsGestioni, ?int $ordinariaId): array
    {
        $righe = DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', function ($join) {
                $join->on('sc.id', '=', 'rs.scrittura_id')->whereNull('sc.deleted_at');
            })
            ->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')
            ->where('sc.condominio_id', $condominio->id)
            ->where('sc.esercizio_id', $esercizio->id)
            ->where('cc.tipo', 'costo')
            ->whereNull('rs.voce_spesa_id')
            ->whereNotIn('sc.tipo_movimento', RegistroContabilitaService::TIPI_SALDO)
            ->get(['rs.conto_contabile_id', 'rs.tipo_riga', 'rs.importo', 'sc.gestione_id', 'cc.codice', 'cc.nome']);

        $perGestione = [];
        foreach ($righe as $r) {
            $g = $this->gestioneDi($r->gestione_id, $idsGestioni, $ordinariaId);
            if ($g === null) continue;

            $cid = (int) $r->conto_contabile_id;
            if (! isset($perGestione[$g][$cid])) {
                $perGestione[$g][$cid] = ['conto_contabile_id' => $cid, 'conto' => trim($r->codice.' '.$r->nome), 'importo' => 0];
            }
            $perGestione[$g][$cid]['importo'] += $r->tipo_riga === 'dare' ? (int) $r->importo : -(int) $r->importo;
        }

        foreach ($perGestione as $g => $voci) {
            $perGestione[$g] = array_filter($voci, fn ($v) => $v['importo'] !== 0);
        }

        return $perGestione;
    }

    /** La gestione a cui imputare un movimento: la sua, se è del rendiconto, altrimenti l'ordinaria. */
    public function gestioneDi($gestioneId, array $idsGestioni, ?int $ordinariaId): ?int
    {
        if ($gestioneId !== null && in_array((int) $gestioneId, $idsGestioni, true)) {
            return (int) $gestioneId;
        }

        return $ordinariaId;
    }

    public function nomeUnita(?string $nome, ?string $interno): string
    {
        $nome = trim((string) $nome);
        $interno = trim((string) $interno);

        return $interno !== '' ? trim($nome.' (int. '.$interno.')') : ($nome !== '' ? $nome : '—');
    }

    /** Scostamento percentuale con un decimale; null se il preventivo è zero. */
    public function percentuale(int $scostamento, int $preventivo): ?float
    {
        return $preventivo !== 0 ? round($scostamento * 100 / abs($preventivo), 1) : null;
    }
}
