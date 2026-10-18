<?php

namespace App\Services\IA;

use App\Contracts\IA\GeneratoreTesto;
use App\Contracts\IA\RisultatoGenerazione;
use App\Models\UtilizzoIA;
use App\Settings\IASettings;

/**
 * Bozze generate con Claude (API Anthropic, POST /v1/messages con output strutturato).
 *
 * Passi di `genera()`:
 * 1. pseudonimizza contesto e istruzioni (`Pseudonimizzatore`, un'istanza nuova per richiesta);
 * 2. controlla il tetto di spesa mensile, nel caso peggiore, prima di chiamare;
 * 3. chiama l'API con il prompt del tipo di documento e lo schema JSON richiesto
 *    (`output_config.format`, https://platform.claude.com/docs/en/build-with-claude/structured-outputs);
 * 4. gestisce rifiuto (`stop_reason: refusal`) e risposta troncata (`max_tokens`);
 * 5. valida la bozza sul testo ancora pseudonimizzato (`ValidatoreBozza`), poi la ricompone;
 * 6. registra l'uso in `ia_utilizzi`: token, costo stimato, esito. **Mai** il testo.
 *
 * La chiamata può durare più di un minuto: chi la usa da una pagina web farebbe bene a metterla in
 * un job in coda (QUEUE_CONNECTION=database) e a interrogarne l'esito.
 */
class GeneratoreTestoAnthropic implements GeneratoreTesto
{
    /** Parole chiave dello schema che l'output strutturato non accetta (risponderebbe 400). */
    private const VINCOLI_NON_SUPPORTATI = [
        'minLength', 'maxLength', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum',
        'multipleOf', 'maxItems', 'uniqueItems', 'minProperties', 'maxProperties',
    ];

    public function __construct(
        private readonly IASettings $settings,
        private readonly ClientAnthropic $client,
        private readonly RegistroUtilizzoIA $registro,
        private readonly PromptIA $prompt,
        private readonly ValidatoreBozza $validatore,
    ) {}

    public function disponibile(): bool
    {
        return $this->settings->operativa() && ! $this->registro->tettoRaggiunto();
    }

    public function genera(string $tipoDocumento, array $contesto, array $schema, ?string $istruzioni = null): RisultatoGenerazione
    {
        $modello = trim($this->settings->modello) ?: (string) config('ia.modello_predefinito');
        $chiave = $this->settings->chiaveInChiaro();

        if (! $this->settings->ia_abilitata || $chiave === null) {
            return RisultatoGenerazione::nonDisponibile("L'assistenza IA non è attiva o la chiave API non è impostata.");
        }

        if (! $this->prompt->supportato($tipoDocumento)) {
            return new RisultatoGenerazione(ok: false, errore: "Tipo di documento non previsto per l'IA: «{$tipoDocumento}».", modello: $modello);
        }

        // 1. Pseudonimizzazione
        $pseudo = new Pseudonimizzatore();
        $contestoInviato = $pseudo->pseudonimizza($contesto);
        $istruzioniInviate = $istruzioni !== null && trim($istruzioni) !== '' ? $pseudo->pseudonimizzaTesto($istruzioni) : null;

        $sistema = $this->prompt->sistema($tipoDocumento);
        $schemaInviato = $this->normalizzaSchema($schema ?: $this->schemaPredefinito());
        $messaggio = $this->messaggioUtente($contestoInviato, $istruzioniInviate);
        $parametri = (array) (config("ia.tipi.$tipoDocumento") ?? config('ia.tipo_predefinito'));
        $maxToken = (int) ($parametri['max_tokens'] ?? 12000);

        $hash = hash('sha256', $messaggio.json_encode($schemaInviato));
        $metadati = ['tipo_documento' => $tipoDocumento, 'modello' => $modello, 'hash_contesto' => $hash];

        // 2. Tetto di spesa
        $stimaIngresso = RegistroUtilizzoIA::stimaToken($sistema['testo'].$messaggio.json_encode($schemaInviato));
        if ($this->registro->superaTetto($modello, $stimaIngresso, $maxToken)) {
            $this->registro->registra($metadati + ['esito' => UtilizzoIA::ESITO_BLOCCATA_TETTO, 'costo_microdollari' => 0]);

            return new RisultatoGenerazione(
                ok: false,
                errore: sprintf(
                    'Tetto di spesa mensile raggiunto: spesi circa $%s su $%s questo mese. Alza il tetto nelle impostazioni IA o attendi il mese prossimo.',
                    number_format($this->registro->spesaMese() / 1_000_000, 2, ',', '.'),
                    number_format(($this->registro->tettoMicrodollari() ?? 0) / 1_000_000, 2, ',', '.'),
                ),
                modello: $modello,
                versionePrompt: $sistema['versione'],
            );
        }

        // 3. Chiamata
        $corpo = [
            'model'      => $modello,
            'max_tokens' => $maxToken,
            'system'     => $sistema['testo'],
            'messages'   => [['role' => 'user', 'content' => $messaggio]],
            'output_config' => array_filter([
                'effort' => $parametri['effort'] ?? null,
                'format' => ['type' => 'json_schema', 'schema' => $schemaInviato],
            ]),
        ];

        $inizio = hrtime(true);

        try {
            $esitoChiamata = $this->client->messaggi($chiave, $corpo);
        } catch (ErroreAnthropic $e) {
            $this->registro->registra($metadati + [
                'esito'         => UtilizzoIA::ESITO_ERRORE,
                'codice_errore' => mb_substr($e->codice, 0, 50),
                'request_id'    => $e->requestId,
                'durata_ms'     => $this->durata($inizio),
            ]);

            return new RisultatoGenerazione(ok: false, errore: $e->getMessage(), modello: $modello, versionePrompt: $sistema['versione']);
        }

        $risposta = $esitoChiamata['risposta'];
        $tokenIngresso = (int) ($risposta['usage']['input_tokens'] ?? 0)
            + (int) ($risposta['usage']['cache_creation_input_tokens'] ?? 0)
            + (int) ($risposta['usage']['cache_read_input_tokens'] ?? 0);
        $tokenUscita = (int) ($risposta['usage']['output_tokens'] ?? 0);
        $modelloUsato = (string) ($risposta['model'] ?? $modello);
        $costo = $this->registro->costo($modello, $tokenIngresso, $tokenUscita);

        $metadati += [
            'token_ingresso'     => $tokenIngresso,
            'token_uscita'       => $tokenUscita,
            'costo_microdollari' => $costo,
            'request_id'         => $esitoChiamata['request_id'],
            'durata_ms'          => $this->durata($inizio),
        ];

        $fallita = function (string $esito, string $errore, ?string $codice = null) use ($metadati, $modelloUsato, $tokenIngresso, $tokenUscita, $costo, $sistema) {
            $this->registro->registra($metadati + ['esito' => $esito, 'codice_errore' => $codice]);

            return new RisultatoGenerazione(
                ok: false, errore: $errore, modello: $modelloUsato,
                tokenIngresso: $tokenIngresso, tokenUscita: $tokenUscita,
                costoStimatoUsd: $costo / 1_000_000, versionePrompt: $sistema['versione'],
            );
        };

        // 4. Rifiuto e troncamento: la risposta può non rispettare lo schema.
        $stop = $risposta['stop_reason'] ?? null;
        if ($stop === 'refusal') {
            return $fallita(UtilizzoIA::ESITO_RIFIUTO, 'Il modello ha rifiutato di generare questa bozza. Rivedi le istruzioni e le note, oppure scrivi il testo a mano.', 'refusal');
        }
        if ($stop === 'max_tokens') {
            return $fallita(UtilizzoIA::ESITO_TRONCATA, 'La risposta del modello è stata troncata perché troppo lunga. Riduci il numero di punti o le note e riprova.', 'max_tokens');
        }

        $testo = null;
        foreach ((array) ($risposta['content'] ?? []) as $blocco) {
            if (($blocco['type'] ?? null) === 'text' && isset($blocco['text'])) {
                $testo = (string) $blocco['text'];
                break;
            }
        }

        $dati = $testo !== null ? json_decode($testo, true) : null;
        if (! is_array($dati)) {
            return $fallita(UtilizzoIA::ESITO_ERRORE, 'La risposta del modello non è nel formato atteso. Riprova.', 'json_non_valido');
        }

        // 5. Validazione (sul testo pseudonimizzato) e ricomposizione
        $validazione = $this->validatore->valida($tipoDocumento, $dati, $contestoInviato, $schemaInviato, $pseudo->codiciAssegnati());

        $avvisi = $validazione->messaggi();
        $rimossi = array_sum($pseudo->residuiRimossi());
        if ($rimossi > 0) {
            $avvisi[] = "Prima dell'invio sono stati tolti dal testo {$rimossi} dati personali riconosciuti (e-mail, IBAN, codici fiscali, telefoni): non sono stati inviati.";
        }

        $this->registro->registra($metadati + [
            'esito' => $validazione->superata() ? UtilizzoIA::ESITO_OK : UtilizzoIA::ESITO_NON_VALIDA,
        ]);

        return new RisultatoGenerazione(
            ok: $validazione->superata(),
            dati: $pseudo->ricomponi($dati),
            avvisi: array_values($pseudo->ricomponi($avvisi)),
            errore: $validazione->superata() ? null : 'La bozza generata non ha superato i controlli automatici: rivedila o rigenerala.',
            modello: $modelloUsato,
            tokenIngresso: $tokenIngresso,
            tokenUscita: $tokenUscita,
            costoStimatoUsd: $costo / 1_000_000,
            versionePrompt: $sistema['versione'],
        );
    }

    /** Il messaggio con istruzioni e contesto (già pseudonimizzati). */
    private function messaggioUtente(array $contesto, ?string $istruzioni): string
    {
        $json = json_encode(
            $contesto === [] ? new \stdClass() : $contesto,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return "ISTRUZIONI DELL'UTENTE:\n".($istruzioni ?? '(nessuna)')."\n\nCONTESTO (JSON):\n".$json;
    }

    /**
     * Adatta lo schema ai limiti dell'output strutturato: `additionalProperties: false` su ogni
     * oggetto, niente vincoli di lunghezza o numerici, `minItems` solo 0 o 1. I controlli tolti
     * li fa `ValidatoreBozza`.
     */
    private function normalizzaSchema(array $schema): array
    {
        foreach (self::VINCOLI_NON_SUPPORTATI as $vincolo) {
            unset($schema[$vincolo]);
        }

        if (isset($schema['minItems']) && (int) $schema['minItems'] > 1) {
            $schema['minItems'] = 1;
        }

        if (($schema['type'] ?? null) === 'object' || isset($schema['properties'])) {
            $schema['additionalProperties'] = false;
        }

        foreach (['properties', '$defs', 'definitions'] as $chiave) {
            if (isset($schema[$chiave]) && is_array($schema[$chiave])) {
                foreach ($schema[$chiave] as $nome => $sotto) {
                    if (is_array($sotto)) {
                        $schema[$chiave][$nome] = $this->normalizzaSchema($sotto);
                    }
                }
            }
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->normalizzaSchema($schema['items']);
        }

        foreach (['anyOf', 'allOf'] as $chiave) {
            if (isset($schema[$chiave]) && is_array($schema[$chiave])) {
                $schema[$chiave] = array_map(fn ($s) => is_array($s) ? $this->normalizzaSchema($s) : $s, $schema[$chiave]);
            }
        }

        return $schema;
    }

    /** Schema di ripiego se il chiamante non ne passa uno: un testo e le note per il revisore. */
    private function schemaPredefinito(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'testo' => ['type' => 'string'],
                'note_per_revisore' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['testo', 'note_per_revisore'],
        ];
    }

    private function durata(int $inizio): int
    {
        return (int) round((hrtime(true) - $inizio) / 1_000_000);
    }
}
