<?php

/**
 * Distinta di pagamento fornitore: nome del file e ora di stampa (collaudo 1, E5 ed E6).
 *
 * - E5: il file si chiamava «Distinta_PAG-PAG-2026-00001_20260722.pdf», perché il formato
 *   aggiungeva «PAG-» a un protocollo che lo contiene già.
 * - E6: «Data di stampa» era l'ora UTC del server (20:50 quando in Italia erano le 22:50).
 */

use App\Helpers\DateHelper;
use App\Models\User;
use App\Services\Gestionale\PagamentoFornitoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);

    $this->admin = User::factory()->create(['email' => 'rossi.admin@test.it']);
    $this->admin->assignRole($ruolo);
});

afterEach(fn () => Carbon::setTestNow());

/** Un pagamento registrato il 22/07/2026, con la sua scrittura protocollata. */
function distintaTestPagamento(): array
{
    $ctx = setupPagamentiService();
    $fattura = registraFatturaServiceTest($ctx, ['data_documento' => '2026-07-21', 'data_scadenza' => '2026-07-28']);
    $pagamento = (new PagamentoFornitoreService)->registraPagamento(
        datiPagamento($ctx, $fattura, ['data_pagamento' => '2026-07-22'])
    );

    return [$ctx[0], $pagamento->fresh(['scrittura', 'fornitore', 'contoCorrente'])];
}

test('il nome del file della distinta non ripete il prefisso PAG', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-18 20:50:00', 'UTC'));
    [$condominio, $pagamento] = distintaTestPagamento();

    $protocollo = $pagamento->scrittura->numero_protocollo;
    expect($protocollo)->toStartWith('PAG-');

    $risposta = $this->actingAs($this->admin)->get(route('admin.gestionale.pagamenti-fornitori.distinta', [
        'condominio' => $condominio->id,
        'pagamento' => $pagamento->id,
    ]));

    $risposta->assertOk();
    $disposizione = (string) $risposta->headers->get('Content-Disposition');

    expect($disposizione)->toContain("Distinta_{$protocollo}_20260722.pdf")
        ->and($disposizione)->not->toContain('PAG-PAG-');
});

test('la data di stampa della distinta è in ora italiana', function () {
    // 20:50 UTC del 18/10/2026 = 22:50 in Italia (ora legale, UTC+2).
    Carbon::setTestNow(Carbon::parse('2026-10-18 20:50:00', 'UTC'));
    [$condominio, $pagamento] = distintaTestPagamento();

    $html = view('pdf.gestionale.distinta', [
        'condominio' => $condominio,
        'pagamento' => $pagamento,
        'fornitore' => $pagamento->fornitore,
        'scrittura' => $pagamento->scrittura,
        'fatture' => $pagamento->scrittura->fatture ?? collect(),
    ])->render();

    expect($html)->toContain('Data di stampa: 18/10/2026 22:50')
        ->and($html)->not->toContain('18/10/2026 20:50');
});

test('dopo la mezzanotte italiana la stampa porta già la data del giorno dopo', function () {
    // 22:30 UTC = 00:30 del giorno dopo in Italia.
    Carbon::setTestNow(Carbon::parse('2026-10-18 22:30:00', 'UTC'));
    [$condominio, $pagamento] = distintaTestPagamento();

    $html = view('pdf.gestionale.distinta', [
        'condominio' => $condominio,
        'pagamento' => $pagamento,
        'fornitore' => $pagamento->fornitore,
        'scrittura' => $pagamento->scrittura,
        'fatture' => collect(),
    ])->render();

    expect($html)->toContain('Data di stampa: 19/10/2026 00:30')
        ->and($html)->toContain('Documento emesso il 19/10/2026');
});

test('DateHelper: oggi e adesso nel fuso utente, i timestamp dell\'app restano in UTC', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-18 22:30:00', 'UTC'));

    expect(config('app.timezone'))->toBe('UTC')
        ->and(now()->toDateString())->toBe('2026-10-18')
        ->and(DateHelper::oggiUtente())->toBe('2026-10-19')
        ->and(DateHelper::adessoUtente()->format('d/m/Y H:i'))->toBe('19/10/2026 00:30')
        ->and(DateHelper::adessoUtente()->utc()->format('Y-m-d H:i'))->toBe('2026-10-18 22:30')
        ->and(DateHelper::inFusoUtente(Carbon::parse('2026-01-10 08:00:00', 'UTC'))->format('H:i'))->toBe('09:00')
        ->and(DateHelper::inFusoUtente(null))->toBeNull();

    // In inverno l'Italia è a UTC+1.
    Carbon::setTestNow(Carbon::parse('2026-12-31 23:15:00', 'UTC'));
    expect(DateHelper::oggiUtente())->toBe('2027-01-01');
});
