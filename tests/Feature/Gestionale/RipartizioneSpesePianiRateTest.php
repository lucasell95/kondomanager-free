<?php

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\User;
use App\Services\PDF\PdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Stampa «Ripartizione spese» del piano dei conti con più piani rate nella stessa gestione.
 *
 * Prima prendeva tutti i piani della gestione, anche quelli disattivati, e metteva nella stessa
 * colonna le rate con lo stesso numero di piani diversi. Qui si guardano i dati che il controller
 * passa al PDF (il PdfService è sostituito da un doppio che li raccoglie).
 */
function ripPianiScenario(): object
{
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $user = User::factory()->create();
    $user->assignRole($ruolo);

    $condominio = Condominio::create([
        'nome' => 'Condominio Riparto', 'uuid' => (string) Str::uuid(),
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
    $pianoConto = PianoConto::create(['gestione_id' => $gestione->id, 'condominio_id' => $condominio->id, 'nome' => 'Preventivo 2026/27']);

    $anagrafica = Anagrafica::create([
        'condominio_id' => $condominio->id, 'nome' => 'Anna Verdi',
        'email' => 'verdi.riparto@test.it', 'indirizzo' => 'Via Verdi 1', 'cap' => '00100',
        'citta' => 'Roma', 'provincia' => 'RM', 'codice_fiscale' => 'TSTTST80A01H501G',
    ]);
    $immobile = Immobile::create([
        'condominio_id' => $condominio->id, 'nome' => 'Appartamento', 'descrizione' => 'Appartamento',
        'interno' => '1', 'foglio' => '1', 'particella' => '1', 'subalterno' => '1', 'codice_immobile' => 'RIP-'.$condominio->id,
    ]);

    $piano = function (string $nome, bool $attivo, array $importiPerRata) use ($condominio, $gestione, $anagrafica, $immobile) {
        $p = PianoRate::create([
            'condominio_id' => $condominio->id, 'gestione_id' => $gestione->id,
            'nome' => $nome, 'numero_rate' => count($importiPerRata), 'attivo' => $attivo,
        ]);
        foreach ($importiPerRata as $i => $importo) {
            $rata = Rata::create([
                'piano_rate_id' => $p->id, 'numero_rata' => $i + 1,
                'data_scadenza' => sprintf('2026-%02d-01', 7 + $i * 3), 'importo_totale' => $importo, 'stato' => 'bozza',
            ]);
            RataQuote::create([
                'rata_id' => $rata->id, 'anagrafica_id' => $anagrafica->id, 'immobile_id' => $immobile->id,
                'importo' => $importo, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'data_scadenza' => $rata->data_scadenza,
            ]);
        }

        return $p;
    };

    $principale = $piano('Ordinario', true, [10000, 10000]);
    $parziale   = $piano('Pulizie', true, [3000]);
    $vecchio    = $piano('Ordinario (vecchio, disattivato)', false, [99900, 99900]);

    return (object) compact('user', 'condominio', 'esercizio', 'pianoConto', 'principale', 'parziale', 'vecchio');
}

/** Esegue la stampa raccogliendo i dati passati al PDF. */
function ripPianiDati(object $s): array
{
    $raccolti = [];

    test()->mock(PdfService::class, function ($mock) use (&$raccolti) {
        $mock->shouldReceive('generate')->andReturnUsing(function ($vista, $dati) use (&$raccolti) {
            $raccolti = $dati;

            // `generate()` dichiara di restituire Mpdf: il doppio deve esserlo.
            $mpdf = \Mockery::mock(\Mpdf\Mpdf::class);
            $mpdf->shouldReceive('SetHeader');
            $mpdf->shouldReceive('Output')->andReturn('%PDF-finto');

            return $mpdf;
        });
    });

    test()->actingAs($s->user)->get(route('admin.gestionale.esercizi.piani-conti.print-riparto', [
        'condominio' => $s->condominio->id, 'esercizio' => $s->esercizio->id, 'pianoConto' => $s->pianoConto->id,
    ]))->assertOk();

    return $raccolti;
}

test('esclude i piani disattivati e tiene separate le rate di piani diversi', function () {
    $s = ripPianiScenario();

    $dati = ripPianiDati($s);

    // Tre colonne: due rate del piano principale e una del piano parziale; niente del vecchio.
    expect(array_keys($dati['colonneRate']))->toBe([
        $s->principale->id.'-1', $s->principale->id.'-2', $s->parziale->id.'-1',
    ]);
    expect($dati['colonneRate'][$s->parziale->id.'-1']['nome'])->toContain('Pulizie');

    $riga = $dati['matrice'][0];
    expect($riga['totale'])->toBe(23000)
        ->and($riga['importi_per_rata'][$s->principale->id.'-1'])->toBe(10000)
        ->and($riga['importi_per_rata'][$s->parziale->id.'-1'])->toBe(3000);
});

test('con un solo piano attivo le intestazioni restano quelle di sempre', function () {
    $s = ripPianiScenario();
    $s->parziale->update(['attivo' => false]);

    $dati = ripPianiDati($s);

    expect(collect($dati['colonneRate'])->pluck('nome')->all())->toBe(['1ª Rata', '2ª Rata'])
        ->and($dati['matrice'][0]['totale'])->toBe(20000);
});
