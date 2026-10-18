<?php

use App\Actions\Gestionale\Movimenti\StoreIncassoRateAction;
use App\Models\Anagrafica;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\User;
use App\Services\Gestionale\FatturaPassivaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * Chi ha registrato una scrittura: `created_by` valorizzato su fattura, incasso, compensazione e
 * rettifica di storno (prima restava vuoto), e il dettaglio dell'incasso che dice chi lo ha
 * stornato (prima il ramo controllava uno stato inesistente e non si eseguiva mai).
 */
function autoreAdmin(string $nome): User
{
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $user = User::factory()->create(['name' => $nome]);
    $user->assignRole($ruolo);

    return $user;
}

test('la scrittura della fattura porta chi l\'ha registrata', function () {
    $user = autoreAdmin('Paolo Rossi');
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = setupContabile();

    $this->actingAs($user);

    $fattura = (new FatturaPassivaService())->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'righe' => [[
                'descrizione' => 'Pulizie', 'importo_imponibile' => 100, 'aliquota_iva' => 22,
                'conto_id' => $capitolo->id, 'is_sopravvenienza' => false,
            ]],
        ]),
        $condominio->id
    );

    expect($fattura->scritture()->first()->created_by)->toBe($user->id);
});

test("incasso e storno portano l'autore, e il dettaglio dell'incasso nomina chi l'ha stornato", function () {
    $chiIncassa = autoreAdmin('Paolo Rossi');
    $chiStorna  = autoreAdmin('Giulia Bianchi');
    [$condominio, $esercizio, $gestione, , , , $immobileId] = setupContabile();

    $anagrafica = Anagrafica::factory()->create();
    $condominio->anagrafiche()->attach($anagrafica->id);

    $piano = PianoRate::create([
        'condominio_id' => $condominio->id, 'gestione_id' => $gestione->id, 'nome' => 'Ordinario', 'numero_rate' => 1,
    ]);
    $rata = Rata::create([
        'piano_rate_id' => $piano->id, 'numero_rata' => 1, 'data_scadenza' => '2026-03-01', 'importo_totale' => 10000, 'stato' => 'emessa',
    ]);
    $quota = RataQuote::create([
        'rata_id' => $rata->id, 'anagrafica_id' => $anagrafica->id, 'immobile_id' => $immobileId,
        'importo' => 10000, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'data_scadenza' => '2026-03-01',
    ]);

    $contoBanca = DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominio->id, 'ruolo' => 'conto_bancario', 'codice' => '1010',
        'nome' => 'Banca', 'tipo' => 'attivo', 'categoria' => 'liquidita', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $cassa = Cassa::create([
        'condominio_id' => $condominio->id, 'nome' => 'Banca', 'tipo' => 'banca',
        'conto_contabile_id' => $contoBanca, 'saldo_iniziale' => 0, 'attiva' => true,
    ]);

    $this->actingAs($chiIncassa);
    app(StoreIncassoRateAction::class)->execute([
        'pagante_id' => $anagrafica->id, 'cassa_id' => $cassa->id, 'gestione_id' => $gestione->id,
        'data_pagamento' => '2026-03-05', 'descrizione' => 'Rata 1',
        'importo_totale' => 100.00, 'eccedenza' => 0,
        'dettaglio_pagamenti' => [['rata_id' => $quota->id, 'importo' => 100.00]],
    ], $condominio, $esercizio);

    $incasso = ScritturaContabile::where('tipo_movimento', 'incasso_rata')->where('condominio_id', $condominio->id)->firstOrFail();
    expect($incasso->created_by)->toBe($chiIncassa->id);

    $this->actingAs($chiStorna)
        ->post(route('admin.gestionale.movimenti-rate.storno', ['condominio' => $condominio->id, 'scrittura' => $incasso->id]))
        ->assertRedirect();

    $rettifica = ScritturaContabile::where('scrittura_padre_id', $incasso->id)->where('tipo_movimento', 'rettifica')->firstOrFail();
    expect($rettifica->created_by)->toBe($chiStorna->id);

    $this->actingAs($chiStorna)
        ->get(route('admin.gestionale.movimenti-rate.show', ['condominio' => $condominio->id, 'scrittura' => $incasso->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('utenteCreatore', 'Paolo Rossi')
            ->where('utenteStornatore', 'Giulia Bianchi'));
});
