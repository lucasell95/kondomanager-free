<?php

/**
 * Helper condivisi dai test del modulo Assemblee. Nessun test qui dentro.
 *
 * Dati fittizi: sei condòmini Rossi, Bianchi, Verdi, Neri, Gialli e Ferrari, ognuno con un'unità,
 * millesimi realistici che sommano a 1000 (180, 170, 160, 150, 175, 165).
 */

use App\Enums\Assemblee\StatoAssemblea;
use App\Models\Anagrafica;
use App\Models\Assemblee\Assemblea;
use App\Models\Condominio;
use App\Models\Immobile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

const ASS_MILLESIMI = ['Rossi Mario' => '180', 'Bianchi Anna' => '170', 'Verdi Luca' => '160', 'Neri Paola' => '150', 'Gialli Carlo' => '175', 'Ferrari Elena' => '165'];

function assAdmin(): User
{
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);

    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole($ruolo);

    return $user;
}

function assNuovaAnagrafica(string $nome, int $i, ?int $userId = null): Anagrafica
{
    $slug = strtolower(str_replace(' ', '.', $nome));

    return Anagrafica::create([
        'user_id' => $userId,
        'nome' => $nome,
        'indirizzo' => 'Via Prova '.$i.', Testopoli',
        'email' => $slug.'.'.$i.uniqid().'@test.it',
        'codice_fiscale' => 'TSTTST'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT),
    ]);
}

/**
 * Un condominio di prova: tabella «Proprietà generale», unità e titolari.
 *
 * @param  array<string,string>  $millesimi  nome => millesimi
 * @return array{condominio:Condominio, tabella_id:int, anagrafiche:array<string,Anagrafica>, immobili:array<string,Immobile>}
 */
function assCondominio(array $millesimi = ASS_MILLESIMI): array
{
    $condominio = Condominio::factory()->create(['nome' => 'Condominio di prova']);

    $tabellaId = DB::table('tabelle')->insertGetId([
        'condominio_id' => $condominio->id,
        'nome' => 'Proprietà generale',
        'tipo' => 'standard',
        'quota' => 'millesimi',
        'numero_decimali' => 3,
        'attiva' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $anagrafiche = [];
    $immobili = [];
    $i = 0;

    foreach ($millesimi as $nome => $valore) {
        $i++;
        $a = assNuovaAnagrafica($nome, $i);
        $condominio->anagrafiche()->attach($a->id);

        $imm = Immobile::create([
            'condominio_id' => $condominio->id,
            'nome' => 'Appartamento '.$i,
            'descrizione' => 'Unità di prova',
            'interno' => (string) $i,
            'attivo' => true,
        ]);

        DB::table('anagrafica_immobile')->insert([
            'immobile_id' => $imm->id,
            'anagrafica_id' => $a->id,
            'tipologia' => 'proprietario',
            'quota' => 100,
            'data_inizio' => '2020-01-01',
            'attivo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('quote_tabella')->insert([
            'tabella_id' => $tabellaId,
            'immobile_id' => $imm->id,
            'valore' => $valore,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $anagrafiche[$nome] = $a;
        $immobili[$nome] = $imm;
    }

    return ['condominio' => $condominio, 'tabella_id' => $tabellaId, 'anagrafiche' => $anagrafiche, 'immobili' => $immobili];
}

function assAssemblea(Condominio $condominio, array $attributi = []): Assemblea
{
    $assemblea = $condominio->assemblee()->create($attributi + [
        'tipo' => 'ordinaria',
        'stato' => StatoAssemblea::CONVOCATA,
        'prima_convocazione_at' => '2026-11-20 21:00:00',
        'prima_luogo' => 'Androne del condominio',
        'seconda_convocazione_at' => '2026-11-23 21:00:00',
    ]);

    app(\App\Services\Assemblee\FotografiaUnitaService::class)->fotografa($assemblea);

    return $assemblea->fresh();
}

function assPresenti(Assemblea $assemblea, array $anagrafiche, array $nomi): void
{
    foreach ($anagrafiche as $nome => $a) {
        $assemblea->presenze()->updateOrCreate(['anagrafica_id' => $a->id], [
            'modo' => in_array($nome, $nomi, true) ? 'presente' : 'assente',
        ]);
    }
}

/** Le rotte del gestionale vogliono {condominio} e {assemblea}. */
function assRotta(string $nome, Assemblea $assemblea, array $extra = []): string
{
    return route('admin.gestionale.assemblee.'.$nome, ['condominio' => $assemblea->condominio_id, 'assemblea' => $assemblea->id] + $extra);
}
