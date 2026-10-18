<?php

namespace App\Services\Assemblee;

use App\Contracts\IA\GeneratoreTesto;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\PuntoOdg;
use Illuminate\Support\Carbon;

/**
 * Bozze di testo per l'avviso (formulazione dei punti) e per il verbale (discussione e dispositivo).
 *
 * ## Il principio
 *
 * **Il programma è la fonte dei fatti, l'IA è la penna.** L'IA riceve un contesto strutturato con un
 * dizionario `fatti` (chiave → valore già formattato) e scrive `{{F:chiave}}` al posto di ogni dato;
 * i segnaposto li sostituisce questo servizio. Esiti, quorum, millesimi e dichiarazioni a verbale non
 * passano mai dall'IA: li compone il modello Blade del verbale.
 *
 * La bozza **non si salva da sola**: torna alla schermata, l'utente la legge, la corregge e salva.
 *
 * Senza IA (`GeneratoreTesto::disponibile() === false`, oppure errore) si usano modelli fissi: il
 * modulo funziona per intero anche così.
 */
class BozzeAssembleaService
{
    public const TIPO_CONVOCAZIONE = 'convocazione';
    public const TIPO_VERBALE = 'verbale';

    public function __construct(
        private GeneratoreTesto $ia,
        private RiepilogoAssembleaService $riepilogo,
    ) {}

    public function iaDisponibile(): bool
    {
        return $this->ia->disponibile();
    }

    /** @return array{fonte:string, punti:list<array>, avvisi:list<string>, note_per_revisore:list<string>} */
    public function convocazione(Assemblea $assemblea): array
    {
        return $this->genera($assemblea, self::TIPO_CONVOCAZIONE);
    }

    /** @return array{fonte:string, punti:list<array>, avvisi:list<string>, note_per_revisore:list<string>} */
    public function verbale(Assemblea $assemblea): array
    {
        return $this->genera($assemblea, self::TIPO_VERBALE);
    }

    /**
     * Chiede la bozza al generatore (se disponibile), la valida, sostituisce i segnaposto e annota
     * sull'assemblea fonte, modello e versione del prompt. In ogni caso di errore: modello fisso.
     */
    private function genera(Assemblea $assemblea, string $tipo): array
    {
        $assemblea->loadMissing(['punti', 'condominio']);
        $punti = $assemblea->punti->values();
        $campi = $tipo === self::TIPO_VERBALE ? ['discussione', 'testo_delibera'] : ['titolo', 'testo'];
        $modello = fn (array $avvisi) => $tipo === self::TIPO_VERBALE
            ? $this->modelloVerbale($assemblea, $avvisi)
            : $this->modelloConvocazione($assemblea, $avvisi);

        if (! $this->ia->disponibile() || $punti->isEmpty()) {
            return $this->annota($assemblea, $tipo, $modello([]), null, null);
        }

        $contesto = $this->contesto($assemblea, $tipo);
        $schema = $tipo === self::TIPO_VERBALE ? $this->schemaVerbale() : $this->schemaConvocazione();
        $istruzioni = $tipo === self::TIPO_VERBALE ? $this->istruzioniVerbale() : $this->istruzioniConvocazione();

        try {
            $risultato = $this->ia->genera($tipo, $contesto, $schema, $istruzioni);
        } catch (\Throwable $e) {
            report($e);

            return $this->annota($assemblea, $tipo, $modello(['Bozza IA non disponibile (errore del servizio): usato il modello fisso.']), null, null);
        }

        $nomeModello = $risultato->modello ?? null;
        $versionePrompt = $risultato->versionePrompt ?? null;

        if (! $risultato->ok) {
            return $this->annota($assemblea, $tipo, $modello(array_merge(
                ['Bozza IA non disponibile ('.($risultato->errore ?? 'errore').'): usato il modello fisso.'],
                $risultato->avvisi
            )), $nomeModello, $versionePrompt);
        }

        $avvisi = $risultato->avvisi;
        $prodotti = $this->allinea($punti, $risultato->dati['punti'] ?? [], $avvisi);

        if ($prodotti === null) {
            return $this->annota($assemblea, $tipo, $modello($avvisi), $nomeModello, $versionePrompt);
        }

        $bozza = [
            'fonte' => 'ia',
            'punti' => $punti->map(function (PuntoOdg $p, $i) use ($prodotti, $campi, $contesto, &$avvisi) {
                $voce = ['id' => $p->id, 'n' => $i + 1];
                foreach ($campi as $campo) {
                    $originale = $campo === 'titolo' ? $p->titolo : '';
                    $voce[$campo] = $this->sostituisci((string) ($prodotti[$i + 1][$campo] ?? $originale), $contesto['fatti'], $avvisi, $i + 1);
                }

                return $voce;
            })->all(),
            'avvisi' => [],
            'note_per_revisore' => array_values((array) ($risultato->dati['note_per_revisore'] ?? [])),
        ];
        $bozza['avvisi'] = array_values(array_unique($avvisi));

        return $this->annota($assemblea, $tipo, $bozza, $nomeModello, $versionePrompt);
    }

    /** Traccia sull'assemblea l'ultima bozza generata per tipo (non il testo: quello lo salva l'utente). */
    private function annota(Assemblea $assemblea, string $tipo, array $bozza, ?string $modello, ?string $versionePrompt): array
    {
        $bozza['modello'] = $modello;
        $bozza['versione_prompt'] = $versionePrompt;
        $bozza['generata_il'] = now()->toIso8601String();

        $traccia = (array) ($assemblea->bozze_ia ?? []);
        $traccia[$tipo] = [
            'fonte' => $bozza['fonte'],
            'modello' => $modello,
            'versione_prompt' => $versionePrompt,
            'generata_il' => $bozza['generata_il'],
            'avvisi' => $bozza['avvisi'],
            'utente_id' => auth()->id(),
        ];
        $assemblea->forceFill(['bozze_ia' => $traccia])->saveQuietly();

        return $bozza;
    }

    /**
     * Il contesto inviato al generatore, nel formato concordato con l'area IA:
     * `condominio_id` (non inviato: serve a pseudonimizzare i nomi), `punti` con `n` e `testo_utente`,
     * `note_seduta` (solo verbale), `fatti` (le sole cifre e date, che l'IA richiama con `{{F:chiave}}`),
     * `norme_ammesse`. Niente codici fiscali, recapiti o indirizzi delle persone.
     */
    public function contesto(Assemblea $assemblea, string $tipo): array
    {
        $assemblea->loadMissing(['punti', 'condominio']);
        $fatti = [];
        $fatto = function (string $chiave, ?string $valore, string $descrizione) use (&$fatti) {
            if ($valore !== null && $valore !== '') {
                $fatti[$chiave] = ['valore' => $valore, 'descrizione' => $descrizione];
            }
        };

        $fatto('ass.tipo', $assemblea->tipo?->label(), 'Tipo di assemblea');
        $fatto('ass.data_prima', $this->dataLunga($assemblea->prima_convocazione_at), 'Data e ora della prima convocazione');
        $fatto('ass.luogo_prima', $assemblea->prima_luogo, 'Luogo della prima convocazione');
        $fatto('ass.data_seconda', $this->dataLunga($assemblea->seconda_convocazione_at), 'Data e ora della seconda convocazione');
        $fatto('ass.luogo_seconda', $assemblea->seconda_luogo ?: $assemblea->prima_luogo, 'Luogo della seconda convocazione');

        $punti = [];
        $note = [];
        foreach ($assemblea->punti->values() as $i => $p) {
            $n = $i + 1;
            $regola = $p->materia?->regola($assemblea->convocazioneEffettiva());
            $fatto("p{$n}.norma", $p->materia?->norma(), "Norma applicabile al punto {$n}");
            $fatto("p{$n}.maggioranza", $regola?->descrizione, "Maggioranza richiesta per il punto {$n}");

            $punti[] = [
                'n' => $n,
                'testo_utente' => trim($p->titolo."\n".(string) $p->testo),
                'titolo_utente' => $p->titolo,
                'materia' => $p->materia?->label(),
            ];

            if ($tipo === self::TIPO_VERBALE) {
                $note[(string) $n] = (string) $p->discussione;
                $esito = $this->riepilogo->punto($assemblea, $p)['esito'];
                $fatto("p{$n}.favorevoli_teste", (string) ($esito['favorevoli']['teste'] ?? 0), "Numero di condòmini favorevoli al punto {$n}");
                $fatto("p{$n}.favorevoli_millesimi", $esito['favorevoli']['valore_testo'] ?? null, "Millesimi favorevoli al punto {$n}");
                $fatto("p{$n}.contrari_teste", (string) ($esito['contrari']['teste'] ?? 0), "Numero di condòmini contrari al punto {$n}");
            }
        }

        if ($tipo === self::TIPO_VERBALE) {
            $fatto('ass.data_svolgimento', $this->dataLunga($assemblea->dataSvolgimento()), 'Data e ora dell\'adunanza');
            $fatto('ass.luogo_svolgimento', $assemblea->luogoSvolgimento(), 'Luogo dell\'adunanza');
        }

        $contesto = [
            'condominio_id' => $assemblea->condominio_id,
            'tipo_assemblea' => $assemblea->tipo?->value,
            'modalita' => $assemblea->videoconferenza ? 'presenza e videoconferenza' : 'presenza',
            'convocazione' => $assemblea->convocazioneEffettiva()->value,
            'punti' => $punti,
            'fatti' => $fatti,
            'norme_ammesse' => ['art. 1117-ter c.c.', 'art. 1120 c.c.', 'art. 1129 c.c.', 'art. 1130 c.c.', 'art. 1130-bis c.c.',
                'art. 1135 c.c.', 'art. 1136 c.c.', 'art. 1137 c.c.', 'art. 66 disp. att. c.c.', 'art. 67 disp. att. c.c.', 'art. 69 disp. att. c.c.'],
        ];

        if ($tipo === self::TIPO_VERBALE) {
            $contesto['note_seduta'] = $note;
        }

        return $contesto;
    }

    /** Il contesto come verrà inviato, senza le chiavi di servizio (`condominio_id`, `_…`): per l'anteprima. */
    public function anteprimaContesto(Assemblea $assemblea, string $tipo): array
    {
        $contesto = $this->contesto($assemblea, $tipo);

        return array_filter($contesto, fn ($chiave) => $chiave !== 'condominio_id' && ! str_starts_with((string) $chiave, '_'), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Sostituisce i segnaposto `{{F:chiave}}`. Quelli sconosciuti diventano un marcatore visibile.
     * Segnala le cifre scritte fuori dai segnaposto (escluse quelle degli articoli di legge).
     */
    public function sostituisci(string $testo, array $fatti, array &$avvisi, ?int $punto = null): string
    {
        $senzaSegnaposto = preg_replace('/\{\{F:[^}]*\}\}/', '', $testo);
        $senzaNorme = preg_replace('/\b(art(t)?\.|articol[oi]|n\.|c\.|comma)\s*[\d\-\s,e]+(bis|ter|quater)?/iu', '', (string) $senzaSegnaposto);

        if (preg_match('/\d/', (string) $senzaNorme)) {
            $avvisi[] = 'Punto '.($punto ?? '?').': nel testo generato ci sono cifre fuori dai segnaposto. Verificarle con attenzione.';
        }

        return (string) preg_replace_callback('/\{\{F:([^}]+)\}\}/', function ($m) use ($fatti, &$avvisi, $punto) {
            $chiave = trim($m[1]);

            if (isset($fatti[$chiave])) {
                return (string) $fatti[$chiave]['valore'];
            }

            $avvisi[] = 'Punto '.($punto ?? '?').": segnaposto sconosciuto «{$chiave}».";

            return '[[DA COMPLETARE: '.$chiave.']]';
        }, $testo);
    }

    /**
     * Controlla che l'IA abbia restituito esattamente i punti richiesti, nello stesso ordine.
     *
     * @return array<int, array>|null indicizzati per numero di punto; null se non coincidono
     */
    private function allinea($punti, array $prodotti, array &$avvisi): ?array
    {
        $perNumero = [];
        foreach ($prodotti as $p) {
            if (is_array($p) && isset($p['n'])) {
                $perNumero[(int) $p['n']] = $p;
            }
        }

        $attesi = range(1, $punti->count());
        $ricevuti = array_keys($perNumero);
        sort($ricevuti);

        if ($ricevuti !== $attesi) {
            $avvisi[] = 'La bozza IA non rispetta il numero o l\'ordine dei punti dell\'ordine del giorno: usato il modello fisso.';

            return null;
        }

        return $perNumero;
    }

    private function modelloConvocazione(Assemblea $assemblea, array $avvisi): array
    {
        return [
            'fonte' => 'modello',
            'punti' => $assemblea->punti->values()->map(fn (PuntoOdg $p, $i) => [
                'id' => $p->id,
                'n' => $i + 1,
                'titolo' => $p->titolo,
                'testo' => filled($p->testo) ? $p->testo : $this->testoPredefinitoPunto($p),
            ])->all(),
            'avvisi' => $avvisi,
            'note_per_revisore' => [],
        ];
    }

    private function modelloVerbale(Assemblea $assemblea, array $avvisi): array
    {
        return [
            'fonte' => 'modello',
            'punti' => $assemblea->punti->values()->map(fn (PuntoOdg $p, $i) => [
                'id' => $p->id,
                'n' => $i + 1,
                'discussione' => filled($p->discussione) ? $p->discussione : 'Il presidente illustra il punto all\'ordine del giorno. Si apre la discussione.',
                'testo_delibera' => filled($p->testo_delibera) ? $p->testo_delibera : $this->dispositivoPredefinito($p),
            ])->all(),
            'avvisi' => $avvisi,
            'note_per_revisore' => [],
        ];
    }

    private function testoPredefinitoPunto(PuntoOdg $p): string
    {
        return match ($p->materia?->value) {
            'rendiconto_preventivo' => 'Esame e approvazione del rendiconto consuntivo e del bilancio preventivo con i relativi riparti. I documenti sono a disposizione dei condòmini per la consultazione prima dell\'assemblea.',
            'nomina_amministratore' => 'Nomina, conferma o revoca dell\'amministratore e determinazione del relativo compenso (art. 1129 c. 14 c.c.).',
            'informativa' => 'Comunicazioni dell\'amministratore. Non sono previste deliberazioni.',
            default => 'Discussione e deliberazione in merito a: '.$p->titolo.'.',
        };
    }

    private function dispositivoPredefinito(PuntoOdg $p): string
    {
        return match ($p->materia?->value) {
            'informativa' => 'L\'assemblea prende atto delle comunicazioni.',
            'rendiconto_preventivo' => 'L\'assemblea, esaminati il rendiconto e il preventivo con i relativi riparti, delibera in merito alla loro approvazione.',
            'nomina_amministratore' => 'L\'assemblea delibera in merito alla nomina dell\'amministratore, con il compenso indicato nella proposta.',
            default => 'L\'assemblea delibera in merito a: '.$p->titolo.'.',
        };
    }

    private function dataLunga(?Carbon $data): ?string
    {
        return $data ? $data->locale('it')->translatedFormat('l j F Y, \o\r\e H:i') : null;
    }

    private function schemaConvocazione(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['punti', 'note_per_revisore'],
            'properties' => [
                'punti' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['n', 'titolo', 'testo'],
                        'properties' => [
                            'n' => ['type' => 'integer'],
                            'titolo' => ['type' => 'string', 'description' => 'Titolo breve e specifico del punto, senza cifre'],
                            'testo' => ['type' => 'string', 'description' => 'Descrizione chiara del punto, con segnaposto {{F:...}} per ogni dato'],
                        ],
                    ],
                ],
                'note_per_revisore' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }

    private function schemaVerbale(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['punti', 'note_per_revisore'],
            'properties' => [
                'punti' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['n', 'discussione', 'testo_delibera'],
                        'properties' => [
                            'n' => ['type' => 'integer'],
                            'discussione' => ['type' => 'string', 'description' => 'Sintesi delle note di seduta, senza cifre e senza aggiungere interventi'],
                            'testo_delibera' => ['type' => 'string', 'description' => 'Dispositivo («L\'assemblea delibera di …») con segnaposto {{F:...}}, senza dichiarare l\'esito'],
                        ],
                    ],
                ],
                'note_per_revisore' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }

    private function istruzioniConvocazione(): string
    {
        return 'Riformula in modo chiaro e specifico ciascun punto dell\'ordine del giorno, senza aggiungerne, '
            .'toglierne o cambiarne l\'ordine. Non scrivere cifre, date o importi: usa i segnaposto {{F:chiave}} '
            .'presenti in fatti. Non introdurre argomenti generici come «varie ed eventuali» con spese.';
    }

    private function istruzioniVerbale(): string
    {
        return 'Per ogni punto scrivi la sintesi della discussione usando solo le note di seduta, senza inventare '
            .'interventi, e il dispositivo della delibera. Non dichiarare esiti né quorum: li inserisce il programma. '
            .'Non scrivere cifre: usa i segnaposto {{F:chiave}}. Le dichiarazioni a verbale non vanno riformulate.';
    }
}
