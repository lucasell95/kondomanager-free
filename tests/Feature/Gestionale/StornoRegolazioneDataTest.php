<?php

/**
 * Data dello storno di una regolazione immediata (collaudo 1, E2) e fuso orario degli storni.
 *
 * Lo storno prendeva come competenza la data di oggi, in UTC: due regolazioni del 22 e 28/07
 * stornate il 18/10 lasciavano la banca a -135,54 € al 31/07 e il registro di cassa con due
 * «entrate» da 177,00 € il 18/10 che in banca non esistono. Ora, a esercizio aperto, lo
 * storno ha la competenza dell'originale; a esercizio chiuso va nell'esercizio aperto con la
 * data di oggi, calcolata nel fuso dell'utente.
 */

use App\Actions\Gestionale\Movimenti\RegistraRegolazioneImmediataAction;
use App\Enums\TipoMovimentoContabile;
use App\Models\Esercizio;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\User;
use App\Services\Gestionale\PagamentoFornitoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    $this->admin = User::factory()->create(['email' => 'bianchi.admin@test.it']);
    $this->admin->assignRole($ruolo);
});

afterEach(fn () => Carbon::setTestNow());

/** Una regolazione immediata da 177,00 € sulla banca, con la data indicata. */
function stornoDataRegolazione(array $ctx, string $data): ScritturaContabile
{
    [$condominio, $esercizio, $gestione, , , $capitolo] = $ctx;

    return (new RegistraRegolazioneImmediataAction)->execute([
        'gestione_id' => $gestione->id,
        'esercizio_id' => $esercizio->id,
        'conto_id' => $capitolo->id,
        'cassa_id' => DB::table('casse')->where('condominio_id', $condominio->id)->value('id'),
        'fornitore_id' => null,
        'data_operazione' => $data,
        'causale' => 'Acconto tabelle millesimali',
        'importo' => 177.00,
    ], $condominio, $esercizio);
}

/** Saldo del conto banca (dare - avere) per competenza fino alla data indicata. */
function stornoDataSaldoBanca(int $contoBancaId, string $finoAl): int
{
    return (int) DB::table('righe_scritture as rs')
        ->join('scritture_contabili as sc', 'sc.id', '=', 'rs.scrittura_id')
        ->where('rs.conto_contabile_id', $contoBancaId)
        ->whereNull('sc.deleted_at')
        ->whereDate('sc.data_competenza', '<=', $finoAl)
        ->selectRaw("COALESCE(SUM(CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE -rs.importo END), 0) as saldo")
        ->value('saldo');
}

test('a esercizio aperto lo storno ha la competenza dell\'operazione stornata', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-18 20:40:00', 'UTC'));
    $ctx = setupPagamentiService();
    [$condominio, , , , $contoBancaId] = $ctx;

    $originale = stornoDataRegolazione($ctx, '2026-07-22');
    expect(stornoDataSaldoBanca($contoBancaId, '2026-07-31'))->toBe(-17700);

    $this->actingAs($this->admin)
        ->post(route('admin.gestionale.regolazioni-immediate.storno', [$condominio, $originale]), [
            'motivo' => 'Riclassificazione come fattura del fornitore',
        ])
        ->assertSessionHasNoErrors();

    $storno = ScritturaContabile::where('scrittura_padre_id', $originale->id)
        ->where('tipo_movimento', TipoMovimentoContabile::STORNO_REGOLAZIONE_IMMEDIATA)
        ->firstOrFail();

    expect($storno->data_competenza->toDateString())->toBe('2026-07-22')
        ->and($storno->data_registrazione->toDateString())->toBe('2026-10-18')
        ->and($storno->esercizio_id)->toBe($originale->esercizio_id);

    // Il saldo «al giorno» della banca torna quello di prima già dal 22/07, non dal 18/10.
    expect(stornoDataSaldoBanca($contoBancaId, '2026-07-31'))->toBe(0)
        ->and(stornoDataSaldoBanca($contoBancaId, '2026-09-30'))->toBe(0);
});

test('a esercizio chiuso lo storno va nell\'esercizio aperto con la data di oggi in ora italiana', function () {
    $ctx = setupPagamentiService();
    [$condominio, $esercizio] = $ctx;

    Carbon::setTestNow(Carbon::parse('2026-10-18 12:00:00', 'UTC'));
    $originale = stornoDataRegolazione($ctx, '2026-07-22');

    $esercizio->update(['stato' => 'chiuso']);
    $aperto = Esercizio::factory()->create([
        'condominio_id' => $condominio->id,
        'nome' => 'Esercizio 2027',
        'stato' => 'aperto',
        'data_inizio' => '2027-01-01',
        'data_fine' => '2027-12-31',
    ]);

    // 22:30 UTC del 18/10 = 00:30 del 19/10 in Italia.
    Carbon::setTestNow(Carbon::parse('2026-10-18 22:30:00', 'UTC'));

    $this->actingAs($this->admin)
        ->post(route('admin.gestionale.regolazioni-immediate.storno', [$condominio, $originale]), [
            'motivo' => 'Errore rilevato dopo la chiusura',
        ])
        ->assertSessionHasNoErrors();

    $storno = ScritturaContabile::where('scrittura_padre_id', $originale->id)->firstOrFail();

    expect($storno->esercizio_id)->toBe($aperto->id)
        ->and($storno->data_competenza->toDateString())->toBe('2026-10-19')
        ->and($storno->data_registrazione->toDateString())->toBe('2026-10-19')
        ->and($storno->causale)->toContain('Originale in esercizio');
});

test('la data di registrazione di una regolazione è il giorno italiano, non quello UTC', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-18 22:30:00', 'UTC'));
    $ctx = setupPagamentiService();

    $originale = stornoDataRegolazione($ctx, '2026-10-18');

    expect($originale->fresh()->data_registrazione->toDateString())->toBe('2026-10-19');
});

test('lo storno di un pagamento fornitore dopo la mezzanotte italiana porta la data italiana', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-18 12:00:00', 'UTC'));
    $ctx = setupPagamentiService();
    $fattura = registraFatturaServiceTest($ctx);
    $service = new PagamentoFornitoreService;
    $pagamento = $service->registraPagamento(datiPagamento($ctx, $fattura, ['data_pagamento' => '2026-10-18']));

    Carbon::setTestNow(Carbon::parse('2026-10-18 22:30:00', 'UTC'));
    $storno = $service->stornaPagamento($pagamento, 'Bonifico respinto dalla banca');

    expect($storno->data_pagamento->toDateString())->toBe('2026-10-19')
        ->and($storno->scrittura->data_registrazione->toDateString())->toBe('2026-10-19')
        ->and($storno->scrittura->data_competenza->toDateString())->toBe('2026-10-19');
});
