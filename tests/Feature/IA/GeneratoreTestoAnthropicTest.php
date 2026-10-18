<?php

/**
 * La generazione delle bozze con l'API Anthropic, senza rete (Http::fake).
 *
 * Copre: richiesta con output strutturato e header giusti, ricomposizione dei nomi, errori HTTP e
 * di rete con messaggi in italiano, nuovi tentativi solo sugli errori temporanei, rifiuto e
 * troncamento, registro dell'uso senza testo, tetto di spesa mensile, scelta dell'implementazione
 * nel container.
 */

use App\Contracts\IA\GeneratoreTesto;
use App\Models\UtilizzoIA;
use App\Services\IA\GeneratoreTestoAnthropic;
use App\Services\IA\GeneratoreTestoNullo;
use App\Settings\IASettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

const CHIAVE_PROVA_IA = 'sk-ant-api03-chiave-di-prova-0000000000XYZW';
const URL_MESSAGGI_IA = 'https://api.anthropic.com/v1/messages';

beforeEach(function () {
    Sleep::fake();
    Http::preventStrayRequests();
});

function attivaIAPerGenerazione(array $modifiche = []): IASettings
{
    $settings = app(IASettings::class);
    $settings->ia_abilitata = true;
    $settings->api_key = Crypt::encryptString(CHIAVE_PROVA_IA);
    $settings->modello = 'claude-sonnet-5-5';
    $settings->tetto_spesa_mensile_centesimi = null;
    foreach ($modifiche as $campo => $valore) {
        $settings->{$campo} = $valore;
    }
    $settings->save();

    return $settings;
}

function contestoVerbaleDiProva(): array
{
    return [
        'soggetti' => [
            ['nome' => 'Mario', 'cognome' => 'Rossi', 'codice_fiscale' => 'TSTTST80A01H501Z', 'email' => 'mario.rossi@test.it', 'ruolo' => 'proprietario'],
            ['nome' => 'Anna', 'cognome' => 'Bianchi', 'iban' => 'DE89370400440532013000', 'ruolo' => 'proprietario'],
        ],
        'punti' => [
            ['n' => 1, 'testo_utente' => 'approvazione consuntivo'],
            ['n' => 2, 'testo_utente' => 'pulizie scale'],
        ],
        'note_seduta' => [
            '1' => 'Rossi chiede spiegazioni sulle pulizie, Anna Bianchi risponde.',
            '2' => 'Si propone di cambiare la ditta Pulizie Verdi Srl.',
        ],
        'fornitori' => [['ragione_sociale' => 'Pulizie Verdi Srl']],
        'fatti' => [
            'rend.totale' => ['valore' => '12.480,00 €', 'descrizione' => 'Spese ordinarie'],
            'pul.nuovo_canone' => ['valore' => '150,00 €', 'descrizione' => 'Nuovo canone mensile'],
        ],
        'norme_ammesse' => ['art. 1130-bis c.c.'],
    ];
}

function schemaVerbaleDiProva(): array
{
    return [
        'type' => 'object',
        'required' => ['punti', 'note_per_revisore'],
        'properties' => [
            'punti' => [
                'type' => 'array',
                'minItems' => 2,
                'items' => [
                    'type' => 'object',
                    'required' => ['n', 'discussione', 'testo_delibera'],
                    'properties' => [
                        'n' => ['type' => 'integer'],
                        'discussione' => ['type' => 'string', 'maxLength' => 2000],
                        'testo_delibera' => ['type' => 'string'],
                    ],
                ],
            ],
            'note_per_revisore' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ];
}

function rispostaClaudeDiProva(array $dati, array $altro = []): array
{
    return array_merge([
        'id'          => 'msg_prova',
        'type'        => 'message',
        'role'        => 'assistant',
        'model'       => 'claude-sonnet-5-5',
        'content'     => [['type' => 'text', 'text' => json_encode($dati, JSON_UNESCAPED_UNICODE)]],
        'stop_reason' => 'end_turn',
        'usage'       => ['input_tokens' => 1000, 'output_tokens' => 500],
    ], $altro);
}

function bozzaVerbaleCorretta(): array
{
    return [
        'punti' => [
            ['n' => 1, 'discussione' => '[PERSONA_1] chiede spiegazioni sulle spese di pulizia, pari a {{F:rend.totale}}; [PERSONA_2] risponde.', 'testo_delibera' => "L'assemblea delibera di approvare il rendiconto ai sensi dell'art. 1130-bis c.c."],
            ['n' => 2, 'discussione' => 'Si propone di sostituire [AZIENDA_1].', 'testo_delibera' => "L'assemblea delibera di affidare il servizio con un canone di {{F:pul.nuovo_canone}}."],
        ],
        'note_per_revisore' => [],
    ];
}

it('genera una bozza con output strutturato e la ricompone', function () {
    attivaIAPerGenerazione();
    Http::fake([URL_MESSAGGI_IA => Http::response(rispostaClaudeDiProva(bozzaVerbaleCorretta()), 200, ['request-id' => 'req_prova'])]);

    $risultato = app(GeneratoreTesto::class)->genera('verbale', contestoVerbaleDiProva(), schemaVerbaleDiProva(), 'Tono sobrio.');

    expect($risultato->ok)->toBeTrue()
        ->and($risultato->errore)->toBeNull()
        ->and($risultato->avvisi)->toBe([])
        ->and($risultato->modello)->toBe('claude-sonnet-5-5')
        ->and($risultato->tokenIngresso)->toBe(1000)
        ->and($risultato->tokenUscita)->toBe(500)
        ->and($risultato->costoStimatoUsd)->toBe(0.007)
        ->and($risultato->versionePrompt)->toBe('sistema.v1+verbale.v1')
        ->and($risultato->dati['punti'][0]['discussione'])->toBe('Mario Rossi chiede spiegazioni sulle spese di pulizia, pari a {{F:rend.totale}}; Anna Bianchi risponde.')
        ->and($risultato->dati['punti'][1]['discussione'])->toBe('Si propone di sostituire Pulizie Verdi Srl.');

    Http::assertSent(function (Request $richiesta) {
        $schema = $richiesta['output_config']['format']['schema'];

        return $richiesta->url() === URL_MESSAGGI_IA
            && $richiesta->method() === 'POST'
            && $richiesta->hasHeader('x-api-key', CHIAVE_PROVA_IA)
            && $richiesta->hasHeader('anthropic-version', '2023-06-01')
            && $richiesta['model'] === 'claude-sonnet-5-5'
            && $richiesta['max_tokens'] === 16000
            && $richiesta['output_config']['effort'] === 'medium'
            && $richiesta['output_config']['format']['type'] === 'json_schema'
            // Lo schema è adattato ai limiti dell'output strutturato.
            && $schema['additionalProperties'] === false
            && $schema['properties']['punti']['items']['additionalProperties'] === false
            && ! isset($schema['properties']['punti']['items']['properties']['discussione']['maxLength'])
            && $schema['properties']['punti']['minItems'] === 1
            && str_contains($richiesta['system'], 'REGOLE INDEROGABILI')
            && str_contains($richiesta['system'], 'verbale di assemblea')
            && $richiesta['messages'][0]['role'] === 'user';
    });
});

it('non invia nomi, codici fiscali, IBAN ed e-mail', function () {
    attivaIAPerGenerazione();
    Http::fake([URL_MESSAGGI_IA => Http::response(rispostaClaudeDiProva(bozzaVerbaleCorretta()))]);

    app(GeneratoreTesto::class)->genera('verbale', contestoVerbaleDiProva(), schemaVerbaleDiProva(), 'Il sig. Rossi preferisce un tono sobrio. Copia a mario.rossi@test.it.');

    Http::assertSent(function (Request $richiesta) {
        $corpo = $richiesta->body();

        foreach (['Rossi', 'Bianchi', 'Mario', 'Pulizie Verdi', 'TSTTST', 'DE8937', 'mario.rossi', '@test.it'] as $vietato) {
            if (str_contains($corpo, $vietato)) {
                return false;
            }
        }

        $messaggio = $richiesta['messages'][0]['content'];

        return str_contains($messaggio, '[PERSONA_1]')
            && str_contains($messaggio, '[AZIENDA_1]')
            && str_contains($messaggio, '[EMAIL_RIMOSSA]')
            && str_contains($messaggio, 'Il sig. [PERSONA_1] preferisce');
    });
});

it('registra l\'uso con token e costo, senza alcun testo', function () {
    attivaIAPerGenerazione();
    Http::fake([URL_MESSAGGI_IA => Http::response(rispostaClaudeDiProva(bozzaVerbaleCorretta()), 200, ['request-id' => 'req_prova'])]);

    app(GeneratoreTesto::class)->genera('verbale', contestoVerbaleDiProva(), schemaVerbaleDiProva());

    $utilizzo = UtilizzoIA::sole();

    expect($utilizzo->tipo_documento)->toBe('verbale')
        ->and($utilizzo->modello)->toBe('claude-sonnet-5-5')
        ->and($utilizzo->token_ingresso)->toBe(1000)
        ->and($utilizzo->token_uscita)->toBe(500)
        ->and($utilizzo->costo_microdollari)->toBe(7000) // 1000 × $2 + 500 × $10 per milione
        ->and($utilizzo->esito)->toBe(UtilizzoIA::ESITO_OK)
        ->and($utilizzo->request_id)->toBe('req_prova')
        ->and($utilizzo->hash_contesto)->toHaveLength(64);

    $riga = json_encode($utilizzo->getAttributes(), JSON_UNESCAPED_UNICODE);
    foreach (['Rossi', 'PERSONA', 'pulizie', 'chiede', 'delibera', CHIAVE_PROVA_IA] as $vietato) {
        expect($riga)->not->toContain($vietato);
    }
});

it('segnala le cifre scritte fuori dai segnaposto', function () {
    attivaIAPerGenerazione();
    $bozza = bozzaVerbaleCorretta();
    $bozza['punti'][1]['testo_delibera'] = "L'assemblea delibera di affidare il servizio per 1.800 euro l'anno.";
    Http::fake([URL_MESSAGGI_IA => Http::response(rispostaClaudeDiProva($bozza))]);

    $risultato = app(GeneratoreTesto::class)->genera('verbale', contestoVerbaleDiProva(), schemaVerbaleDiProva());

    expect($risultato->ok)->toBeTrue()
        ->and($risultato->avvisi)->toHaveCount(1)
        ->and($risultato->avvisi[0])->toContain('punti.1.testo_delibera')
        ->and($risultato->avvisi[0])->toContain('cifre fuori dai segnaposto');
});

it('scarta la bozza con un numero di punti diverso o segnaposto inesistenti', function () {
    attivaIAPerGenerazione();
    $bozza = bozzaVerbaleCorretta();
    $bozza['punti'][] = ['n' => 3, 'discussione' => 'Punto inventato con {{F:fondo.inventato}}.', 'testo_delibera' => '…'];
    Http::fake([URL_MESSAGGI_IA => Http::response(rispostaClaudeDiProva($bozza))]);

    $risultato = app(GeneratoreTesto::class)->genera('verbale', contestoVerbaleDiProva(), schemaVerbaleDiProva());

    expect($risultato->ok)->toBeFalse()
        ->and($risultato->errore)->toContain('non ha superato i controlli')
        ->and($risultato->dati)->not->toBe([])
        ->and(implode(' ', $risultato->avvisi))->toContain('3 punti invece dei 2')
        ->and(implode(' ', $risultato->avvisi))->toContain('{{F:fondo.inventato}}');

    expect(UtilizzoIA::sole()->esito)->toBe(UtilizzoIA::ESITO_NON_VALIDA);
});

it('spiega in italiano una chiave non valida, senza ritentare', function () {
    attivaIAPerGenerazione();
    Http::fake([URL_MESSAGGI_IA => Http::response(
        ['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key'], 'request_id' => 'req_401'],
        401,
        ['request-id' => 'req_401'],
    )]);

    $risultato = app(GeneratoreTesto::class)->genera('verbale', contestoVerbaleDiProva(), schemaVerbaleDiProva());

    expect($risultato->ok)->toBeFalse()
        ->and($risultato->errore)->toContain('La chiave API non è valida')
        ->and($risultato->errore)->toContain('req_401');

    Http::assertSentCount(1);
    expect(UtilizzoIA::sole())
        ->esito->toBe(UtilizzoIA::ESITO_ERRORE)
        ->codice_errore->toBe('http_401:authentication_error')
        ->costo_microdollari->toBe(0);
});

it('non ritenta un 429 senza retry-after (limite di spesa della Console)', function () {
    attivaIAPerGenerazione();
    Http::fake([URL_MESSAGGI_IA => Http::response(['type' => 'error', 'error' => ['type' => 'rate_limit_error', 'message' => 'spend cap']], 429)]);

    $risultato = app(GeneratoreTesto::class)->genera('verbale', contestoVerbaleDiProva(), schemaVerbaleDiProva());

    expect($risultato->ok)->toBeFalse()
        ->and($risultato->errore)->toContain('Limite di richieste o di spesa');
    Http::assertSentCount(1);
});

it('ritenta un 429 con retry-after e un 529, poi riesce', function () {
    attivaIAPerGenerazione();
    Http::fakeSequence(URL_MESSAGGI_IA)
        ->push(['type' => 'error', 'error' => ['type' => 'rate_limit_error', 'message' => 'slow down']], 429, ['retry-after' => '3'])
        ->push(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'overloaded']], 529)
        ->push(rispostaClaudeDiProva(bozzaVerbaleCorretta()));

    $risultato = app(GeneratoreTesto::class)->genera('verbale', contestoVerbaleDiProva(), schemaVerbaleDiProva());

    expect($risultato->ok)->toBeTrue();
    Http::assertSentCount(3);
    Sleep::assertSleptTimes(2);
});

it('dopo i nuovi tentativi su un 500 si arrende con un messaggio chiaro', function () {
    attivaIAPerGenerazione();
    Http::fake([URL_MESSAGGI_IA => Http::response(['type' => 'error', 'error' => ['type' => 'api_error', 'message' => 'boom']], 500)]);

    $risultato = app(GeneratoreTesto::class)->genera('verbale', contestoVerbaleDiProva(), schemaVerbaleDiProva());

    expect($risultato->ok)->toBeFalse()
        ->and($risultato->errore)->toContain('problema temporaneo');
    Http::assertSentCount(3);
});

it('gestisce la rete assente', function () {
    attivaIAPerGenerazione();
    Http::fake([URL_MESSAGGI_IA => Http::failedConnection()]);

    $risultato = app(GeneratoreTesto::class)->genera('verbale', contestoVerbaleDiProva(), schemaVerbaleDiProva());

    expect($risultato->ok)->toBeFalse()
        ->and($risultato->errore)->toContain('Impossibile contattare api.anthropic.com');
    expect(UtilizzoIA::sole()->codice_errore)->toBe('rete');
});

it('tratta il rifiuto e la risposta troncata come bozze non utilizzabili', function (string $stop, string $esito, string $messaggio) {
    attivaIAPerGenerazione();
    Http::fake([URL_MESSAGGI_IA => Http::response(rispostaClaudeDiProva(['punti' => []], ['stop_reason' => $stop]))]);

    $risultato = app(GeneratoreTesto::class)->genera('verbale', contestoVerbaleDiProva(), schemaVerbaleDiProva());

    expect($risultato->ok)->toBeFalse()
        ->and($risultato->dati)->toBe([])
        ->and($risultato->errore)->toContain($messaggio)
        ->and($risultato->tokenUscita)->toBe(500);
    // I token del rifiuto si pagano lo stesso: entrano nella spesa.
    expect(UtilizzoIA::sole())->esito->toBe($esito)->costo_microdollari->toBe(7000);
})->with([
    'rifiuto'    => ['refusal', UtilizzoIA::ESITO_RIFIUTO, 'ha rifiutato'],
    'troncata'   => ['max_tokens', UtilizzoIA::ESITO_TRONCATA, 'troncata'],
]);

it('rifiuta una risposta che non è JSON', function () {
    attivaIAPerGenerazione();
    Http::fake([URL_MESSAGGI_IA => Http::response(rispostaClaudeDiProva([], ['content' => [['type' => 'text', 'text' => 'non è json']]]))]);

    $risultato = app(GeneratoreTesto::class)->genera('verbale', contestoVerbaleDiProva(), schemaVerbaleDiProva());

    expect($risultato->ok)->toBeFalse()->and($risultato->errore)->toContain('formato atteso');
});

it('rifiuta un tipo di documento senza prompt, senza chiamare', function () {
    attivaIAPerGenerazione();
    Http::fake();

    $risultato = app(GeneratoreTesto::class)->genera('../../.env', [], []);

    expect($risultato->ok)->toBeFalse()->and($risultato->errore)->toContain('Tipo di documento non previsto');
    Http::assertNothingSent();
});

it('ha un prompt per ciascuno dei quattro tipi di documento', function (string $tipo) {
    attivaIAPerGenerazione();
    Http::fake([URL_MESSAGGI_IA => Http::response(rispostaClaudeDiProva(['testo' => 'Testo senza cifre.', 'note_per_revisore' => []]))]);

    $risultato = app(GeneratoreTesto::class)->genera($tipo, ['fatti' => []], []);

    expect($risultato->ok)->toBeTrue()->and($risultato->versionePrompt)->toBe("sistema.v1+{$tipo}.v1");
})->with(['convocazione', 'verbale', 'nota_preventivo', 'nota_rendiconto']);

// ─── Tetto di spesa ─────────────────────────────────────────────────────────

it('blocca la chiamata che farebbe superare il tetto mensile', function () {
    attivaIAPerGenerazione(['tetto_spesa_mensile_centesimi' => 10]); // $0,10
    UtilizzoIA::create(['tipo_documento' => 'verbale', 'modello' => 'claude-sonnet-5-5', 'costo_microdollari' => 50_000, 'esito' => 'ok']);
    Http::fake();

    // Nel caso peggiore (16.000 token d'uscita × $10/M = $0,16) si supererebbe il tetto.
    $generatore = app(GeneratoreTesto::class);
    $risultato = $generatore->genera('verbale', contestoVerbaleDiProva(), schemaVerbaleDiProva());

    expect($risultato->ok)->toBeFalse()
        ->and($risultato->errore)->toContain('Tetto di spesa mensile raggiunto')
        ->and($generatore->disponibile())->toBeTrue(); // il tetto non è ancora raggiunto, solo insufficiente

    Http::assertNothingSent();
    expect(UtilizzoIA::latest('id')->first())->esito->toBe(UtilizzoIA::ESITO_BLOCCATA_TETTO)->costo_microdollari->toBe(0);
});

it('a tetto raggiunto il generatore si dichiara non disponibile', function () {
    attivaIAPerGenerazione(['tetto_spesa_mensile_centesimi' => 5]);
    UtilizzoIA::create(['tipo_documento' => 'verbale', 'modello' => 'claude-sonnet-5-5', 'costo_microdollari' => 50_000, 'esito' => 'ok']);

    expect(app(GeneratoreTesto::class)->disponibile())->toBeFalse();
});

it('conta solo la spesa del mese in corso', function () {
    attivaIAPerGenerazione(['tetto_spesa_mensile_centesimi' => 100]); // $1
    $vecchio = UtilizzoIA::create(['tipo_documento' => 'verbale', 'modello' => 'claude-sonnet-5-5', 'costo_microdollari' => 5_000_000, 'esito' => 'ok']);
    $vecchio->forceFill(['created_at' => now()->subMonthNoOverflow()->startOfMonth()])->save();
    Http::fake([URL_MESSAGGI_IA => Http::response(rispostaClaudeDiProva(bozzaVerbaleCorretta()))]);

    $risultato = app(GeneratoreTesto::class)->genera('verbale', contestoVerbaleDiProva(), schemaVerbaleDiProva());

    expect($risultato->ok)->toBeTrue();
    Http::assertSentCount(1);
});

// ─── Scelta dell'implementazione ─────────────────────────────────────────────

it('usa il generatore nullo finché l\'IA è spenta o senza chiave leggibile', function () {
    expect(app(GeneratoreTesto::class))->toBeInstanceOf(GeneratoreTestoNullo::class);

    attivaIAPerGenerazione(['ia_abilitata' => false]);
    expect(app(GeneratoreTesto::class))->toBeInstanceOf(GeneratoreTestoNullo::class);

    attivaIAPerGenerazione(['api_key' => 'cifrata-con-un-altra-app-key']);
    expect(app(GeneratoreTesto::class))->toBeInstanceOf(GeneratoreTestoNullo::class)
        ->and(app(GeneratoreTesto::class)->disponibile())->toBeFalse();

    attivaIAPerGenerazione();
    expect(app(GeneratoreTesto::class))->toBeInstanceOf(GeneratoreTestoAnthropic::class)
        ->and(app(GeneratoreTesto::class)->disponibile())->toBeTrue();
});
