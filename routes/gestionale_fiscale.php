<?php

use App\Http\Controllers\Gestionale\Fiscale\SostitutoImpostaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Area fiscale: il condominio come sostituto d'imposta (CU e 770)
|--------------------------------------------------------------------------
|
| Incluso da `routes/gestionale.php` DENTRO il gruppo `/gestionale/{condominio}`, quindi eredita
| prefisso, nome `gestionale.`, middleware del pannello amministratore e `scopeBindings()`.
|
| L'anno e il fornitore viaggiano come numeri, non come modelli: l'anno non è un modello, e il
| fornitore non è figlio del condominio (l'anagrafica dei fornitori è comune). Il vincolo al
| condominio lo applica il controller.
*/

Route::get('/fiscale/cu-770', [SostitutoImpostaController::class, 'index'])
    ->name('fiscale.cu770.index');

Route::get('/fiscale/cu-770/{anno}/pdf', [SostitutoImpostaController::class, 'pdf'])
    ->whereNumber('anno')
    ->name('fiscale.cu770.pdf');

Route::get('/fiscale/cu-770/{anno}/export/{formato}', [SostitutoImpostaController::class, 'export'])
    ->whereNumber('anno')
    ->whereIn('formato', ['csv', 'xlsx'])
    ->name('fiscale.cu770.export');

Route::get('/fiscale/cu-770/{anno}/cu/{fornitoreId}', [SostitutoImpostaController::class, 'cu'])
    ->whereNumber('anno')
    ->whereNumber('fornitoreId')
    ->name('fiscale.cu770.cu');

Route::put('/fiscale/sostituto', [SostitutoImpostaController::class, 'aggiornaSostituto'])
    ->name('fiscale.sostituto.update');

Route::post('/fiscale/ravvedimenti', [SostitutoImpostaController::class, 'salvaRavvedimento'])
    ->name('fiscale.ravvedimenti.store');

Route::delete('/fiscale/ravvedimenti/{ravvedimentoId}', [SostitutoImpostaController::class, 'eliminaRavvedimento'])
    ->whereNumber('ravvedimentoId')
    ->name('fiscale.ravvedimenti.destroy');
