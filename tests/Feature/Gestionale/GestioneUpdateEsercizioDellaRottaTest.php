<?php

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Modificare una gestione la aggiorna **nell'esercizio da cui la si modifica**.
 *
 * Prima `GestioneController::update()` cercava l'esercizio aperto e ci agganciava la gestione:
 * correggere il nome di una straordinaria del 2026/27 dopo l'apertura del 2027/28 la faceva
 * comparire anche nel 2027/28, attiva, e piani e prospetti la contavano in entrambi gli anni.
 */
function gestUpdScenario(): object
{
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $user = User::factory()->create();
    $user->assignRole($ruolo);

    $condominio = Condominio::factory()->create();
    $vecchio = Esercizio::create([
        'condominio_id' => $condominio->id, 'nome' => '2026/2027', 'descrizione' => '2026/2027',
        'data_inizio' => '2026-06-01', 'data_fine' => '2027-05-31', 'stato' => 'chiuso',
    ]);
    $nuovo = Esercizio::create([
        'condominio_id' => $condominio->id, 'nome' => '2027/2028', 'descrizione' => '2027/2028',
        'data_inizio' => '2027-06-01', 'data_fine' => '2028-05-31', 'stato' => 'aperto',
    ]);

    $gestione = Gestione::create([
        'condominio_id' => $condominio->id, 'nome' => 'Rifacimento tetto', 'descrizione' => 'Lavori tetto',
        'tipo' => 'straordinaria', 'data_inizio' => '2026-09-01', 'data_fine' => '2027-05-31',
    ]);
    $vecchio->gestioni()->attach($gestione->id, [
        'attiva' => true, 'data_inizio' => '2026-09-01', 'data_fine' => '2027-05-31',
    ]);

    return (object) compact('user', 'condominio', 'vecchio', 'nuovo', 'gestione');
}

test("la modifica da un esercizio chiuso non aggancia la gestione all'esercizio aperto", function () {
    $s = gestUpdScenario();

    $this->actingAs($s->user)
        ->put(route('admin.gestionale.esercizi.gestioni.update', [$s->condominio, $s->vecchio, $s->gestione]), [
            'nome' => 'Rifacimento tetto e grondaie', 'descrizione' => 'Lavori tetto',
            'data_inizio' => '2026-09-01', 'data_fine' => '2027-04-30', 'tipo' => 'straordinaria',
        ])
        ->assertRedirect(route('admin.gestionale.esercizi.gestioni.index', [
            'condominio' => $s->condominio->id, 'esercizio' => $s->vecchio->id,
        ]));

    expect($s->gestione->fresh()->nome)->toBe('Rifacimento tetto e grondaie');

    // Nessun legame con l'esercizio nuovo.
    expect(DB::table('esercizio_gestione')->where('gestione_id', $s->gestione->id)->pluck('esercizio_id')->all())
        ->toBe([$s->vecchio->id]);

    // Il legame con l'esercizio della rotta segue le nuove date.
    $pivot = DB::table('esercizio_gestione')
        ->where('gestione_id', $s->gestione->id)->where('esercizio_id', $s->vecchio->id)->first();
    expect(substr((string) $pivot->data_fine, 0, 10))->toBe('2027-04-30');
});

test("la modifica dall'esercizio aperto resta in quell'esercizio", function () {
    $s = gestUpdScenario();
    $s->nuovo->gestioni()->attach($s->gestione->id, ['attiva' => true]);

    $this->actingAs($s->user)
        ->put(route('admin.gestionale.esercizi.gestioni.update', [$s->condominio, $s->nuovo, $s->gestione]), [
            'nome' => 'Tetto', 'descrizione' => 'Lavori tetto',
            'data_inizio' => '2026-09-01', 'data_fine' => '2028-05-31', 'tipo' => 'straordinaria',
        ])
        ->assertRedirect();

    $pivotNuovo = DB::table('esercizio_gestione')
        ->where('gestione_id', $s->gestione->id)->where('esercizio_id', $s->nuovo->id)->first();

    // Intervallo ritagliato sull'esercizio: dal suo inizio alla fine della gestione.
    expect(substr((string) $pivotNuovo->data_inizio, 0, 10))->toBe('2027-06-01')
        ->and(substr((string) $pivotNuovo->data_fine, 0, 10))->toBe('2028-05-31');

    // Il legame col vecchio esercizio non è stato toccato.
    $pivotVecchio = DB::table('esercizio_gestione')
        ->where('gestione_id', $s->gestione->id)->where('esercizio_id', $s->vecchio->id)->first();
    expect(substr((string) $pivotVecchio->data_fine, 0, 10))->toBe('2027-05-31');
});
