<?php

namespace App\Services\Gestionale\Rendiconto;

use App\Helpers\MoneyHelper;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\RendicontoEsercizio;
use Illuminate\Support\Facades\DB;

/**
 * Rendiconto condominiale annuale (art. 1130-bis c.c.) di un esercizio: mette insieme registro
 * di contabilità, riepilogo finanziario, consuntivo per gestione confrontato col preventivo,
 * riparto consuntivo con i conguagli, nota sintetica, e le verifiche interne che dicono se i
 * numeri tornano.
 *
 * Tutto è calcolato al momento dal giornale; si salvano solo nota ed estratti conto
 * (`RendicontoEsercizio`). Sola lettura sulla contabilità: si può produrre anche per un
 * esercizio chiuso.
 */
class RendicontoService
{
    public function __construct(
        private readonly RegistroContabilitaService $registro,
        private readonly ConsuntivoGestioniService $consuntivo,
        private readonly RipartoConsuntivoService $riparto,
        private readonly RiepilogoFinanziarioService $riepilogo,
        private readonly NotaRendicontoService $nota,
    ) {}

    public function datiSalvati(Esercizio $esercizio): ?RendicontoEsercizio
    {
        return RendicontoEsercizio::where('esercizio_id', $esercizio->id)->first();
    }

    public function calcola(Condominio $condominio, Esercizio $esercizio): array
    {
        $salvati = $this->datiSalvati($esercizio);
        $estratti = $salvati?->estrattiConto() ?? [];

        $registro = $this->registro->calcola($condominio, $esercizio);
        $consuntivo = $this->consuntivo->calcola($condominio, $esercizio);
        $riparto = $this->riparto->calcola($condominio, $esercizio, $consuntivo);
        $riepilogo = $this->riepilogo->calcola($condominio, $esercizio, $registro, $estratti);

        $rendiconto = [
            'condominio' => [
                'id'             => (int) $condominio->id,
                'nome'           => (string) $condominio->nome,
                'indirizzo'      => (string) ($condominio->indirizzo ?? ''),
                'codice_fiscale' => (string) ($condominio->codice_fiscale ?? ''),
            ],
            'esercizio' => [
                'id'          => (int) $esercizio->id,
                'nome'        => (string) $esercizio->nome,
                'data_inizio' => $esercizio->data_inizio?->toDateString(),
                'data_fine'   => $esercizio->data_fine?->toDateString(),
                'stato'       => (string) $esercizio->stato,
            ],
            'generato_il' => now()->toIso8601String(),
            'registro'    => $registro,
            'riepilogo'   => $riepilogo,
            'consuntivo'  => $consuntivo,
            'riparto'     => $riparto,
        ];

        $rendiconto['verifiche'] = $this->verifiche($condominio, $esercizio, $rendiconto);
        $rendiconto['esito_verifiche'] = $this->esitoComplessivo($rendiconto['verifiche']);

        return $rendiconto;
    }

    /**
     * La nota sintetica da mostrare e stampare: quella salvata o, in mancanza, il modello
     * predefinito; in chiaro (`testo`, con i segnaposto) e compilata con i numeri (`compilata`).
     */
    public function nota(array $rendiconto, ?RendicontoEsercizio $salvati): array
    {
        $modello = $this->nota->modello($rendiconto);
        $testo = $salvati?->nota_sintetica;
        $fatti = $this->nota->fatti($rendiconto);
        $compilata = $this->nota->compila($this->nota->aggiornaFrasiSuperate($testo ?? $modello), $fatti);

        return [
            'testo'      => $testo ?? $modello,
            'salvata'    => $testo !== null,
            'fonte'      => $salvati?->fonte_nota ?? 'modello',
            'modello_ia' => $salvati?->modello_ia,
            'versione_prompt_ia' => $salvati?->versione_prompt_ia,
            'modello'    => $modello,
            'compilata'  => $compilata['testo'],
            'mancanti'   => $compilata['mancanti'],
            'fatti'      => $fatti,
            'aggiornata_il' => $salvati?->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Verifiche interne. `errore` = i numeri non tornano e il rendiconto non va approvato così;
     * `avviso` = c'è qualcosa da guardare; `ok` = tutto torna.
     *
     * @return list<array{codice:string, titolo:string, esito:string, dettaglio:string}>
     */
    private function verifiche(Condominio $condominio, Esercizio $esercizio, array $r): array
    {
        $v = [];
        $e = fn (int $c) => MoneyHelper::format($c);
        $add = function (string $codice, string $titolo, string $esito, string $dettaglio) use (&$v) {
            $v[] = compact('codice', 'titolo', 'esito', 'dettaglio');
        };

        // 1. Il giornale dell'esercizio quadra.
        $q = DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', function ($join) {
                $join->on('sc.id', '=', 'rs.scrittura_id')->whereNull('sc.deleted_at');
            })
            ->where('sc.condominio_id', $condominio->id)
            ->where('sc.esercizio_id', $esercizio->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE 0 END), 0) as dare")
            ->selectRaw("COALESCE(SUM(CASE WHEN rs.tipo_riga = 'avere' THEN rs.importo ELSE 0 END), 0) as avere")
            ->first();
        $dare = (int) ($q->dare ?? 0);
        $avere = (int) ($q->avere ?? 0);
        $add('giornale', 'Il libro giornale dell\'esercizio quadra', $dare === $avere ? 'ok' : 'errore',
            "Dare {$e($dare)}, avere {$e($avere)}".($dare !== $avere ? ', differenza '.$e($dare - $avere) : '').'.');

        // 2. Saldo finale del registro = saldo contabile, conto per conto.
        $diversi = array_filter($r['riepilogo']['conti'], fn ($c) => $c['saldo_finale'] !== $c['saldo_contabile']);
        $add('saldi_cassa', 'Saldo finale di cassa e banca = saldo contabile', $diversi === [] ? 'ok' : 'errore',
            $diversi === []
                ? 'Per ogni conto il saldo iniziale più le entrate meno le uscite coincide con il saldo del giornale ('.$e($r['riepilogo']['totali']['saldo_finale']).' in totale).'
                : implode('; ', array_map(fn ($c) => "{$c['nome']}: registro {$e($c['saldo_finale'])}, giornale {$e($c['saldo_contabile'])}", $diversi)).'.');

        // 3. Saldo finale = estratto conto.
        $banche = array_filter($r['riepilogo']['conti'], fn ($c) => $c['tipo'] === 'banca');
        $conEstratto = array_filter($r['riepilogo']['conti'], fn ($c) => $c['estratto_conto'] !== null);
        $nonCoincidono = array_filter($conEstratto, fn ($c) => $c['differenza_estratto'] !== 0);
        $senzaEstratto = array_filter($banche, fn ($c) => $c['estratto_conto'] === null);
        if ($nonCoincidono !== []) {
            $add('estratto_conto', 'Saldo finale = estratto conto', 'errore',
                implode('; ', array_map(fn ($c) => "{$c['nome']}: contabile {$e($c['saldo_finale'])}, estratto conto {$e((int) $c['estratto_conto'])}, differenza {$e($c['differenza_estratto'])}", $nonCoincidono)).'.');
        } elseif ($senzaEstratto !== []) {
            $add('estratto_conto', 'Saldo finale = estratto conto', 'avviso',
                'Indicare il saldo dell\'estratto conto al '.$this->data($r['esercizio']['data_fine']).' per: '.implode(', ', array_map(fn ($c) => $c['nome'], $senzaEstratto)).'.');
        } else {
            $add('estratto_conto', 'Saldo finale = estratto conto', 'ok',
                $conEstratto === [] ? 'Nessun conto bancario.' : 'Il saldo contabile coincide con l\'estratto conto per ogni conto indicato.');
        }

        // 4–6. Per gestione: riparto completo, conguagli coerenti, costi senza voce.
        foreach ($r['riparto'] as $i => $rip) {
            $t = $rip['totali'];
            $nome = $rip['gestione'];

            $add("riparto_{$rip['gestione_id']}", "Riparto «{$nome}» = totale spese", $t['non_ripartito'] === 0 ? 'ok' : 'errore',
                $t['non_ripartito'] === 0
                    ? "Le quote dei condòmini sommano esattamente alle spese da ripartire ({$e($t['spese'])})."
                    : "Ripartite {$e($t['ripartito'])} su {$e($t['spese'])}: non ripartite {$e($t['non_ripartito'])} ("
                        .implode('; ', array_map(fn ($n) => "{$n['voce']} {$e($n['importo'])}", $rip['non_ripartite'])).'). Collegare le tabelle millesimali o i titolari mancanti.');

            $atteso = $t['saldo_iniziale'] + $t['ripartito'] - $t['contributi'] - $t['versato'];
            $add("conguagli_{$rip['gestione_id']}", "Conguagli «{$nome}» = saldi iniziali + spese − versamenti", $atteso === $t['conguaglio'] ? 'ok' : 'errore',
                "Saldi iniziali {$e($t['saldo_iniziale'])} + quote {$e($t['ripartito'])}"
                .($t['contributi'] !== 0 ? " − già versato {$e($t['contributi'])}" : '')
                ." − versato {$e($t['versato'])} = {$e($atteso)}; somma dei conguagli {$e($t['conguaglio'])}.");

            $senzaVoce = $r['consuntivo']['gestioni'][$i]['totali']['senza_voce'] ?? 0;
            if ($senzaVoce !== 0) {
                $add("senza_voce_{$rip['gestione_id']}", "Costi senza voce di spesa «{$nome}»", 'avviso',
                    "{$e($senzaVoce)} di costi (per esempio commissioni bancarie) non sono imputati a nessuna voce e quindi non entrano nel riparto. Registrarli su una voce di spesa.");
            }

            if ($rip['millesimi_non_compilati'] > 0) {
                $add("millesimi_{$rip['gestione_id']}", "Millesimi non compilati «{$nome}»", 'avviso',
                    "{$rip['millesimi_non_compilati']} righe di tabella senza millesimo: quelle unità non partecipano al riparto.");
            }

            if ($rip['contributi_non_attribuiti'] !== 0) {
                $add("contributi_{$rip['gestione_id']}", "Già versato non attribuito «{$nome}»", 'avviso',
                    "{$e($rip['contributi_non_attribuiti'])} di contributi già versati non trovano un condòmino con una quota della voce sulla stessa unità.");
            }
        }

        // 7. Versamenti dei condòmini: registro di cassa = riparto.
        $versatoRiparto = array_sum(array_map(fn ($rip) => $rip['totali']['versato'], $r['riparto']));
        $versatoCassa = array_sum(array_map(
            fn ($c) => $c['netto'],
            array_filter($r['riepilogo']['categorie'], fn ($c) => str_starts_with($c['chiave'], 'versamenti:') || $c['chiave'] === 'rimborsi_condomini')
        ));
        $add('versamenti', 'Versamenti dei condòmini: registro di cassa = riparto', $versatoCassa === $versatoRiparto ? 'ok' : 'avviso',
            "Nel registro {$e($versatoCassa)}, nel riparto {$e($versatoRiparto)}"
            .($versatoCassa !== $versatoRiparto ? '. La differenza di solito è un incasso con l\'eccedenza senza quota o un movimento registrato su un altro esercizio.' : '.'));

        // 8. Stato patrimoniale.
        $sp = $r['riepilogo']['stato_patrimoniale'];
        $add('stato_patrimoniale', 'Situazione patrimoniale: attività = passività + risultato', $sp['quadra'] ? 'ok' : 'errore',
            $sp['quadra'] ? 'Lo stato patrimoniale a fine esercizio quadra.' : "Sbilancio di {$e($sp['sbilancio'])}.");

        // 9. Debiti verso fornitori: elenco fatture = mastro.
        $deb = $r['riepilogo']['debiti'];
        if ($deb['totale_fatture'] !== $deb['mastro_fornitori']) {
            $add('debiti_fornitori', 'Debiti verso fornitori: fatture aperte = mastro', 'avviso',
                "Fatture con residuo {$e($deb['totale_fatture'])}, mastro Debiti v/fornitori {$e($deb['mastro_fornitori'])}. La differenza di solito sono debiti iniziali o note di credito non ancora compensate.");
        }

        return $v;
    }

    private function esitoComplessivo(array $verifiche): string
    {
        $esiti = array_column($verifiche, 'esito');

        return in_array('errore', $esiti, true) ? 'errore' : (in_array('avviso', $esiti, true) ? 'avviso' : 'ok');
    }

    private function data(?string $d): string
    {
        return $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m/Y') : '—';
    }
}
