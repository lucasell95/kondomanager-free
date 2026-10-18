<?php

/*
|--------------------------------------------------------------------------
| Modulo Assemblee (area condòmini)
|--------------------------------------------------------------------------
|
| Incluso da `routes/user.php` dentro il gruppo `user/` (auth + verified). Sola lettura: il
| controller filtra sui condomìni dell'anagrafica dell'utente e nasconde le assemblee in bozza.
*/

use App\Http\Controllers\Assemblee\Utenti\AssembleaController;
use Illuminate\Support\Facades\Route;

Route::get('assemblee', [AssembleaController::class, 'index'])->name('assemblee.index');
Route::get('assemblee/{assemblea}', [AssembleaController::class, 'show'])->name('assemblee.show');
Route::get('assemblee/{assemblea}/avviso', [AssembleaController::class, 'avviso'])->name('assemblee.avviso');
Route::get('assemblee/{assemblea}/verbale', [AssembleaController::class, 'verbale'])->name('assemblee.verbale');
