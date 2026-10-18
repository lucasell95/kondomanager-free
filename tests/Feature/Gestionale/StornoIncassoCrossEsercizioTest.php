<?php

use App\Models\Esercizio;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * Storno di un incasso che sta in un esercizio **chiuso**: la rettifica va nell'esercizio aperto,
 * come per giroconti, regolazioni e pagamenti (Variante B1 di StornaGirocontoAction). Prima finiva
 * nell'esercizio chiuso e ne alterava i conti già rendicontati.
 */
function stornoCrossAdmin(): User
{
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $user = User::factory()->create();
    $user->assignRole($ruolo);

    return $user;
}

/** Incasso bilanciato nell'esercizio di setupContabile(), che poi viene chiuso. */
function stornoCrossScenario(): object
{
    [$condominio, $esercizio, $gestione] = setupContabile();

    $crediti = ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'crediti_condomini')->firstOrFail();
    $passate = ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'passate_gestioni')->firstOrFail();

    $incasso = ScritturaContabile::create([
        'condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id, 'gestione_id' => $gestione->id,
        'data_registrazione' => '2026-03-10', 'data_competenza' => '2026-03-10',
        'causale' => 'Incasso rata 1', 'tipo_movimento' => 'incasso_rata', 'stato' => 'registrata',
    ]);
    $incasso->righe()->create(['conto_contabile_id' => $crediti->id, 'tipo_riga' => 'avere', 'importo' => 10000]);
    $incasso->righe()->create(['conto_contabile_id' => $passate->id, 'tipo_riga' => 'dare', 'importo' => 10000]);

    $esercizio->update(['stato' => 'chiuso']);

    return (object) compact('condominio', 'esercizio', 'gestione', 'incasso');
}

test("lo storno di un incasso di un esercizio chiuso si registra nell'esercizio aperto", function () {
    $user = stornoCrossAdmin();
    $s = stornoCrossScenario();

    $aperto = Esercizio::create([
        'condominio_id' => $s->condominio->id, 'nome' => 'Esercizio 2027', 'descrizione' => '2027',
        'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto',
    ]);

    $this->actingAs($user)
        ->post(route('admin.gestionale.movimenti-rate.storno', ['condominio' => $s->condominio->id, 'scrittura' => $s->incasso->id]))
        ->assertRedirect();

    $rettifica = ScritturaContabile::where('scrittura_padre_id', $s->incasso->id)->where('tipo_movimento', 'rettifica')->firstOrFail();

    expect($rettifica->esercizio_id)->toBe($aperto->id)
        ->and($rettifica->data_competenza->toDateString())->toBe(now()->toDateString())
        ->and($rettifica->causale)->toContain('Esercizio 2026')
        ->and($s->incasso->fresh()->stato)->toBe('annullata');

    // L'esercizio chiuso non ha ricevuto nessuna scrittura nuova.
    expect(ScritturaContabile::where('esercizio_id', $s->esercizio->id)->count())->toBe(1);
});

test('senza un esercizio aperto lo storno è rifiutato con un messaggio e non scrive nulla', function () {
    $user = stornoCrossAdmin();
    $s = stornoCrossScenario();

    $this->actingAs($user)
        ->from('/indietro')
        ->post(route('admin.gestionale.movimenti-rate.storno', ['condominio' => $s->condominio->id, 'scrittura' => $s->incasso->id]))
        ->assertRedirect('/indietro')
        ->assertSessionHas('message');

    expect($s->incasso->fresh()->stato)->toBe('registrata')
        ->and(ScritturaContabile::where('scrittura_padre_id', $s->incasso->id)->count())->toBe(0);
});

test("con l'esercizio dell'incasso aperto lo storno resta lì, con la data dell'incasso", function () {
    $user = stornoCrossAdmin();
    $s = stornoCrossScenario();
    $s->esercizio->update(['stato' => 'aperto']);

    $this->actingAs($user)
        ->post(route('admin.gestionale.movimenti-rate.storno', ['condominio' => $s->condominio->id, 'scrittura' => $s->incasso->id]))
        ->assertRedirect();

    $rettifica = ScritturaContabile::where('scrittura_padre_id', $s->incasso->id)->firstOrFail();

    expect($rettifica->esercizio_id)->toBe($s->esercizio->id)
        ->and($rettifica->data_competenza->toDateString())->toBe('2026-03-10')
        ->and($rettifica->causale)->toBe('Storno: Incasso rata 1');
});
