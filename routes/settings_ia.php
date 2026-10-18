<?php

use App\Http\Controllers\Impostazioni\IASettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Impostazioni IA
|--------------------------------------------------------------------------
| Incluse da routes/settings.php dentro il gruppo 'auth'. Come le altre pagine di Impostazioni,
| l'autorizzazione vera è nel controller (Gate::authorize('manage', IASettings) → permesso
| «Gestisci impostazioni generali»); qui si aggiungono l'e-mail verificata e un limite alla prova
| di connessione, che fa una chiamata esterna.
*/

Route::middleware('verified')->group(function () {

    Route::get('impostazioni/ia', [IASettingsController::class, 'edit'])
        ->name('impostazioni.ia');

    Route::post('impostazioni/ia', [IASettingsController::class, 'update'])
        ->name('impostazioni.ia.update');

    Route::post('impostazioni/ia/prova', [IASettingsController::class, 'test'])
        ->middleware('throttle:10,1')
        ->name('impostazioni.ia.test');
});
