<?php

/**
 * La distinta delle spese è un preventivo, e non deve presentarsi come rendiconto.
 *
 * La nota in fondo diceva «Documento generato ai sensi dell'art. 1135 c.c. e dell'art. 1130-bis c.c.
 * (rendiconto condominiale)», mentre la stampa riporta gli importi del preventivo approvato. Il
 * rendiconto dell'art. 1130-bis (registro di contabilità, riepilogo finanziario, nota sintetica) è
 * un altro documento, e chi riceve la distinta non deve poterli confondere.
 */

use App\Models\Esercizio;

test('la nota legale della distinta dice preventivo, non rendiconto', function () {
    $esercizio = new Esercizio([
        'nome' => '2026/27', 'data_inizio' => '2026-06-01', 'data_fine' => '2027-05-31',
    ]);

    $html = view('pdf.gestionale.distinta_spese', [
        'condominio' => (object) ['nome' => 'Condominio Rossi', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => '91000000001'],
        'esercizio' => $esercizio,
        'pianoConto' => (object) ['nome' => 'Preventivo ordinario'],
        'conti' => collect(),
        'totalePreventivo' => 0,
        'totaleSopravvenienze' => 0,
    ])->render();

    expect($html)
        ->toContain('Prospetto del preventivo di spesa (art. 1135 c. 1 n. 2 c.c.)')
        ->toContain('non è il rendiconto condominiale')
        ->not->toContain('(rendiconto condominiale)');
});
