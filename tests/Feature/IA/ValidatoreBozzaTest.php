<?php

/**
 * I controlli automatici sulla bozza: cifre fuori dai segnaposto, segnaposto inesistenti, punti,
 * codici di persona inventati, norme non fornite, parole che anticipano l'esito.
 */

use App\Services\IA\ValidatoreBozza;

function contestoValidatoreDiProva(): array
{
    return [
        'punti' => [['n' => 1, 'testo_utente' => 'rendiconto'], ['n' => 2, 'testo_utente' => 'pulizie']],
        'fatti' => [
            'rend.totale' => ['valore' => '12.480,00 €', 'descrizione' => 'Spese'],
            'ass.data'    => ['valore' => '20/11/2026', 'descrizione' => 'Data'],
        ],
        'norme_ammesse' => ['art. 1130-bis c.c.', 'art. 66 disp. att. c.c.'],
    ];
}

function bozzaValidatoreDiProva(string $testo1 = 'Testo del primo punto.', string $testo2 = 'Testo del secondo punto.'): array
{
    return ['punti' => [['n' => 1, 'testo' => $testo1], ['n' => 2, 'testo' => $testo2]]];
}

it('accetta segnaposto esistenti, norme ammesse e rimandi ai punti', function () {
    $esito = (new ValidatoreBozza())->valida('verbale', bozzaValidatoreDiProva(
        "Spese per {{F:rend.totale}} ai sensi dell'art. 1130-bis c.c., come detto al punto 2.",
        'Assemblea del {{F:ass.data}}; [PERSONA_1] interviene.',
    ), contestoValidatoreDiProva(), [], ['[PERSONA_1]']);

    expect($esito->superata())->toBeTrue()
        ->and($esito->errori)->toBe([])
        ->and($esito->avvisi)->toBe([]);
});

it('segnala come avviso le cifre fuori dai segnaposto', function (string $testo) {
    $esito = (new ValidatoreBozza())->valida('convocazione', bozzaValidatoreDiProva($testo), contestoValidatoreDiProva());

    expect($esito->superata())->toBeTrue()
        ->and($esito->avvisi)->toHaveCount(1)
        ->and($esito->avvisi[0])->toContain('punti.0.testo')
        ->and($esito->avvisi[0])->toContain('cifre fuori dai segnaposto');
})->with([
    'importo' => ['La spesa è di 12.480 euro.'],
    'data'    => ['Assemblea del 20 novembre 2026.'],
    'percentuale' => ['Aumento del 18%.'],
]);

it('segnala una norma non fornita nel contesto', function () {
    $esito = (new ValidatoreBozza())->valida('verbale', bozzaValidatoreDiProva("Ai sensi dell'art. 1136 c.c."), contestoValidatoreDiProva());

    expect(implode(' ', $esito->avvisi))->toContain('riferimento normativo («art. 1136»)');
});

it('considera grave un numero di punti diverso da quello richiesto', function () {
    $bozza = ['punti' => [['n' => 1, 'testo' => 'Solo un punto.']]];

    $esito = (new ValidatoreBozza())->valida('convocazione', $bozza, contestoValidatoreDiProva());

    expect($esito->superata())->toBeFalse()
        ->and($esito->errori)->toBe(['la bozza ha 1 punti invece dei 2 richiesti.']);
});

it('considera grave una numerazione dei punti cambiata', function () {
    $bozza = ['punti' => [['n' => 2, 'testo' => 'a'], ['n' => 1, 'testo' => 'b']]];

    expect((new ValidatoreBozza())->valida('convocazione', $bozza, contestoValidatoreDiProva())->errori)
        ->toBe(['la numerazione dei punti non corrisponde a quella richiesta.']);
});

it('considera grave un segnaposto inesistente o scritto male', function () {
    $esito = (new ValidatoreBozza())->valida('verbale', bozzaValidatoreDiProva('Fondo di {{F:fondo.cassa}}.', 'Totale {{rend.totale}}.'), contestoValidatoreDiProva());

    expect($esito->superata())->toBeFalse()
        ->and($esito->errori)->toHaveCount(2)
        ->and($esito->errori[0])->toContain('{{F:fondo.cassa}}')
        ->and($esito->errori[1])->toContain('scritto male');
});

it('considera grave un codice di persona che non era nel contesto', function () {
    $esito = (new ValidatoreBozza())->valida('verbale', bozzaValidatoreDiProva('[PERSONA_7] interviene.'), contestoValidatoreDiProva(), [], ['[PERSONA_1]']);

    expect($esito->errori)->toHaveCount(1)->and($esito->errori[0])->toContain('[PERSONA_7]');
});

it('considera grave un campo obbligatorio mancante', function () {
    $esito = (new ValidatoreBozza())->valida('verbale', bozzaValidatoreDiProva(), contestoValidatoreDiProva(), ['required' => ['punti', 'note_per_revisore']]);

    expect($esito->errori)->toBe(['manca il campo obbligatorio «note_per_revisore».']);
});

it('nel verbale avvisa se la bozza anticipa l\'esito della votazione', function () {
    $esito = (new ValidatoreBozza())->valida('verbale', bozzaValidatoreDiProva('La proposta è approvata all\'unanimità.'), contestoValidatoreDiProva());

    expect($esito->superata())->toBeTrue()
        ->and(implode(' ', $esito->avvisi))->toContain('esito delle votazioni');
});

it('avvisa delle parti da completare e dei dati rimossi', function () {
    $esito = (new ValidatoreBozza())->valida('verbale', bozzaValidatoreDiProva('[[DA COMPLETARE: nome del tecnico]]', 'Scrivere a [EMAIL_RIMOSSA].'), contestoValidatoreDiProva());

    expect($esito->superata())->toBeTrue()
        ->and($esito->avvisi)->toHaveCount(2);
});
