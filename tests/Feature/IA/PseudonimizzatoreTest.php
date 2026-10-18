<?php

/**
 * Pseudonimizzazione del contesto prima dell'invio all'IA e ricomposizione della risposta.
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Services\IA\Pseudonimizzatore;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('elimina codici fiscali, IBAN, e-mail e telefoni dai campi strutturati', function () {
    $inviato = (new Pseudonimizzatore())->pseudonimizza([
        'soggetti' => [[
            'nome' => 'Mario', 'cognome' => 'Rossi', 'codice_fiscale' => 'TSTTST80A01H501Z',
            'email' => 'mario.rossi@test.it', 'pec' => 'rossi.pec@example.com', 'telefono' => '035 123456',
            'iban' => 'DE89370400440532013000', 'data_nascita' => '1980-01-01', 'ruolo' => 'proprietario',
        ]],
    ]);

    expect($inviato['soggetti'][0])->toBe(['nome' => '[PERSONA_1]', 'ruolo' => 'proprietario']);
});

it('sostituisce nomi, ragioni sociali, indirizzi e condominio con codici e li ricompone', function () {
    $p = new Pseudonimizzatore();

    $inviato = $p->pseudonimizza([
        'condominio' => ['nome' => 'Condominio Via Verdi 12', 'indirizzo' => 'Via Verdi 12, Bergamo'],
        'presidente' => 'Bianchi Anna',
        'fornitori'  => [['ragione_sociale' => 'Pulizie Verdi Srl']],
        'note'       => 'La sig.ra Bianchi riferisce che Pulizie Verdi non ha pulito in via Verdi 12.',
    ]);

    expect($inviato['condominio'])->toBe(['nome' => '[CONDOMINIO_1]', 'indirizzo' => '[INDIRIZZO_1]'])
        ->and($inviato['presidente'])->toBe('[PERSONA_1]')
        ->and($inviato['fornitori'][0]['ragione_sociale'])->toBe('[AZIENDA_1]')
        ->and($inviato['note'])->toBe('La sig.ra [PERSONA_1] riferisce che [AZIENDA_1] non ha pulito in [INDIRIZZO_2].');

    $risposta = ['testo' => '[PERSONA_1] presiede; il servizio di [AZIENDA_1] presso [CONDOMINIO_1] è da verificare.'];

    expect($p->ricomponi($risposta))->toBe([
        'testo' => 'Bianchi Anna presiede; il servizio di Pulizie Verdi Srl presso Condominio Via Verdi 12 è da verificare.',
    ]);
});

it('riconosce il nome in ordine inverso, il solo cognome e le maiuscole diverse', function () {
    $p = new Pseudonimizzatore();
    $p->registraPersona('Rossi Mario');

    expect($p->pseudonimizzaTesto('Interviene MARIO ROSSI; poi il sig. rossi replica. Rossini non c\'entra.'))
        ->toBe('Interviene [PERSONA_1]; poi il sig. [PERSONA_1] replica. Rossini non c\'entra.');
});

it('con due persone dallo stesso cognome non attribuisce il cognome a nessuna delle due', function () {
    $p = new Pseudonimizzatore();
    $p->registraPersona('Rossi Mario');
    $p->registraPersona('Rossi Luigi');

    $testo = $p->pseudonimizzaTesto('Mario Rossi e Luigi Rossi sono presenti; Rossi chiede la parola.');

    expect($testo)->toBe('[PERSONA_1] e [PERSONA_2] sono presenti; [PERSONA_3] chiede la parola.')
        // Il cognome da solo torna come era scritto, senza indovinare quale dei due.
        ->and($p->ricomponi($testo))->toBe('Rossi Mario e Rossi Luigi sono presenti; Rossi chiede la parola.');
});

it('toglie dal testo libero e-mail, IBAN, codici fiscali, partite IVA e telefoni senza ricomporli', function () {
    $p = new Pseudonimizzatore();

    $testo = $p->pseudonimizzaTesto(
        'Scrivere a verdi@example.com, IBAN IT60 X054 2811 1010 0000 0123 456, CF TSTTST80A01H501Z, '
        .'P.IVA 01234567890, cell. 333 1234567. Spesa di 12.480,00 € al 31/05/2026.'
    );

    expect($testo)->toBe(
        'Scrivere a [EMAIL_RIMOSSA], IBAN [IBAN_RIMOSSO], CF [CODICE_FISCALE_RIMOSSO], '
        .'P.IVA [PARTITA_IVA_RIMOSSA], cell. [TELEFONO_RIMOSSO]. Spesa di 12.480,00 € al 31/05/2026.'
    )
        ->and($p->ricomponi($testo))->toBe($testo)
        ->and($p->residuiRimossi())->toBe([
            'EMAIL_RIMOSSA' => 1, 'IBAN_RIMOSSO' => 1, 'CODICE_FISCALE_RIMOSSO' => 1,
            'PARTITA_IVA_RIMOSSA' => 1, 'TELEFONO_RIMOSSO' => 1,
        ]);
});

it('usa i dati personali dichiarati a parte senza inviarli', function () {
    $p = new Pseudonimizzatore();

    $inviato = $p->pseudonimizza([
        '_dati_personali' => [['tipo' => 'persona', 'valore' => 'Verdi Paolo']],
        'note' => 'Paolo Verdi assente.',
    ]);

    expect($inviato)->toBe(['note' => '[PERSONA_1] assente.']);
});

it('da condominio_id carica condominio e anagrafiche collegate', function () {
    $condominio = Condominio::factory()->create(['nome' => 'Condominio dei Gelsi Prova', 'indirizzo' => 'Via dei Gelsi 99']);
    $anagrafica = Anagrafica::factory()->create(['nome' => 'Bianchi Carla', 'indirizzo' => 'Via Rossi 3, Bergamo', 'codice_fiscale' => 'TSTTST70A41H501X']);
    $condominio->anagrafiche()->attach($anagrafica->id);

    $p = new Pseudonimizzatore();
    $inviato = $p->pseudonimizza([
        'condominio_id' => $condominio->id,
        'note' => 'Nel Condominio dei Gelsi Prova di via dei Gelsi 99 la sig.ra Carla Bianchi (via Rossi 3) segnala un guasto.',
    ]);

    expect($inviato)->not->toHaveKey('condominio_id')
        ->and($inviato['note'])->toBe('Nel [CONDOMINIO_1] di [INDIRIZZO_1] la sig.ra [PERSONA_1] ([INDIRIZZO_2]) segnala un guasto.');
});

it('non tocca importi, date e chiavi dei fatti', function () {
    $contesto = ['fatti' => ['rend.totale' => ['valore' => '12.480,00 €', 'descrizione' => 'Spese ordinarie 2025/26']]];

    expect((new Pseudonimizzatore())->pseudonimizza($contesto))->toBe($contesto);
});
