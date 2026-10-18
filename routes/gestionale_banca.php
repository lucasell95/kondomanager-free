<?php

use App\Http\Controllers\Gestionale\Banca\ImportazioneBancaController;
use App\Http\Controllers\Gestionale\Banca\MovimentiBancariController;
use App\Http\Controllers\Gestionale\Banca\RiconciliazioneBancaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Banca: import degli estratti conto, abbinamento, riconciliazione
|--------------------------------------------------------------------------
|
| Incluso da `routes/gestionale.php` **dentro** il gruppo `/gestionale/{condominio}`: ne eredita
| prefisso, nome (`admin.gestionale.`), middleware (accesso al pannello, esercizio e piano dei
| conti presenti) e `scopeBindings()`. I parametri `{movimento}` e `{regola}` sono id grezzi: i
| controller li cercano dentro il condominio dell'indirizzo (`assicuraMovimento`, `assicuraRegola`).
*/

Route::prefix('banca')->name('banca.')->group(function () {
    Route::get('/importa', [ImportazioneBancaController::class, 'create'])->name('importa');
    Route::post('/importa/anteprima', [ImportazioneBancaController::class, 'anteprima'])->name('importa.anteprima');
    Route::post('/importa/conferma', [ImportazioneBancaController::class, 'conferma'])->name('importa.conferma');
    Route::post('/importa/scarta', [ImportazioneBancaController::class, 'scarta'])->name('importa.scarta');

    Route::get('/movimenti', [MovimentiBancariController::class, 'index'])->name('movimenti.index');
    Route::post('/movimenti/conferma-multipla', [MovimentiBancariController::class, 'multipla'])->name('movimenti.multipla');
    Route::get('/movimenti/{movimento}/proposte', [MovimentiBancariController::class, 'proposte'])->name('movimenti.proposte');
    Route::get('/movimenti/{movimento}/rate-aperte', [MovimentiBancariController::class, 'rateAperte'])->name('movimenti.rate-aperte');
    Route::post('/movimenti/{movimento}/incasso', [MovimentiBancariController::class, 'incasso'])->name('movimenti.incasso');
    Route::post('/movimenti/{movimento}/pagamento', [MovimentiBancariController::class, 'pagamento'])->name('movimenti.pagamento');
    Route::post('/movimenti/{movimento}/regolazione', [MovimentiBancariController::class, 'regolazione'])->name('movimenti.regolazione');
    Route::post('/movimenti/{movimento}/collega', [MovimentiBancariController::class, 'collega'])->name('movimenti.collega');
    Route::post('/movimenti/{movimento}/ignora', [MovimentiBancariController::class, 'ignora'])->name('movimenti.ignora');
    Route::post('/movimenti/{movimento}/scollega', [MovimentiBancariController::class, 'scollega'])->name('movimenti.scollega');

    Route::post('/regole', [MovimentiBancariController::class, 'storeRegola'])->name('regole.store');
    Route::delete('/regole/{regola}', [MovimentiBancariController::class, 'destroyRegola'])->name('regole.destroy');

    Route::get('/riconciliazione', [RiconciliazioneBancaController::class, 'index'])->name('riconciliazione');
    Route::post('/riconciliazione/saldo', [RiconciliazioneBancaController::class, 'salvaSaldo'])->name('riconciliazione.saldo');
});
