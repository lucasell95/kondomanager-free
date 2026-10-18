<?php

use App\Models\Gestionale\ScritturaContabile;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * Emissioni, compensazioni e accantonamenti hanno un prefisso di protocollo proprio invece di
 * `SCR`, che nel registro vuol dire «non classificata».
 */
test('emissione, compensazione e accantonamento hanno il loro prefisso di protocollo', function (string $tipo, string $prefisso) {
    [$condominio, $esercizio, $gestione] = setupContabile();

    $scrittura = ScritturaContabile::create([
        'condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id, 'gestione_id' => $gestione->id,
        'data_registrazione' => now()->toDateString(), 'data_competenza' => now()->toDateString(),
        'causale' => 'Prova', 'tipo_movimento' => $tipo, 'stato' => 'registrata',
    ]);

    expect($scrittura->numero_protocollo)->toStartWith($prefisso.'-'.now()->format('Y').'-');
})->with([
    ['emissione_rata', 'EMI'],
    ['storno_credito', 'CMP'],
    ['accantonamento', 'ACC'],
]);
