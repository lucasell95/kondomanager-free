<?php

namespace App\Services\Gestionale;

use App\Models\Condominio;
use App\Models\Esercizio;
use Illuminate\Support\Facades\DB;

/**
 * Stato Patrimoniale e verifica della quadratura fondamentale.
 *
 *      ATTIVITÀ = PASSIVITÀ + PATRIMONIO NETTO
 *
 * dove il patrimonio netto, in assenza di conti di netto dedicati nel piano dei
 * conti, coincide con il risultato d'esercizio (Ricavi − Costi).
 *
 * PERCHÉ SERVE. Il `DoubleEntryValidator` verifica che ogni SINGOLA scrittura
 * abbia DARE = AVERE: condizione necessaria ma NON sufficiente. Un saldo entrato
 * nel sistema senza contropartita (es. il saldo di apertura di una cassa prima
 * della beta.25) non viola nessuna singola scrittura, eppure lascia l'attivo
 * scoperto. Solo il controllo A = P + N lo intercetta.
 *
 * È quindi tanto un report quanto un ORACOLO: qualunque futura falla che
 * introduca valore senza contropartita fa emergere uno sbilancio qui, invece di
 * restare invisibile fino alla segnalazione di un amministratore.
 *
 * Convenzioni di segno (coerenti con SaldoCassaService):
 *   attivo, costo   → DARE aumenta  → saldo = Σdare − Σavere
 *   passivo, ricavo → AVERE aumenta → saldo = Σavere − Σdare
 *
 * Le scritture annullate (soft-deleted) sono sempre escluse.
 *
 * PER ESERCIZIO. Il prospetto di un esercizio è una fotografia a fine esercizio:
 *   - i conti PATRIMONIALI (attivo, passivo) sono cumulativi: tutte le scritture
 *     dell'esercizio e di quelli che lo precedono. Non ci sono scritture di chiusura
 *     e riapertura, quindi la banca di fine anno è la somma di tutti i movimenti
 *     dall'inizio, non le sole variazioni dell'anno;
 *   - i conti ECONOMICI (costo, ricavo) sono del solo esercizio: il risultato
 *     d'esercizio è quello dell'anno.
 * Il risultato degli esercizi precedenti, che i patrimoniali portano dentro ma che
 * nessuna scrittura ha chiuso, entra nel patrimonio netto come voce a sé
 * (`risultato_esercizi_precedenti`): senza, dal secondo esercizio l'equazione non
 * chiuderebbe più. Nel primo esercizio vale 0 e il prospetto è quello di sempre.
 *
 * @see docs/fondo_accantonato_e_quadratura_sp.md
 */
class StatoPatrimonialeService
{
    /**
     * @return array{
     *     attivo: array{totale:int, voci:array},
     *     passivo: array{totale:int, voci:array},
     *     costi:int, ricavi:int, risultato_esercizio:int, risultato_esercizi_precedenti:int,
     *     liquidita_non_contabilizzata:int,
     *     sbilancio:int, quadra:bool
     * }
     */
    public function calcola(Condominio $condominio, ?Esercizio $esercizio = null): array
    {
        $saldi = $this->saldiPerConto($condominio, $esercizio);

        $attivo  = $saldi->where('tipo', 'attivo');
        $passivo = $saldi->where('tipo', 'passivo');

        $totaleAttivo  = (int) $attivo->sum('saldo');
        $totalePassivo = (int) $passivo->sum('saldo');
        $costi         = (int) $saldi->where('tipo', 'costo')->sum('saldo');
        $ricavi        = (int) $saldi->where('tipo', 'ricavo')->sum('saldo');

        $risultato = $ricavi - $costi;

        // Costi e ricavi degli esercizi precedenti (vedi la nota in testa alla classe).
        $risultatoPrecedenti = (int) $saldi->where('tipo', 'ricavo')->sum('saldo_precedenti')
            - (int) $saldi->where('tipo', 'costo')->sum('saldo_precedenti');

        // Saldi di apertura delle casse non ancora portati a giornale (installazioni
        // aggiornate ma con la migrazione di backfill non ancora eseguita). Sono
        // liquidità REALE priva di contropartita contabile: va esposta, non nascosta,
        // ed è esattamente ciò che produce lo sbilancio.
        $liquiditaNonContabilizzata = (int) DB::table('casse')
            ->where('condominio_id', $condominio->id)
            ->sum('saldo_iniziale');

        $totaleAttivo += $liquiditaNonContabilizzata;

        $sbilancio = $totaleAttivo - $totalePassivo - $risultato - $risultatoPrecedenti;

        return [
            'attivo' => [
                'totale' => $totaleAttivo,
                'voci'   => $attivo->values()->all(),
            ],
            'passivo' => [
                'totale' => $totalePassivo,
                'voci'   => $passivo->values()->all(),
            ],
            'costi'                        => $costi,
            'ricavi'                       => $ricavi,
            'risultato_esercizio'          => $risultato,
            'risultato_esercizi_precedenti' => $risultatoPrecedenti,
            'liquidita_non_contabilizzata' => $liquiditaNonContabilizzata,
            'sbilancio'                    => $sbilancio,
            'quadra'                       => $sbilancio === 0,
        ];
    }

    /** Lo Stato Patrimoniale chiude? Scorciatoia per test e controlli di integrità. */
    public function quadra(Condominio $condominio, ?Esercizio $esercizio = null): bool
    {
        return $this->calcola($condominio, $esercizio)['quadra'];
    }

    /**
     * Saldo di ogni conto contabile, già orientato secondo la natura del conto.
     *
     * Senza esercizio: tutte le scritture. Con l'esercizio: i patrimoniali fino a fine
     * esercizio (questo e i precedenti), gli economici del solo esercizio; per questi
     * ultimi `saldo_precedenti` porta la parte degli esercizi prima.
     */
    private function saldiPerConto(Condominio $condominio, ?Esercizio $esercizio)
    {
        // Gli esercizi che la fotografia include: questo e quelli iniziati prima.
        $eserciziInclusi = $esercizio
            ? Esercizio::where('condominio_id', $condominio->id)
                ->whereDate('data_inizio', '<=', $esercizio->data_inizio)
                ->pluck('id')
                ->push($esercizio->id)
                ->unique()
                ->all()
            : null;

        // `sc.esercizio_id = <id>`: l'id è un intero del modello, non un input.
        $soloEsercizio = $esercizio ? 'sc.esercizio_id = '.(int) $esercizio->id : '1 = 1';

        $query = DB::table('conti_contabili as cc')
            ->leftJoin('righe_scritture as rs', 'rs.conto_contabile_id', '=', 'cc.id')
            ->leftJoin('scritture_contabili as sc', function ($join) use ($eserciziInclusi) {
                $join->on('rs.scrittura_id', '=', 'sc.id')
                     ->whereNull('sc.deleted_at');

                if ($eserciziInclusi !== null) {
                    $join->whereIn('sc.esercizio_id', $eserciziInclusi);
                }
            })
            ->where('cc.condominio_id', $condominio->id)
            ->whereNull('cc.deleted_at')
            ->groupBy('cc.id', 'cc.codice', 'cc.nome', 'cc.tipo', 'cc.categoria')
            ->select([
                'cc.id',
                'cc.codice',
                'cc.nome',
                'cc.tipo',
                'cc.categoria',
                DB::raw("COALESCE(SUM(CASE WHEN sc.id IS NULL THEN 0 WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE 0 END), 0) as dare"),
                DB::raw("COALESCE(SUM(CASE WHEN sc.id IS NULL THEN 0 WHEN rs.tipo_riga = 'avere' THEN rs.importo ELSE 0 END), 0) as avere"),
                DB::raw("COALESCE(SUM(CASE WHEN sc.id IS NULL THEN 0 WHEN {$soloEsercizio} AND rs.tipo_riga = 'dare' THEN rs.importo ELSE 0 END), 0) as dare_esercizio"),
                DB::raw("COALESCE(SUM(CASE WHEN sc.id IS NULL THEN 0 WHEN {$soloEsercizio} AND rs.tipo_riga = 'avere' THEN rs.importo ELSE 0 END), 0) as avere_esercizio"),
            ]);

        return collect($query->get())->map(function ($riga) {
            $natura = in_array($riga->tipo, ['attivo', 'costo'], true) ? 1 : -1;
            $economico = in_array($riga->tipo, ['costo', 'ricavo'], true);

            // Patrimoniali: cumulativi. Economici: del solo esercizio.
            $dare  = (int) ($economico ? $riga->dare_esercizio : $riga->dare);
            $avere = (int) ($economico ? $riga->avere_esercizio : $riga->avere);

            // Conti di natura DARE (attivo, costo) vs natura AVERE (passivo, ricavo).
            $saldo = $natura * ($dare - $avere);

            $saldoPrecedenti = $economico
                ? $natura * (((int) $riga->dare - (int) $riga->avere) - ($dare - $avere))
                : 0;

            return [
                'id'               => $riga->id,
                'codice'           => $riga->codice,
                'nome'             => $riga->nome,
                'tipo'             => $riga->tipo,
                'categoria'        => $riga->categoria,
                'dare'             => $dare,
                'avere'            => $avere,
                'saldo'            => $saldo,
                'saldo_precedenti' => $saldoPrecedenti,
            ];
        })->filter(fn ($c) => $c['saldo'] !== 0 || $c['dare'] !== 0 || $c['avere'] !== 0 || $c['saldo_precedenti'] !== 0);
    }
}
