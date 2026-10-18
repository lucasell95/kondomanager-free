<?php

use App\Actions\Gestionale\Movimenti\StoreIncassoRateAction;
use App\Exceptions\Gestionale\IncassoSenzaGestioneException;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\PianoRate;
use App\Models\User;
use App\Services\CondominioService;
use App\Traits\HasEsercizio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * Esercizio 01/06–31/05 e passaggio d'esercizio.
 *
 * Il programma crea l'esercizio sull'anno solare (CondominioService, non si cambia); qui si
 * verifica che lo si possa portare a 01/06–31/05, chiudere e aprire il successivo, e che nei
 * casi limite — due esercizi aperti (ripristino di un backup), nessun esercizio aperto — le
 * pagine e le registrazioni principali non vadano in errore.
 */
function passEsAdmin(): User
{
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $user = User::factory()->create();
    $user->assignRole($ruolo);

    return $user;
}

function passEsCondominio(): Condominio
{
    return app(CondominioService::class)->createCondominioWithEsercizio([
        'nome' => 'Condominio Giugno', 'uuid' => (string) Str::uuid(),
        'indirizzo' => 'Via Roma 1', 'citta' => 'Milano', 'cap' => '20100', 'provincia' => 'MI',
    ]);
}

/** Espone il trait per provarlo da solo. */
function passEsCorrente(Condominio $condominio): ?Esercizio
{
    $sonda = new class { use HasEsercizio; public function corrente($c) { return $this->getEsercizioCorrente($c); } };

    return $sonda->corrente($condominio);
}

test("l'esercizio creato sull'anno solare si porta a 01/06–31/05, si chiude e si apre il successivo", function () {
    $user = passEsAdmin();
    $condominio = passEsCondominio();
    $primo = $condominio->esercizi()->firstOrFail();

    $this->actingAs($user)
        ->put(route('admin.gestionale.esercizi.update', [$condominio, $primo]), [
            'nome' => 'Esercizio 2026/2027', 'descrizione' => 'Dal 01/06/2026 al 31/05/2027',
            'data_inizio' => '2026-06-01', 'data_fine' => '2027-05-31', 'stato' => 'aperto',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.gestionale.esercizi.index', $condominio));

    expect($primo->fresh()->data_inizio->toDateString())->toBe('2026-06-01')
        ->and($primo->fresh()->data_fine->toDateString())->toBe('2027-05-31');

    // Finché il primo è aperto non se ne apre un secondo: è il vincolo «un solo esercizio aperto».
    $nuovo = [
        'nome' => 'Esercizio 2027/2028', 'descrizione' => 'Dal 01/06/2027 al 31/05/2028',
        'data_inizio' => '2027-06-01', 'data_fine' => '2028-05-31', 'stato' => 'aperto',
    ];
    $this->actingAs($user)->post(route('admin.gestionale.esercizi.store', $condominio), $nuovo)
        ->assertSessionHasErrors('stato');

    // Chiuso il primo, il secondo si apre.
    $this->actingAs($user)
        ->put(route('admin.gestionale.esercizi.update', [$condominio, $primo]), [
            'nome' => 'Esercizio 2026/2027', 'descrizione' => 'Dal 01/06/2026 al 31/05/2027',
            'data_inizio' => '2026-06-01', 'data_fine' => '2027-05-31', 'stato' => 'chiuso',
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($user)->post(route('admin.gestionale.esercizi.store', $condominio), $nuovo)
        ->assertSessionHasNoErrors();

    $secondo = $condominio->esercizi()->where('nome', 'Esercizio 2027/2028')->firstOrFail();
    expect(passEsCorrente($condominio)?->id)->toBe($secondo->id);
});

test("con due esercizi aperti l'esercizio corrente è quello che contiene oggi, e le pagine rispondono", function () {
    $user = passEsAdmin();
    $condominio = passEsCondominio();
    $primo = $condominio->esercizi()->firstOrFail();
    $primo->update(['nome' => '2026/2027', 'data_inizio' => '2026-06-01', 'data_fine' => '2027-05-31']);

    // Come dopo il ripristino di un backup anteriore al vincolo: due aperti, scritti senza la
    // FormRequest.
    $secondo = Esercizio::create([
        'condominio_id' => $condominio->id, 'nome' => '2027/2028', 'descrizione' => '2027/2028',
        'data_inizio' => '2027-06-01', 'data_fine' => '2028-05-31', 'stato' => 'aperto',
    ]);

    Carbon::setTestNow('2027-07-15 10:00:00');
    expect(passEsCorrente($condominio)?->id)->toBe($secondo->id);

    Carbon::setTestNow('2027-03-01 10:00:00');
    expect(passEsCorrente($condominio)?->id)->toBe($primo->id);

    // Fuori da entrambi: il più recente.
    Carbon::setTestNow('2029-01-10 10:00:00');
    expect(passEsCorrente($condominio)?->id)->toBe($secondo->id);

    Carbon::setTestNow('2027-07-15 10:00:00');

    $anagrafica = Anagrafica::factory()->create();
    $condominio->anagrafiche()->attach($anagrafica->id);

    foreach ([
        route('admin.gestionale.esercizi.index', $condominio),
        route('admin.gestionale.movimenti-rate.index', $condominio),
        route('admin.gestionale.esercizi.scritture.index', [$condominio, $secondo]),
        route('admin.gestionale.esercizi.scritture.index', [$condominio, $primo]),
        route('admin.gestionale.esercizi.piani-rate.index', [$condominio, $secondo]),
        route('admin.gestionale.anagrafiche.estratto-conto', ['condominio' => $condominio->id, 'anagrafica' => $anagrafica->id]),
    ] as $url) {
        $this->actingAs($user)->get($url)->assertOk();
    }

    $this->actingAs($user)
        ->get(route('admin.gestionale.anagrafiche.estratto-conto', ['condominio' => $condominio->id, 'anagrafica' => $anagrafica->id]))
        ->assertInertia(fn (Assert $page) => $page->where('esercizio.id', $secondo->id));

    Carbon::setTestNow();
});

test("senza esercizi aperti l'emissione risponde con un messaggio invece di un errore tecnico", function () {
    $user = passEsAdmin();
    $condominio = passEsCondominio();
    $esercizio = $condominio->esercizi()->firstOrFail();
    $gestione = $condominio->gestioni()->firstOrFail();
    $esercizio->update(['stato' => 'chiuso']);

    $piano = PianoRate::create([
        'condominio_id' => $condominio->id, 'gestione_id' => $gestione->id,
        'nome' => 'Ordinario', 'numero_rate' => 1, 'stato' => 'approvato',
    ]);
    $rata = $piano->rate()->create(['numero_rata' => 1, 'data_scadenza' => now()->addMonth(), 'importo_totale' => 0, 'stato' => 'bozza']);

    $this->actingAs($user)
        ->post(route('admin.gestionale.piani-rate.emetti', ['condominio' => $condominio->id, 'pianoRate' => $piano->id]), [
            'rate_ids' => [$rata->id], 'data_emissione' => now()->toDateString(),
        ])
        ->assertSessionHas('message.type', 'error')
        ->assertSessionHas('message.message', fn ($m) => str_contains($m, 'esercizio aperto'));
});

test("un incasso in un esercizio senza gestioni è rifiutato con un motivo, non con un errore 500", function () {
    [$condominio, , $gestione] = setupContabile();

    // L'esercizio nuovo, appena creato: nessuna gestione ancora.
    $nuovo = Esercizio::create([
        'condominio_id' => $condominio->id, 'nome' => '2027', 'descrizione' => '2027',
        'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto',
    ]);

    $anagrafica = Anagrafica::factory()->create();
    $condominio->anagrafiche()->attach($anagrafica->id);
    $contoBanca = DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominio->id, 'ruolo' => 'conto_bancario', 'codice' => '1010',
        'nome' => 'Banca', 'tipo' => 'attivo', 'categoria' => 'liquidita', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $cassa = Cassa::create([
        'condominio_id' => $condominio->id, 'nome' => 'Banca', 'tipo' => 'banca',
        'conto_contabile_id' => $contoBanca, 'saldo_iniziale' => 0, 'attiva' => true,
    ]);

    // Un anticipo senza rate: la gestione non si ricava da nessuna quota.
    expect(fn () => app(StoreIncassoRateAction::class)->execute([
        'pagante_id' => $anagrafica->id, 'cassa_id' => $cassa->id, 'gestione_id' => null,
        'data_pagamento' => '2027-02-01', 'descrizione' => 'Anticipo',
        'importo_totale' => 50.00, 'eccedenza' => 50.00, 'dettaglio_pagamenti' => [],
    ], $condominio, $nuovo))->toThrow(IncassoSenzaGestioneException::class);

    expect(DB::table('scritture_contabili')->where('esercizio_id', $nuovo->id)->count())->toBe(0);
});
