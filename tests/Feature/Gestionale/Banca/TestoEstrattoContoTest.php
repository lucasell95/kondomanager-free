<?php

/**
 * Le conversioni di base degli estratti conto: importi, date, IBAN, controparti.
 * Un errore qui sposta soldi da un movimento all'altro, quindi le forme si provano una per una.
 */

use App\Services\Gestionale\Banca\Testo;

require_once __DIR__.'/BancaTestHelpers.php';

it('converte gli importi nelle forme che le banche usano', function (mixed $valore, ?int $atteso) {
    expect(Testo::importoInCentesimi($valore))->toBe($atteso);
})->with([
    'italiano con migliaia' => ['1.234,56', 123456],
    'negativo' => ['-1234,56', -123456],
    'inglese' => ['1,234.56', 123456],
    'con simbolo' => ['€ 12,00', 1200],
    'un decimale' => ['12,5', 1250],
    'migliaia senza decimali' => ['1.234', 123400],
    'negativo fra parentesi' => ['(3,20)', -320],
    'segno in coda' => ['15,00-', -1500],
    'numero di Excel' => [1234.56, 123456],
    'milioni' => ['1.000.000,00', 100000000],
    'vuoto' => ['', null],
    'testo' => ['EUR', null],
]);

it('legge le date italiane, ISO e i seriali di Excel', function (mixed $valore, ?string $atteso) {
    expect(Testo::data($valore)?->toDateString())->toBe($atteso);
})->with([
    ['31/01/2026', '2026-01-31'],
    ['31-01-26', '2026-01-31'],
    ['31.01.2026', '2026-01-31'],
    ['2026-01-31', '2026-01-31'],
    ['20260131', '2026-01-31'],
    [46053, '2026-01-31'],
    ['31/02/2026', null],
    ['Totale', null],
]);

it('estrae l\'IBAN dalla descrizione solo se il controllo mod 97 torna', function () {
    expect(Testo::estraiIban('IBAN ORD.: DE89 3704 0044 0532 0130 00 INFO: RATA'))->toBe(IBAN_ROSSI)
        ->and(Testo::estraiIban('RIF. DE89370400440532013001'))->toBeNull()
        ->and(Testo::estraiIban('da '.IBAN_CONTO_CONDOMINIO.' a '.IBAN_ROSSI, IBAN_CONTO_CONDOMINIO))->toBe(IBAN_ROSSI);
});

it('estrae la controparte dalle etichette della descrizione', function () {
    expect(Testo::estraiControparte('BONIFICO A VOSTRO FAVORE MITT.: ROSSI MARIO BENEF.: CONDOMINIO INFO: RATA', true))->toBe('ROSSI MARIO')
        ->and(Testo::estraiControparte('Bonifico disposto da: Bianchi Anna; causale rata', true))->toBe('Bianchi Anna')
        ->and(Testo::estraiControparte('COMMISSIONI', false))->toBeNull();
});

it('pesa la somiglianza del nome: una parola sola vale meno di nome e cognome', function () {
    expect(Testo::somiglianzaNome('Rossi Mario', 'bonifico mitt rossi mario rata'))->toBe(100)
        ->and(Testo::somiglianzaNome('Rossi', 'bonifico rossi'))->toBe(60)
        ->and(Testo::somiglianzaNome('Verdi Impianti Srl', 'bonifico a verdi impianti'))->toBe(100)
        ->and(Testo::somiglianzaNome('Bianchi Anna', 'bonifico rossi'))->toBe(0);
});
