<?php

use App\Http\Controllers\Gestionale\Esercizi\ChiusuraEsercizioController;
use Illuminate\Support\Facades\Route;

/*
 * Passaggio d'esercizio guidato (area «chiusura»).
 *
 * Incluso da `routes/gestionale.php` DENTRO il gruppo `/gestionale/{condominio}`: eredita il
 * prefisso, il nome `admin.gestionale.`, i middleware del pannello amministratore e lo
 * `scopeBindings()` — `{esercizio}` si cerca dentro `{condominio}`, quindi l'esercizio di un altro
 * condominio risponde 404.
 */

Route::get('esercizi/{esercizio}/chiusura', [ChiusuraEsercizioController::class, 'show'])
    ->name('esercizi.chiusura.show');

Route::post('esercizi/{esercizio}/chiusura', [ChiusuraEsercizioController::class, 'store'])
    ->name('esercizi.chiusura.store');

Route::post('esercizi/{esercizio}/chiusura/annulla', [ChiusuraEsercizioController::class, 'annulla'])
    ->name('esercizi.chiusura.annulla');

// A quale esercizio appartiene una data (guida per le fatture tardive).
Route::get('esercizi-competenza', [ChiusuraEsercizioController::class, 'competenza'])
    ->name('esercizi.competenza');
