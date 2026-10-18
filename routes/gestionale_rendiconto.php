<?php

use App\Http\Controllers\Gestionale\Rendiconto\RendicontoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rendiconto condominiale (art. 1130-bis c.c.)
|--------------------------------------------------------------------------
|
| Incluso da `routes/gestionale.php` DENTRO il gruppo `/gestionale/{condominio}`: eredita il
| prefisso `admin.gestionale.`, i middleware del pannello amministratore e `scopeBindings()`,
| quindi `{esercizio}` si risolve solo fra gli esercizi del condominio della rotta.
|
| Sola lettura sulla contabilità; le due rotte di scrittura toccano solo `rendiconti_esercizio`
| (nota sintetica ed estratti conto).
*/

Route::get('esercizi/{esercizio}/rendiconto', [RendicontoController::class, 'show'])
    ->name('esercizi.rendiconto.show');

Route::put('esercizi/{esercizio}/rendiconto', [RendicontoController::class, 'aggiorna'])
    ->name('esercizi.rendiconto.aggiorna');

Route::post('esercizi/{esercizio}/rendiconto/bozza-nota', [RendicontoController::class, 'bozzaNota'])
    ->name('esercizi.rendiconto.bozza-nota');

Route::get('esercizi/{esercizio}/rendiconto/pdf', [RendicontoController::class, 'pdf'])
    ->name('esercizi.rendiconto.pdf');

Route::get('esercizi/{esercizio}/rendiconto/xlsx', [RendicontoController::class, 'xlsx'])
    ->name('esercizi.rendiconto.xlsx');
