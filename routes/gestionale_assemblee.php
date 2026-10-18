<?php

/*
|--------------------------------------------------------------------------
| Modulo Assemblee (gestionale)
|--------------------------------------------------------------------------
|
| Incluso da `routes/gestionale.php` **dentro** il gruppo `/gestionale/{condominio}`: eredita il
| prefisso `admin/`, i middleware del pannello amministratore e soprattutto `scopeBindings()`, per
| cui `{assemblea}` si cerca dentro il condominio dell'indirizzo e `{punto}`, `{destinatario}`,
| `{dichiarazione}` dentro l'assemblea (mappe in `Condominio` e `Assemblea`, trait
| `RisolveIFigliDelleRotte`). Ogni metodo ripete comunque la guardia (`GuardieAssemblea`).
*/

use App\Http\Controllers\Gestionale\Assemblee\AssembleaController;
use App\Http\Controllers\Gestionale\Assemblee\AssembleaConvocazioneController;
use App\Http\Controllers\Gestionale\Assemblee\AssembleaDocumentiController;
use App\Http\Controllers\Gestionale\Assemblee\AssembleaOdgController;
use App\Http\Controllers\Gestionale\Assemblee\AssembleaPresenzeController;
use Illuminate\Support\Facades\Route;

Route::get('registro-verbali/stampa', [AssembleaDocumentiController::class, 'registroPdf'])
    ->name('assemblee.registro.stampa');

Route::resource('assemblee', AssembleaController::class)
    ->except(['edit'])
    ->parameters(['assemblee' => 'assemblea']);

Route::prefix('assemblee/{assemblea}')->name('assemblee.')->group(function () {
    Route::put('stato', [AssembleaController::class, 'stato'])->name('stato');
    Route::post('fotografia', [AssembleaController::class, 'fotografia'])->name('fotografia');
    Route::put('svolgimento', [AssembleaController::class, 'svolgimento'])->name('svolgimento');

    // Ordine del giorno
    Route::post('punti', [AssembleaOdgController::class, 'store'])->name('punti.store');
    Route::put('punti/{punto}', [AssembleaOdgController::class, 'update'])->name('punti.update');
    Route::delete('punti/{punto}', [AssembleaOdgController::class, 'destroy'])->name('punti.destroy');
    Route::put('punti/{punto}/sposta', [AssembleaOdgController::class, 'sposta'])->name('punti.sposta');

    // Convocazione
    Route::post('destinatari/genera', [AssembleaConvocazioneController::class, 'genera'])->name('destinatari.genera');
    Route::post('destinatari', [AssembleaConvocazioneController::class, 'store'])->name('destinatari.store');
    Route::put('destinatari/{destinatario}', [AssembleaConvocazioneController::class, 'update'])->name('destinatari.update');
    Route::delete('destinatari/{destinatario}', [AssembleaConvocazioneController::class, 'destroy'])->name('destinatari.destroy');
    Route::put('comunicazione-verbale', [AssembleaConvocazioneController::class, 'comunicazioneVerbale'])->name('comunicazione-verbale');

    // Presenze, voti, verbale
    Route::put('presenze', [AssembleaPresenzeController::class, 'presenza'])->name('presenze.update');
    Route::put('punti/{punto}/voti', [AssembleaPresenzeController::class, 'voti'])->name('punti.voti');
    Route::post('punti/{punto}/anteprima', [AssembleaPresenzeController::class, 'anteprima'])->name('punti.anteprima');
    Route::put('punti/{punto}/testi', [AssembleaPresenzeController::class, 'testi'])->name('punti.testi');
    Route::post('dichiarazioni', [AssembleaPresenzeController::class, 'storeDichiarazione'])->name('dichiarazioni.store');
    Route::delete('dichiarazioni/{dichiarazione}', [AssembleaPresenzeController::class, 'destroyDichiarazione'])->name('dichiarazioni.destroy');

    // Documenti
    Route::get('stampa/{documento}', [AssembleaDocumentiController::class, 'stampa'])
        ->whereIn('documento', ['avviso', 'presenze', 'verbale', 'assenti'])
        ->name('stampa');
    Route::post('bozza/{tipo}', [AssembleaDocumentiController::class, 'bozza'])
        ->whereIn('tipo', ['convocazione', 'verbale'])
        ->name('bozza');
    Route::get('bozza/{tipo}/contesto', [AssembleaDocumentiController::class, 'contestoBozza'])
        ->whereIn('tipo', ['convocazione', 'verbale'])
        ->name('bozza.contesto');
    Route::post('verbale/registra', [AssembleaDocumentiController::class, 'registra'])->name('verbale.registra');
});
