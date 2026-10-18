<?php

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\RigaScrittura;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * «Annulla emissione» cancella le scritture dell'emissione. Due protezioni:
 *
 * - non su un esercizio chiuso: toglierebbe dal registro addebiti già rendicontati;
 * - non se il giornale ha incassi o compensazioni validi su quella rata, anche quando le quote
 *   (l'altra fonte di verità) non lo dicono.
 */
function annEmScenario(): object
{
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $user = User::factory()->create();
    $user->assignRole($ruolo);

    $condominio = Condominio::create([
        'nome' => 'Condominio Annulla Emissione', 'uuid' => (string) Str::uuid(),
        'indirizzo' => 'Via Roma 1', 'citta' => 'Milano', 'cap' => '20100', 'provincia' => 'MI',
    ]);
    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id, 'nome' => 'Esercizio 2026/2027', 'descrizione' => '2026/2027',
        'data_inizio' => '2026-06-01', 'data_fine' => '2027-05-31', 'stato' => 'aperto',
    ]);
    $gestione = Gestione::create([
        'condominio_id' => $condominio->id, 'nome' => 'ORDINARIA', 'tipo' => 'ordinaria',
        'data_inizio' => '2026-06-01', 'data_fine' => '2027-05-31',
    ]);
    $gestione->esercizi()->attach($esercizio->id, ['attiva' => true]);

    $crediti = ContoContabile::create([
        'condominio_id' => $condominio->id, 'ruolo' => 'crediti_condomini', 'codice' => '1101',
        'nome' => 'Crediti verso Condomini', 'tipo' => 'attivo', 'categoria' => 'crediti',
    ]);
    ContoContabile::create([
        'condominio_id' => $condominio->id, 'ruolo' => 'gestione_rate', 'codice' => '3001',
        'nome' => 'Gestione Rate', 'tipo' => 'passivo', 'categoria' => 'fondi',
    ]);

    $anagrafica = Anagrafica::create([
        'condominio_id' => $condominio->id, 'nome' => 'Luigi Bianchi',
        'email' => 'bianchi.annulla@test.it', 'indirizzo' => 'Via Verdi 1', 'cap' => '00100',
        'citta' => 'Roma', 'provincia' => 'RM', 'codice_fiscale' => 'TSTTST80A01H501F',
    ]);
    $condominio->anagrafiche()->attach($anagrafica->id);
    $immobile = Immobile::create([
        'condominio_id' => $condominio->id, 'nome' => 'Appartamento', 'descrizione' => 'Appartamento',
        'interno' => '1', 'foglio' => '1', 'particella' => '1', 'subalterno' => '1',
    ]);

    $piano = PianoRate::create([
        'condominio_id' => $condominio->id, 'gestione_id' => $gestione->id,
        'nome' => 'ORDINARIO 2026/27', 'numero_rate' => 1, 'stato' => 'approvato',
    ]);
    $rata = Rata::create([
        'piano_rate_id' => $piano->id, 'numero_rata' => 1,
        'data_scadenza' => '2026-09-01', 'importo_totale' => 15000, 'stato' => 'bozza',
    ]);
    RataQuote::create([
        'rata_id' => $rata->id, 'anagrafica_id' => $anagrafica->id, 'immobile_id' => $immobile->id,
        'importo' => 15000, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'data_scadenza' => '2026-09-01',
    ]);

    $s = (object) compact('user', 'condominio', 'esercizio', 'gestione', 'crediti', 'anagrafica', 'immobile', 'piano', 'rata');

    // Emissione vera, dal controller.
    test()->actingAs($user)
        ->post(route('admin.gestionale.piani-rate.emetti', ['condominio' => $condominio->id, 'pianoRate' => $piano->id]), [
            'rate_ids' => [$rata->id], 'data_emissione' => '2026-08-01', 'invia_notifiche' => false,
        ])
        ->assertRedirect();

    $s->emissione = ScritturaContabile::where('tipo_movimento', 'emissione_rata')->where('condominio_id', $condominio->id)->firstOrFail();

    // L'emissione porta chi l'ha fatta (B9).
    expect($s->emissione->created_by)->toBe($user->id);

    return $s;
}

function annEmAnnulla(object $s)
{
    return test()->actingAs($s->user)
        ->from('/piano')
        ->delete(route('admin.gestionale.piani-rate.annulla-emissione', [
            'condominio' => $s->condominio->id, 'pianoRate' => $s->piano->id, 'rata' => $s->rata->id,
        ]));
}

test("l'annullamento normale funziona come prima", function () {
    $s = annEmScenario();

    annEmAnnulla($s)->assertRedirect('/piano')->assertSessionHas('message.type', 'success');

    expect(ScritturaContabile::withTrashed()->whereKey($s->emissione->id)->exists())->toBeFalse()
        ->and($s->rata->fresh()->stato)->toBe('bozza');
});

test("non si annulla un'emissione registrata in un esercizio chiuso", function () {
    $s = annEmScenario();

    $s->esercizio->update(['stato' => 'chiuso']);
    Esercizio::create([
        'condominio_id' => $s->condominio->id, 'nome' => 'Esercizio 2027/2028', 'descrizione' => '2027/2028',
        'data_inizio' => '2027-06-01', 'data_fine' => '2028-05-31', 'stato' => 'aperto',
    ]);

    annEmAnnulla($s)
        ->assertRedirect('/piano')
        ->assertSessionHas('message.type', 'error');

    expect(ScritturaContabile::whereKey($s->emissione->id)->exists())->toBeTrue()
        ->and($s->rata->fresh()->stato)->toBe('emessa');
});

test('non si annulla se il giornale ha un incasso valido sulla rata, anche con le quote a zero', function () {
    $s = annEmScenario();

    // Un incasso a giornale sulla rata che le quote non riportano (le due fonti divergono).
    $incasso = ScritturaContabile::create([
        'condominio_id' => $s->condominio->id, 'esercizio_id' => $s->esercizio->id, 'gestione_id' => $s->gestione->id,
        'data_registrazione' => '2026-08-10', 'data_competenza' => '2026-08-10',
        'causale' => 'Incasso', 'tipo_movimento' => 'incasso_rata', 'stato' => 'registrata',
    ]);
    RigaScrittura::create([
        'scrittura_id' => $incasso->id, 'conto_contabile_id' => $s->crediti->id, 'tipo_riga' => 'avere',
        'importo' => 15000, 'anagrafica_id' => $s->anagrafica->id, 'immobile_id' => $s->immobile->id, 'rata_id' => $s->rata->id,
    ]);

    annEmAnnulla($s)->assertSessionHas('message.type', 'error');
    expect(ScritturaContabile::whereKey($s->emissione->id)->exists())->toBeTrue();

    // Stornato l'incasso, l'annullamento torna possibile.
    $incasso->update(['stato' => 'annullata']);
    annEmAnnulla($s)->assertSessionHas('message.type', 'success');
    expect(ScritturaContabile::withTrashed()->whereKey($s->emissione->id)->exists())->toBeFalse();
});
