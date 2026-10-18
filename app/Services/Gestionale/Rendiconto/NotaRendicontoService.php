<?php

namespace App\Services\Gestionale\Rendiconto;

use App\Contracts\IA\GeneratoreTesto;
use App\Helpers\MoneyHelper;

/**
 * Nota sintetica esplicativa della gestione (art. 1130-bis c.c.).
 *
 * Regola del progetto: **il programma è la fonte dei numeri, il testo è la penna**. La nota si
 * scrive con segnaposto `{{F:chiave}}` che al momento della stampa vengono sostituiti con i
 * valori calcolati dal rendiconto; così il testo resta giusto anche se i numeri cambiano dopo
 * (una fattura registrata in ritardo) e una bozza scritta dall'IA non può contenere cifre
 * inventate.
 *
 * Il modello predefinito copre le sezioni che la legge chiede («rapporti in corso e questioni
 * pendenti») e quelle che servono all'«immediata verifica»: criteri, risultato per gestione,
 * fondi, morosità aggregata, fatti dopo la chiusura. Le parti che il programma non conosce
 * (contratti, liti, sinistri) restano da completare a mano.
 */
class NotaRendicontoService
{
    public const TIPO_DOCUMENTO_IA = 'nota_rendiconto';

    private const SEGNAPOSTO = '/\{\{F:([a-z0-9_.]+)\}\}/i';

    /**
     * I fatti del rendiconto: `chiave => [valore formattato, descrizione]`. Le chiavi per gestione
     * sono numerate nell'ordine del rendiconto (`g1` = la prima, di norma l'ordinaria).
     *
     * @return array<string, array{valore:string, descrizione:string}>
     */
    public function fatti(array $rendiconto): array
    {
        $e = fn (int $cents) => MoneyHelper::format($cents);
        $data = fn (?string $d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m/Y') : '—';
        $f = [];
        $add = function (string $chiave, string $valore, string $descrizione) use (&$f) {
            $f[$chiave] = ['valore' => $valore, 'descrizione' => $descrizione];
        };

        $es = $rendiconto['esercizio'];
        $add('es.nome', (string) $es['nome'], "Nome dell'esercizio");
        $add('es.inizio', $data($es['data_inizio']), "Inizio dell'esercizio");
        $add('es.fine', $data($es['data_fine']), "Fine dell'esercizio");

        $tot = $rendiconto['riepilogo']['totali'];
        $add('cassa.iniziale', $e($tot['saldo_iniziale']), 'Saldo iniziale di cassa, banca e fondi');
        $add('cassa.entrate', $e($tot['entrate']), "Entrate di cassa dell'esercizio (giroconti interni inclusi)");
        $add('cassa.uscite', $e($tot['uscite']), "Uscite di cassa dell'esercizio (giroconti interni inclusi)");
        $add('cassa.finale', $e($tot['saldo_finale']), 'Saldo finale di cassa, banca e fondi');

        foreach ($rendiconto['consuntivo']['gestioni'] as $i => $g) {
            $n = 'g'.($i + 1);
            $t = $g['totali'];
            $rip = $rendiconto['riparto'][$i]['totali'] ?? null;
            $add("{$n}.nome", (string) $g['nome'], 'Nome della gestione');
            $add("{$n}.preventivo", $e($t['preventivo']), "Preventivo della gestione {$g['nome']}");
            $add("{$n}.consuntivo", $e($t['consuntivo']), "Spese effettive della gestione {$g['nome']}");
            $add("{$n}.scostamento", $e($t['scostamento']), "Consuntivo meno preventivo, gestione {$g['nome']}");
            $add("{$n}.scostamento_perc", $t['scostamento_perc'] === null ? '—' : number_format($t['scostamento_perc'], 1, ',', '.').'%', "Scostamento percentuale, gestione {$g['nome']}");
            if ($rip) {
                $add("{$n}.versato", $e($rip['versato']), "Versamenti dei condòmini nell'esercizio, gestione {$g['nome']}");
                $add("{$n}.conguaglio_debito", $e($rip['a_debito']), "Conguagli a debito dei condòmini, gestione {$g['nome']}");
                $add("{$n}.conguaglio_credito", $e(abs($rip['a_credito'])), "Conguagli a credito dei condòmini, gestione {$g['nome']}");
            }
        }

        $rie = $rendiconto['riepilogo'];
        $add('crediti.totale', $e(max(0, $rie['crediti']['totale_a_debito'])), 'Rate emesse e non pagate a fine esercizio (crediti verso condòmini)');
        $add('crediti.condomini', (string) $rie['crediti']['condomini_a_debito'], 'Numero di condòmini con rate non pagate a fine esercizio');
        $add('debiti.fornitori', $e($rie['debiti']['totale_fatture']), 'Fatture dei fornitori non ancora pagate a fine esercizio');
        $add('debiti.fatture', (string) count($rie['debiti']['fatture']), 'Numero di fatture non pagate a fine esercizio');
        $add('debiti.ritenute', $e($rie['debiti']['ritenute_da_versare']), "Ritenute d'acconto operate e non ancora versate");
        $add('fondi.speciale', $e($rie['fondo_speciale']), 'Saldo del fondo speciale per lavori straordinari (art. 1135 n. 4)');
        $add('fondi.totale', $e(array_sum(array_column($rie['fondi'], 'saldo_finale'))), 'Saldo complessivo dei fondi');

        return $f;
    }

    /** Il modello predefinito, con i segnaposto. */
    public function modello(array $rendiconto): string
    {
        $righeGestioni = [];
        foreach ($rendiconto['consuntivo']['gestioni'] as $i => $g) {
            $n = 'g'.($i + 1);
            $riga = "- {{F:{$n}.nome}}: preventivo {{F:{$n}.preventivo}}, spese effettive {{F:{$n}.consuntivo}}, scostamento {{F:{$n}.scostamento}} ({{F:{$n}.scostamento_perc}}).";
            if (isset($rendiconto['riparto'][$i])) {
                $riga .= " Versamenti dei condòmini {{F:{$n}.versato}}; conguagli a debito {{F:{$n}.conguaglio_debito}}, a credito {{F:{$n}.conguaglio_credito}}.";
            }
            $righeGestioni[] = $riga;
        }
        $gestioni = $righeGestioni !== [] ? implode("\n", $righeGestioni) : '- Nessuna gestione con movimenti.';

        return <<<TXT
        CRITERI DI REDAZIONE
        Il rendiconto dell'esercizio {{F:es.nome}} (dal {{F:es.inizio}} al {{F:es.fine}}) è redatto ai sensi dell'art. 1130-bis c.c. e si compone del registro di contabilità, del riepilogo finanziario e della presente nota. Le spese sono esposte per competenza: una fattura dell'esercizio è considerata anche se pagata dopo la sua chiusura. La gestione ordinaria e ciascuna gestione straordinaria sono tenute e ripartite separatamente.

        SINTESI DEL RISULTATO
        {$gestioni}
        Gli scostamenti principali rispetto al preventivo sono dovuti a: [da completare].

        SITUAZIONE FINANZIARIA
        Il saldo di cassa, banca e fondi è passato da {{F:cassa.iniziale}} a inizio esercizio a {{F:cassa.finale}} a fine esercizio e coincide con l'estratto conto bancario alla stessa data.

        FONDI E RISERVE
        Il fondo speciale per i lavori straordinari (art. 1135 n. 4 c.c.) ammonta a {{F:fondi.speciale}}; il saldo complessivo dei fondi è {{F:fondi.totale}}.

        CREDITI E MOROSITÀ
        A fine esercizio risultano rate emesse e non pagate per {{F:crediti.totale}}, riferite a {{F:crediti.condomini}} condòmini. Azioni intraprese o dispensa dell'assemblea (art. 1129 c. 9 c.c.): [da completare].

        DEBITI
        Risultano {{F:debiti.fatture}} fatture di fornitori da pagare per {{F:debiti.fornitori}} e ritenute d'acconto da versare per {{F:debiti.ritenute}}.

        RAPPORTI IN CORSO
        [Contratti attivi (assicurazione, pulizie, manutenzioni, utenze) con le scadenze: da completare.]

        QUESTIONI PENDENTI
        [Liti, sinistri, fatture contestate o da ratificare, lavori deliberati e non conclusi, adempimenti fiscali: da completare.]

        FATTI DI RILIEVO DOPO LA CHIUSURA
        [Da completare, oppure: nessuno.]
        TXT;
    }

    /**
     * Sostituisce i segnaposto con i valori. Un segnaposto sconosciuto resta visibile e segnalato.
     *
     * @return array{testo:string, mancanti:list<string>}
     */
    public function compila(string $testo, array $fatti): array
    {
        $mancanti = [];
        $compilato = preg_replace_callback(self::SEGNAPOSTO, function ($m) use ($fatti, &$mancanti) {
            $chiave = strtolower($m[1]);
            if (isset($fatti[$chiave])) {
                return $fatti[$chiave]['valore'];
            }
            $mancanti[] = $chiave;

            return '[dato non disponibile: '.$chiave.']';
        }, $testo);

        return ['testo' => (string) $compilato, 'mancanti' => array_values(array_unique($mancanti))];
    }

    /**
     * Bozza della nota. Con l'IA configurata le si passa il contesto strutturato (solo dati
     * aggregati, nessun nome di persona) e si chiede testo con segnaposto; altrimenti, o se la
     * generazione fallisce, si torna al modello predefinito.
     *
     * @return array{testo:string, fonte:string, avvisi:list<string>, note_per_revisore?:list<string>, modello_ia?:?string, versione_prompt?:?string}
     */
    public function bozza(array $rendiconto, ?string $istruzioni = null): array
    {
        $modello = $this->modello($rendiconto);

        /** @var GeneratoreTesto|null $ia */
        $ia = app()->bound(GeneratoreTesto::class) ? app(GeneratoreTesto::class) : null;

        if (! $ia || ! $ia->disponibile()) {
            return ['testo' => $modello, 'fonte' => 'modello', 'avvisi' => ['IA non configurata: è stato usato il modello predefinito.']];
        }

        $fatti = $this->fatti($rendiconto);

        // Tutte le cifre e le date stanno SOLO in `fatti`: l'IA scrive {{F:chiave}} e i valori si
        // sostituiscono dopo. Il resto del contesto è testo senza numeri (i nomi delle gestioni e
        // delle voci stanno anch'essi nei fatti o sono descrittivi).
        $contesto = [
            // Non viene inviato: serve all'implementazione per pseudonimizzare.
            'condominio_id'   => $rendiconto['condominio']['id'] ?? null,
            'documento'       => 'Nota sintetica esplicativa del rendiconto condominiale',
            'gestioni'        => array_map(fn ($g, $i) => [
                'codice' => 'g'.($i + 1),
                'tipo'   => $g['tipo'],
                'scostamenti_principali' => $this->scostamentiPrincipali($g),
            ], $rendiconto['consuntivo']['gestioni'], array_keys($rendiconto['consuntivo']['gestioni'])),
            'fatti'           => $fatti,
            'sezioni_richieste' => [
                'Criteri di redazione', 'Sintesi del risultato', 'Situazione finanziaria', 'Fondi e riserve',
                'Crediti e morosità', 'Debiti', 'Rapporti in corso', 'Questioni pendenti', 'Fatti di rilievo dopo la chiusura',
            ],
            'regole'          => [
                'Ogni importo, data o numero va scritto solo con il segnaposto {{F:chiave}} dei fatti, mai in cifre.',
                'Le parti che i dati non coprono (contratti, liti, sinistri) restano tra parentesi quadre da completare.',
                'Gestione ordinaria e straordinarie restano separate.',
            ],
            'norme_ammesse'   => ['art. 1130-bis c.c.', 'art. 1130 c.c.', 'art. 1135 c.c.', 'art. 1129 c.c.'],
            'modello'         => $modello,
        ];

        $schema = [
            'type'       => 'object',
            'properties' => [
                'sezioni' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => ['titolo' => ['type' => 'string'], 'testo' => ['type' => 'string']],
                        'required'   => ['titolo', 'testo'],
                    ],
                ],
                'note_per_revisore' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required'   => ['sezioni', 'note_per_revisore'],
        ];

        try {
            $esito = $ia->genera(self::TIPO_DOCUMENTO_IA, $contesto, $schema, $istruzioni);
        } catch (\Throwable $e) {
            report($e);

            return ['testo' => $modello, 'fonte' => 'modello', 'avvisi' => ['La generazione non è riuscita: è stato usato il modello predefinito.']];
        }

        $testo = null;
        if ($esito->ok) {
            if (isset($esito->dati['sezioni']) && is_array($esito->dati['sezioni'])) {
                $blocchi = [];
                foreach ($esito->dati['sezioni'] as $s) {
                    $titolo = trim((string) ($s['titolo'] ?? ''));
                    $corpo = trim((string) ($s['testo'] ?? ''));
                    if ($corpo === '') continue;
                    $blocchi[] = ($titolo !== '' ? mb_strtoupper($titolo)."\n" : '').$corpo;
                }
                $testo = $blocchi !== [] ? implode("\n\n", $blocchi) : null;
            } elseif (isset($esito->dati['testo']) && is_string($esito->dati['testo'])) {
                $testo = trim($esito->dati['testo']);
            }
        }

        if (! $testo) {
            return [
                'testo'  => $modello,
                'fonte'  => 'modello',
                'avvisi' => array_values(array_filter(array_merge(
                    [$esito->errore ? 'Bozza IA non disponibile ('.$esito->errore.'): è stato usato il modello predefinito.' : 'Bozza IA vuota: è stato usato il modello predefinito.'],
                    $esito->avvisi
                ))),
            ];
        }

        $note = array_values(array_filter(array_map(
            fn ($n) => is_string($n) ? trim($n) : '',
            (array) ($esito->dati['note_per_revisore'] ?? [])
        )));

        return [
            'testo'           => $testo,
            'fonte'           => 'ia',
            'avvisi'          => array_values(array_merge(['Bozza generata con IA: va riletta e approvata prima della stampa.'], $esito->avvisi)),
            'note_per_revisore' => $note,
            'modello_ia'      => $esito->modello ?? null,
            // La proprietà può non esistere nelle versioni dell'interfaccia precedenti al ramo ia.
            'versione_prompt' => $esito->versionePrompt ?? null,
        ];
    }

    /** Le voci con lo scostamento più rilevante (oltre il 10% o senza preventivo), per il contesto. */
    private function scostamentiPrincipali(array $gestione): array
    {
        $voci = array_filter(
            array_merge($gestione['voci'], $gestione['sopravvenienze']),
            fn ($v) => $v['livello'] === 0 && $v['scostamento'] !== 0
                && ($v['scostamento_perc'] === null || abs($v['scostamento_perc']) >= 10)
        );
        usort($voci, fn ($a, $b) => abs($b['scostamento']) <=> abs($a['scostamento']));

        // Niente cifre: l'IA non deve poterne scrivere. Basta dire quale voce e in che verso.
        return array_map(fn ($v) => [
            'voce'      => $v['nome'],
            'andamento' => $v['preventivo'] === 0
                ? 'spesa non prevista a preventivo'
                : ($v['scostamento'] > 0 ? 'superiore al preventivo' : 'inferiore al preventivo'),
        ], array_slice(array_values($voci), 0, 5));
    }
}
