<?php

/**
 * Pagare un fornitore in contanti, o da una cassa contanti, chiede una conferma esplicita.
 *
 * Art. 1129 c. 7 c.c.: le somme erogate a qualunque titolo per conto del condominio transitano sul
 * conto corrente intestato al condominio. Art. 25-ter c. 2-ter DPR 600/1973: i corrispettivi di
 * appalto si pagano tramite conto corrente intestato al condominio o modalità tracciabili, con la
 * sanzione dell'art. 11 c. 1 D.Lgs. 471/1997.
 *
 * Fino al 18/10/2026 i contanti verso i fornitori erano fermati solo da 5.000 € in su
 * (antiriciclaggio). Ora sotto quella soglia serve una conferma con motivazione; sopra resta il
 * blocco non aggirabile di prima.
 */

use App\Enums\MetodoPagamento;
use App\Enums\TipoAllocazioneFattura;
use App\Exceptions\Pagamenti\IllegalCashAmountException;
use App\Exceptions\Pagamenti\PagamentoContantiFornitoreException;
use App\Models\Gestionale\PagamentoFornitore;
use App\Models\User;
use App\Services\Gestionale\FatturaPassivaService;
use App\Services\Gestionale\PagamentoFornitoreService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/GestionaleTestHelpers.php';

/** Una cassa di tipo contanti del condominio, con il suo conto. Restituisce l'id del conto. */
function ctfCassaContanti(int $condominioId): int
{
    $contoId = DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominioId, 'ruolo' => 'cassa_contanti', 'codice' => 'CASSA-TEST',
        'nome' => 'Cassa contanti', 'tipo' => 'attivo', 'categoria' => 'crediti',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('casse')->insert([
        'condominio_id' => $condominioId, 'conto_contabile_id' => $contoId, 'nome' => 'Cassa contanti',
        'tipo' => 'contanti', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $contoId;
}

test('contanti al fornitore senza conferma: si ferma e cita l\'art. 1129', function () {
    $ctx = setupPagamentiService();
    $fattura = registraFatturaServiceTest($ctx);

    $tentativo = fn () => (new PagamentoFornitoreService)->registraPagamento(datiPagamento($ctx, $fattura, [
        'metodo_pagamento' => MetodoPagamento::CONTANTI->value,
    ]));

    expect($tentativo)->toThrow(PagamentoContantiFornitoreException::class, 'art. 1129 c. 7');
    expect(PagamentoFornitore::count())->toBe(0);
});

test('per un appaltatore con ritenuta 4% l\'avviso cita anche l\'art. 25-ter c. 2-ter', function () {
    $ctx = setupPagamentiService();
    $ctx[3]->update(['soggetto_ritenuta' => true, 'perc_ritenuta' => 4, 'perc_imponibile_ritenuta' => 100]);
    $ctx[3]->refresh();
    $fattura = registraFatturaServiceTest($ctx, ['applica_ritenuta' => true]);

    expect(fn () => (new PagamentoFornitoreService)->registraPagamento(datiPagamento($ctx, $fattura, [
        'metodo_pagamento' => MetodoPagamento::CONTANTI->value,
    ])))->toThrow(PagamentoContantiFornitoreException::class, 'art. 25-ter');
});

test('anche un «bonifico» che esce da una cassa contanti chiede conferma', function () {
    $ctx = setupPagamentiService();
    $fattura = registraFatturaServiceTest($ctx);
    $contoCassa = ctfCassaContanti($ctx[0]->id);

    expect(fn () => (new PagamentoFornitoreService)->registraPagamento(datiPagamento($ctx, $fattura, [
        'conto_corrente_id' => $contoCassa,
    ])))->toThrow(PagamentoContantiFornitoreException::class);
});

test('confermato con motivazione, il pagamento in contanti si registra', function () {
    $ctx = setupPagamentiService();
    $fattura = registraFatturaServiceTest($ctx);

    $pagamento = (new PagamentoFornitoreService)->registraPagamento(datiPagamento($ctx, $fattura, [
        'metodo_pagamento' => MetodoPagamento::CONTANTI->value,
        'conferma_contanti_fornitore' => true,
        'nota_override' => 'Contanti: pagato il 10/10 con ricevuta n. 5',
    ]));

    expect($pagamento->metodo_pagamento)->toBe(MetodoPagamento::CONTANTI)
        ->and($pagamento->note_override)->toContain('ricevuta n. 5');
});

test('la conferma non aggira il limite antiriciclaggio dei 5.000 euro', function () {
    $ctx = setupPagamentiService();
    [$condominio, $esercizio, $gestione, $fornitore, , $capitolo] = $ctx;
    $fattura = (new FatturaPassivaService)->registraFattura(datiBase([$condominio, $esercizio, $gestione, $fornitore], [
        'righe' => [[
            'descrizione' => 'Lavori', 'importo_imponibile' => 6000, 'aliquota_iva' => 22,
            'conto_id' => $capitolo->id, 'is_sopravvenienza' => false,
        ]],
    ]), $condominio->id);

    expect(fn () => (new PagamentoFornitoreService)->registraPagamento(datiPagamento($ctx, $fattura, [
        'metodo_pagamento' => MetodoPagamento::CONTANTI->value,
        'conferma_contanti_fornitore' => true,
    ])))->toThrow(IllegalCashAmountException::class);
});

test('in modifica chiede conferma solo se il pagamento passa ai contanti', function () {
    $ctx = setupPagamentiService();
    [$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId] = $ctx;
    $fattura = registraFatturaServiceTest($ctx);
    $service = new PagamentoFornitoreService;

    $pagamento = $service->registraPagamento(datiPagamento($ctx, $fattura));

    $modifica = [
        'conto_corrente_id' => $contoCorrenteId,
        'data_pagamento' => now()->format('Y-m-d'),
        'metodo_pagamento' => MetodoPagamento::CONTANTI->value,
        'importo_lordo_cents' => $pagamento->importo_lordo,
        'importo_netto_cents' => $pagamento->importo_netto,
        'allow_overdraft' => true,
    ];

    expect(fn () => $service->aggiornaPagamento($pagamento->fresh(), $modifica))
        ->toThrow(PagamentoContantiFornitoreException::class);

    $aggiornato = $service->aggiornaPagamento($pagamento->fresh(), $modifica + ['conferma_contanti_fornitore' => true]);
    expect($aggiornato->metodo_pagamento)->toBe(MetodoPagamento::CONTANTI);

    // Già in contanti: correggerne la data non richiede una nuova conferma.
    $ancora = $service->aggiornaPagamento($aggiornato->fresh(), array_merge($modifica, [
        'data_pagamento' => now()->subDay()->format('Y-m-d'),
    ]));
    expect($ancora->data_pagamento->toDateString())->toBe(now()->subDay()->toDateString());
});

test('dal form la richiesta torna con la chiave che apre la finestra di conferma', function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $utente = User::factory()->create();
    $utente->assignRole($ruolo);

    [$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo] = setupPagamentiHttp();
    $fattura = registraFatturaServiceTest([$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo]);

    $dati = [
        'fornitore_id' => $fornitore->id,
        'esercizio_id' => $esercizio->id,
        'conto_corrente_id' => $contoCorrenteId,
        'data_pagamento' => now()->toDateString(),
        'metodo_pagamento' => MetodoPagamento::CONTANTI->value,
        'allocazioni' => [[
            'fattura_id' => $fattura->id,
            'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
            'importo_allocato_cents' => $fattura->netto_a_pagare,
        ]],
        'allow_overdraft' => true,
    ];

    $this->actingAs($utente)
        ->post(route('admin.gestionale.pagamenti-fornitori.store', [$condominio]), $dati)
        ->assertSessionHasErrors('contanti_fornitore');

    expect(PagamentoFornitore::count())->toBe(0);

    $this->actingAs($utente)
        ->post(route('admin.gestionale.pagamenti-fornitori.store', [$condominio]), $dati + [
            'conferma_contanti_fornitore' => true,
            'nota_override' => 'Contanti: pagato alla consegna, ricevuta allegata',
        ])
        ->assertSessionHasNoErrors();

    expect(PagamentoFornitore::count())->toBe(1);
});
