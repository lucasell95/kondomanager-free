<?php

namespace App\Services\Gestionale\Chiusura;

use App\Models\Esercizio;
use App\Services\Gestionale\StatoPatrimonialeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * I controlli prima di chiudere un esercizio. **Sola lettura.**
 *
 * Ogni controllo ha un livello:
 *  - `errore`: la chiusura non si può eseguire finché non si risolve (giornale che non quadra,
 *    scritture in bozza, un esercizio successivo che esiste già);
 *  - `avviso`: si può chiudere, ma l'amministratore deve saperlo (F24 da versare, fatture da
 *    approvare, esercizio non ancora finito);
 *  - `ok` / `info`: tutto in ordine, o un dato utile.
 *
 * Le stesse regole di livello `errore` le riapplica l'azione di chiusura dentro la transazione:
 * la pagina è una guida, non la difesa.
 */
class ControlliChiusuraService
{
    /**
     * Tabelle che l'import degli estratti conto bancari potrebbe usare (area «banca», in
     * sviluppo parallelo). Se una esiste e ha una colonna di abbinamento, si controlla che non
     * restino movimenti dell'esercizio senza scrittura. Altrimenti il controllo è informativo.
     *
     * @var array<string, string>  tabella => colonna dell'abbinamento
     */
    public const TABELLE_BANCA = [
        // Modulo Banca (import estratti conto): i movimenti «ignorati» non contano come non abbinati.
        'banca_movimenti'          => 'scrittura_contabile_id',
        'movimenti_bancari'        => 'scrittura_contabile_id',
        'estratto_conto_movimenti' => 'scrittura_contabile_id',
        'righe_estratto_conto'     => 'scrittura_contabile_id',
    ];

    public function __construct(private StatoPatrimonialeService $statoPatrimoniale) {}

    /**
     * @return list<array{codice: string, livello: string, titolo: string, dettaglio: string, conteggio: int}>
     */
    public function esegui(Esercizio $esercizio): array
    {
        return array_values(array_filter([
            $this->stato($esercizio),
            $this->successivo($esercizio),
            $this->quadratura($esercizio),
            $this->statoPatrimoniale($esercizio),
            $this->bozze($esercizio),
            $this->pianiInBozza($esercizio),
            $this->fattureDaApprovare($esercizio),
            $this->fattureAperte($esercizio),
            $this->f24($esercizio),
            $this->banca($esercizio),
            $this->fine($esercizio),
            $this->fuoriPeriodo($esercizio),
        ]));
    }

    /** Solo i controlli che bloccano. */
    public function errori(Esercizio $esercizio): array
    {
        return array_values(array_filter($this->esegui($esercizio), fn ($c) => $c['livello'] === 'errore'));
    }

    private function voce(string $codice, string $livello, string $titolo, string $dettaglio, int $conteggio = 0): array
    {
        return compact('codice', 'livello', 'titolo', 'dettaglio', 'conteggio');
    }

    private function scritture(Esercizio $esercizio)
    {
        return DB::table('scritture_contabili')
            ->where('condominio_id', $esercizio->condominio_id)
            ->where('esercizio_id', $esercizio->id)
            ->whereNull('deleted_at');
    }

    private function stato(Esercizio $esercizio): ?array
    {
        if ($esercizio->stato === 'aperto') {
            return null;
        }

        return $this->voce('stato', 'errore', 'Esercizio già chiuso',
            "L'esercizio «{$esercizio->nome}» è già chiuso: la procedura parte da un esercizio aperto.");
    }

    private function successivo(Esercizio $esercizio): ?array
    {
        $successivo = Esercizio::where('condominio_id', $esercizio->condominio_id)
            ->where('id', '!=', $esercizio->id)
            ->whereDate('data_inizio', '>', $esercizio->data_fine)
            ->orderBy('data_inizio')
            ->first();

        if (! $successivo) {
            return null;
        }

        return $this->voce('successivo', 'errore', 'Esiste già un esercizio successivo',
            "L'esercizio «{$successivo->nome}» inizia dopo la fine di questo. La procedura crea l'esercizio "
            .'successivo da sé: se quello esistente è vuoto eliminalo, altrimenti chiudi a mano.');
    }

    private function quadratura(Esercizio $esercizio): array
    {
        $sbilanciate = DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', 'sc.id', '=', 'rs.scrittura_id')
            ->where('sc.condominio_id', $esercizio->condominio_id)
            ->where('sc.esercizio_id', $esercizio->id)
            ->whereNull('sc.deleted_at')
            ->groupBy('sc.id', 'sc.numero_protocollo')
            ->havingRaw("SUM(CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE 0 END) <> SUM(CASE WHEN rs.tipo_riga = 'avere' THEN rs.importo ELSE 0 END)")
            ->pluck('sc.numero_protocollo');

        if ($sbilanciate->isEmpty()) {
            return $this->voce('quadratura', 'ok', 'Dare e avere quadrano', 'Ogni scrittura dell\'esercizio ha dare = avere.');
        }

        return $this->voce('quadratura', 'errore', 'Scritture che non quadrano',
            'Dare e avere differiscono in: '.$sbilanciate->take(10)->implode(', ')
            .($sbilanciate->count() > 10 ? ' e altre' : '').'. Correggile prima di chiudere.', $sbilanciate->count());
    }

    private function statoPatrimoniale(Esercizio $esercizio): array
    {
        $sp = $this->statoPatrimoniale->calcola($esercizio->condominio, $esercizio);

        if ($sp['quadra']) {
            return $this->voce('stato_patrimoniale', 'ok', 'Stato patrimoniale in equilibrio',
                'Attività = passività + risultato, a fine esercizio.');
        }

        return $this->voce('stato_patrimoniale', 'avviso', 'Stato patrimoniale non in equilibrio',
            'Sbilancio di '.number_format(abs($sp['sbilancio']) / 100, 2, ',', '.').' €'
            .($sp['liquidita_non_contabilizzata'] ? ' (c\'è un saldo di apertura di cassa non ancora a giornale)' : '')
            .'. La chiusura non lo corregge: verificalo dal libro giornale.');
    }

    private function bozze(Esercizio $esercizio): array
    {
        $bozze = (clone $this->scritture($esercizio))->where('stato', 'bozza')->count();

        if ($bozze === 0) {
            return $this->voce('bozze', 'ok', 'Nessuna scrittura in bozza', 'Tutti i movimenti sono registrati.');
        }

        return $this->voce('bozze', 'errore', 'Scritture in bozza',
            "{$bozze} scritture dell'esercizio sono ancora in bozza: registrale o eliminale.", $bozze);
    }

    private function pianiInBozza(Esercizio $esercizio): ?array
    {
        $piani = DB::table('piani_rate')
            ->join('esercizio_gestione as eg', 'eg.gestione_id', '=', 'piani_rate.gestione_id')
            ->where('eg.esercizio_id', $esercizio->id)
            ->where('piani_rate.attivo', true)
            ->where('piani_rate.stato', 'bozza')
            ->pluck('piani_rate.nome');

        if ($piani->isEmpty()) {
            return null;
        }

        return $this->voce('piani_bozza', 'avviso', 'Piani rate non approvati',
            'Ancora in bozza: '.$piani->implode(', ').'. Le loro rate non sono emesse e non entrano nei residui.', $piani->count());
    }

    private function fattureDaApprovare(Esercizio $esercizio): array
    {
        $fatture = DB::table('fatture_passive')
            ->where('condominio_id', $esercizio->condominio_id)
            ->where('esercizio_id', $esercizio->id)
            ->whereIn('stato_approvazione', ['da_approvare', 'contestata'])
            ->count();

        if ($fatture === 0) {
            return $this->voce('fatture_approvazione', 'ok', 'Fatture approvate', 'Nessuna fattura da approvare o contestata.');
        }

        return $this->voce('fatture_approvazione', 'avviso', 'Fatture da approvare o contestate',
            "{$fatture} fatture dell'esercizio non sono approvate. Sono già a giornale: dopo la chiusura non si "
            .'modificano più, quindi verificale adesso.', $fatture);
    }

    private function fattureAperte(Esercizio $esercizio): ?array
    {
        $aperte = DB::table('fatture_passive')
            ->where('condominio_id', $esercizio->condominio_id)
            ->where('esercizio_id', $esercizio->id)
            ->whereIn('stato_pagamento', ['aperta', 'parziale'])
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(netto_a_pagare), 0) as totale')
            ->first();

        if (! $aperte || (int) $aperte->n === 0) {
            return null;
        }

        return $this->voce('fatture_aperte', 'info', 'Fatture ancora da pagare',
            "{$aperte->n} fatture restano da pagare: il debito resta a giornale e il pagamento si registra nel nuovo esercizio.",
            (int) $aperte->n);
    }

    private function f24(Esercizio $esercizio): array
    {
        $deleghe = DB::table('deleghe_f24')
            ->where('condominio_id', $esercizio->condominio_id)
            ->whereIn('stato', ['bozza', 'confermata'])
            ->count();

        // Ritenute operate su pagamenti dell'esercizio e non ancora in una delega versata.
        $ritenute = DB::table('pagamenti_fornitori as pf')
            ->join('scritture_contabili as sc', 'sc.id', '=', 'pf.scrittura_contabile_id')
            ->where('pf.condominio_id', $esercizio->condominio_id)
            ->where('sc.esercizio_id', $esercizio->id)
            ->where('pf.importo_ritenuta', '>', 0)
            ->where('pf.stato', '!=', 'stornato')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('riga_f24_pagamento as rfp')
                    ->join('righe_f24 as r', 'r.id', '=', 'rfp.riga_f24_id')
                    ->join('deleghe_f24 as d', 'd.id', '=', 'r.delega_f24_id')
                    ->whereColumn('rfp.pagamento_fornitore_id', 'pf.id')
                    ->where('d.stato', 'versata');
            })
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(pf.importo_ritenuta), 0) as totale')
            ->first();

        $n = (int) ($ritenute->n ?? 0);

        if ($deleghe === 0 && $n === 0) {
            return $this->voce('f24', 'ok', 'Ritenute versate', 'Nessuna ritenuta dell\'esercizio in attesa di F24.');
        }

        $parti = [];
        if ($n > 0) {
            $parti[] = "{$n} pagamenti con ritenuta (".number_format(((int) $ritenute->totale) / 100, 2, ',', '.').' €) non ancora versati con F24';
        }
        if ($deleghe > 0) {
            $parti[] = "{$deleghe} deleghe F24 in bozza o confermate e non versate";
        }

        return $this->voce('f24', 'avviso', 'F24 da versare',
            implode('; ', $parti).'. Si versano anche dopo la chiusura, ma rispetta le scadenze (16 del mese successivo, o le soglie semestrali).',
            $n + $deleghe);
    }

    private function banca(Esercizio $esercizio): array
    {
        foreach (self::TABELLE_BANCA as $tabella => $colonna) {
            if (! Schema::hasTable($tabella) || ! Schema::hasColumn($tabella, $colonna)) {
                continue;
            }

            $colonnaData = collect(['data_contabile', 'data_operazione', 'data_valuta', 'data', 'data_movimento'])
                ->first(fn ($c) => Schema::hasColumn($tabella, $c));

            $query = DB::table($tabella)->whereNull($colonna);

            if (Schema::hasColumn($tabella, 'stato')) {
                $query->where('stato', '!=', 'ignorato');
            }

            if (Schema::hasColumn($tabella, 'condominio_id')) {
                $query->where('condominio_id', $esercizio->condominio_id);
            }
            if ($colonnaData) {
                $query->whereBetween($colonnaData, [$esercizio->data_inizio->toDateString(), $esercizio->data_fine->toDateString()]);
            }

            $nonAbbinati = $query->count();

            return $nonAbbinati === 0
                ? $this->voce('banca', 'ok', 'Banca riconciliata', 'Tutti i movimenti bancari dell\'esercizio sono abbinati a una scrittura.')
                : $this->voce('banca', 'avviso', 'Movimenti bancari non abbinati',
                    "{$nonAbbinati} movimenti dell'estratto conto nel periodo dell'esercizio non sono abbinati a una scrittura.", $nonAbbinati);
        }

        return $this->voce('banca', 'info', 'Riconciliazione bancaria',
            'Il programma non ha ancora gli estratti conto importati: confronta a mano il saldo della banca al '
            .$esercizio->data_fine->format('d/m/Y').' con quello delle casse.');
    }

    private function fine(Esercizio $esercizio): ?array
    {
        if ($esercizio->data_fine->copy()->endOfDay()->isPast()) {
            return null;
        }

        return $this->voce('fine', 'avviso', 'Esercizio non ancora terminato',
            "L'esercizio termina il {$esercizio->data_fine->format('d/m/Y')}. Dopo la chiusura i movimenti di questi "
            .'giorni andranno nel nuovo esercizio.');
    }

    private function fuoriPeriodo(Esercizio $esercizio): ?array
    {
        $fuori = (clone $this->scritture($esercizio))
            ->where(function ($q) use ($esercizio) {
                $q->whereDate('data_competenza', '>', $esercizio->data_fine)
                  ->orWhereDate('data_competenza', '<', $esercizio->data_inizio);
            })
            ->count();

        if ($fuori === 0) {
            return null;
        }

        return $this->voce('fuori_periodo', 'info', 'Movimenti con data fuori dall\'esercizio',
            "{$fuori} scritture hanno una data di competenza fuori dal periodo dell'esercizio (es. incassi tardivi "
            .'o fatture pregresse). Non è un errore, ma conviene saperlo.', $fuori);
    }
}
