<?php

/**
 * Le descrizioni dei codici tributo in `config/fiscale.php` dicono quello che dice l'Agenzia.
 *
 * Ris. AdE 18/E del 28/04/2023 (operativa dal 03/07/2023, al posto dell'8906):
 *  - 8947: sanzione per ravvedimento su ritenute — lavoro dipendente, assimilati, assistenza fiscale;
 *  - 8948: sanzione per ravvedimento su ritenute — lavoro autonomo, provvigioni, redditi diversi,
 *    locazioni brevi;
 *  - 8949: sanzione per ravvedimento su ritenute — redditi di capitale.
 * Gli interessi non hanno un codice: si cumulano al tributo.
 *
 * Prima 8947 era descritto come «interessi», 8948 come «imposte sostitutive», 8949 come «altre
 * imposte dirette», e il 1038 (provvigioni) come «lavoro autonomo».
 */
test('i codici sanzione hanno la denominazione della ris. 18/E/2023', function () {
    $codici = config('fiscale.codici_tributo');

    expect($codici['8947']['descrizione'])->toContain('lavoro dipendente')
        ->and($codici['8948']['descrizione'])->toContain('lavoro autonomo, provvigioni, redditi diversi')
        ->and($codici['8949']['descrizione'])->toContain('redditi di capitale');

    // Nessuno dei tre è il codice degli interessi, e nessuno si precompila: l'abbinamento alle
    // ritenute 1019/1020 non è confermato da fonte ufficiale.
    foreach (['8947', '8948', '8949'] as $codice) {
        expect(strtolower($codici[$codice]['descrizione']))->not->toContain('interessi')
            ->and($codici[$codice]['auto_precompila'])->toBeFalse()
            ->and($codici[$codice]['sanzione'])->toBeTrue();
    }
});

test('il 1038 è il vecchio codice delle provvigioni, sostituito dal 1040', function () {
    $codici = config('fiscale.codici_tributo');

    expect($codici['1038']['descrizione'])->toContain('provvigioni')
        ->and($codici['1038']['soppresso_dal'])->toBe('2017-01-01')
        ->and($codici['1040']['descrizione'])->toContain('provvigioni');
});
