<?php

/**
 * Prospetto rate unificato — le rate di tutti i piani dell'esercizio, sommate per condòmino e
 * per data di scadenza.
 *
 * Lo scenario è quello da cui la funzionalità è nata: un piano ordinario e uno straordinario
 * sullo stesso esercizio, con le stesse quattro scadenze. Tre condòmini fittizi, con importi
 * sintetici in centesimi, coprono i tre casi che contano: un debitore con pagamenti pieni e
 * parziali, un credito ordinario più piccolo della quota straordinaria (la scadenza resta a
 * debito), un credito più grande (la scadenza va a credito).
 *
 * La proprietà che conta più di tutte è l'ultima: il prospetto è la **somma** di
 * `PianoRateQuoteService::quotePerAnagrafica()` dei singoli piani, cioè della stessa prop che la
 * pagina di ciascun piano mostra. Se le due strade divergono, una delle due schermate mente.
 */

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Models\User;
use App\Services\Gestionale\ProspettoRateUnificatoService;
use App\Services\PianoRateQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

const PROSPETTO_SCADENZE = ['2026-06-01', '2026-09-01', '2026-12-01', '2027-03-01'];

/**
 * Inserisce un piano rate con le sue quattro rate e le quote per anagrafica.
 *
 * @param  array<int, array{0: list<int>, 1?: list<int>}>  $quote  anagrafica_id => [importi per rata, pagati per rata]
 */
function prospettoInserisciPiano(array $ctx, int $gestioneId, string $nome, string $tipo, string $stato, array $quote): int
{
    $pianoId = DB::table('piani_rate')->insertGetId([
        'condominio_id'        => $ctx['condominio']->id,
        'gestione_id'          => $gestioneId,
        'nome'                 => $nome,
        'numero_rate'          => 4,
        'giorno_scadenza'      => 1,
        'metodo_distribuzione' => 'rata_zero',
        'attivo'               => true,
        'stato'                => $stato,
        'tipo'                 => $tipo,
        'contesto_creazione'   => 'preventivo_iniziale',
        'created_at'           => now(),
        'updated_at'           => now(),
    ]);

    foreach (PROSPETTO_SCADENZE as $i => $scadenza) {
        $rataId = DB::table('rate')->insertGetId([
            'piano_rate_id'  => $pianoId,
            'numero_rata'    => $i + 1,
            'data_scadenza'  => $scadenza,
            'importo_totale' => array_sum(array_map(fn ($q) => $q[0][$i], $quote)),
            'stato'          => 'bozza',
            'created_at'     => now(), 'updated_at' => now(),
        ]);

        foreach ($quote as $anagraficaId => $importi) {
            $importo = $importi[0][$i];
            $pagato = $importi[1][$i] ?? 0;

            DB::table('rate_quote')->insert([
                'rata_id'        => $rataId,
                'anagrafica_id'  => $anagraficaId,
                'immobile_id'    => $ctx['immobili'][$anagraficaId],
                'importo'        => $importo,
                'importo_pagato' => $pagato,
                'stato'          => $pagato >= $importo && $importo > 0 ? 'pagata' : ($pagato > 0 ? 'parzialmente_pagata' : 'da_pagare'),
                'created_at'     => now(), 'updated_at' => now(),
            ]);
        }
    }

    return $pianoId;
}

function setupProspettoUnificato(): array
{
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $role = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);
    $user->givePermissionTo('Accesso pannello amministratore');

    $condominio = Condominio::factory()->create();

    $esercizioId = DB::table('esercizi')->insertGetId([
        'condominio_id' => $condominio->id,
        'nome'          => 'Esercizio 2026/2027',
        'stato'         => 'aperto',
        'data_inizio'   => '2026-04-01',
        'data_fine'     => '2027-03-31',
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    $gestioni = [];
    foreach (['ordinaria' => 'Gestione ordinaria 2026/2027', 'straordinaria' => 'Straordinaria mappa millesimi'] as $tipo => $nome) {
        $gestioni[$tipo] = DB::table('gestioni')->insertGetId([
            'condominio_id' => $condominio->id,
            'nome'          => $nome,
            'tipo'          => $tipo,
            'attiva'        => true,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table('esercizio_gestione')->insert([
            'esercizio_id' => $esercizioId,
            'gestione_id'  => $gestioni[$tipo],
            'attiva'       => true,
            'data_inizio'  => '2026-04-01',
            'data_fine'    => '2027-03-31',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    $anagrafiche = [];
    $immobili = [];
    foreach (['ROSSI MARIO', 'BIANCHI LUCA', 'VERDI ANNA'] as $n => $nome) {
        $id = DB::table('anagrafiche')->insertGetId([
            'nome'       => $nome,
            'indirizzo'  => 'Via Test ' . ($n + 1),
            'email'      => 'prospetto' . $n . '@test.it',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $anagrafiche[$nome] = $id;
        $immobili[$id] = DB::table('immobili')->insertGetId([
            'condominio_id' => $condominio->id, 'nome' => 'Int. ' . ($n + 1),
            'interno' => (string) ($n + 1), 'piano' => 1, 'codice_immobile' => 'P-000' . ($n + 1),
            'descrizione' => '', 'attivo' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $ctx = [
        'user'        => $user,
        'condominio'  => $condominio,
        'esercizio'   => Esercizio::findOrFail($esercizioId),
        'gestioni'    => $gestioni,
        'anagrafiche' => $anagrafiche,
        'immobili'    => $immobili,
    ];

    [$rossi, $bianchi, $verdi] = array_values($anagrafiche);

    // Ordinario, approvato. ROSSI ha saldato la prima rata e versato in parte la seconda.
    $ctx['pianoOrdinario'] = prospettoInserisciPiano($ctx, $gestioni['ordinaria'], 'Rate ordinarie 2026/2027', 'ordinario', 'approvato', [
        $rossi   => [[25050, 25050, 25050, 25050], [25050, 10000, 0, 0]],
        $bianchi => [[-300, 200, 200, 200]],        // credito ordinario sulla prima rata
        $verdi   => [[-8000, -8000, -8000, -8000]],
    ]);

    // Straordinario, ancora in bozza: entra comunque nel prospetto, con il suo stato.
    $ctx['pianoStraordinario'] = prospettoInserisciPiano($ctx, $gestioni['straordinaria'], 'Rate straordinaria mappa millesimi', 'straordinario', 'bozza', [
        $rossi   => [[1520, 1520, 1520, 1520], [1520, 0, 0, 0]],
        $bianchi => [[900, 250, 250, 250]],
        $verdi   => [[1000, 1000, 1000, 1000]],
    ]);

    return $ctx;
}

function prospettoRigaDi(array $prospetto, int $anagraficaId): array
{
    return collect($prospetto['righe'])->firstWhere('anagrafica.id', $anagraficaId);
}

it('somma per scadenza le rate di tutti i piani, crediti compresi', function () {
    $ctx = setupProspettoUnificato();

    $p = app(ProspettoRateUnificatoService::class)
        ->calcola($ctx['condominio'], $ctx['esercizio'], Carbon::parse('2026-10-18'));

    expect(array_column($p['scadenze'], 'chiave'))->toBe(PROSPETTO_SCADENZE)
        ->and($p['piani'])->toHaveCount(2)
        ->and($p['piani'][0]['tipo'])->toBe('ordinario')   // l'ordinaria viene prima
        ->and($p['piani'][1]['stato'])->toBe('bozza');

    $rossi = prospettoRigaDi($p, $ctx['anagrafiche']['ROSSI MARIO']);
    expect($rossi['celle']['2026-06-01']['importo'])->toBe(26570)          // 250,50 + 15,20
        ->and($rossi['celle']['2026-09-01']['importo'])->toBe(26570)
        ->and($rossi['celle']['2026-06-01']['stato'])->toBe('pagata')
        ->and($rossi['celle']['2026-09-01']['stato'])->toBe('parzialmente_pagata')
        ->and($rossi['celle']['2026-06-01']['n_piani'])->toBe(2)
        ->and($rossi['totale'])->toBe(106280)
        ->and($rossi['versato'])->toBe(36570)
        ->and($rossi['da_saldare'])->toBe(69710)
        // Scaduto al 18/10/2026: residuo della seconda rata (150,50 ord. + 15,20 straord.).
        ->and($rossi['scaduto'])->toBe(16570);

    $bianchi = prospettoRigaDi($p, $ctx['anagrafiche']['BIANCHI LUCA']);
    expect($bianchi['celle']['2026-06-01']['importo'])->toBe(600)          // −3,00 + 9,00
        ->and($bianchi['celle']['2026-06-01']['stato'])->toBe('da_pagare')
        ->and($bianchi['celle']['2026-06-01']['credito'])->toBe(300)
        ->and($bianchi['totale'])->toBe(1950);

    $verdi = prospettoRigaDi($p, $ctx['anagrafiche']['VERDI ANNA']);
    expect($verdi['celle']['2026-06-01']['importo'])->toBe(-7000)          // −80,00 + 10,00
        ->and($verdi['celle']['2026-06-01']['stato'])->toBe('credito')
        ->and($verdi['totale'])->toBe(-28000)
        ->and($verdi['da_saldare'])->toBe(-28000);

    // Riga dei totali = somma delle righe.
    expect($p['totali']['totale'])->toBe(106280 + 1950 - 28000)
        ->and($p['totali']['versato'])->toBe(36570)
        ->and($p['totali']['da_saldare'])->toBe($p['totali']['totale'] - 36570)
        ->and($p['scadenze'][0]['importo'])->toBe(26570 + 600 - 7000)
        ->and($p['scadenze'][0]['emissione'])->toBe('bozza');
});

it('il dettaglio per piano dice da dove viene ogni scadenza', function () {
    $ctx = setupProspettoUnificato();

    $p = app(ProspettoRateUnificatoService::class)
        ->calcola($ctx['condominio'], $ctx['esercizio'], Carbon::parse('2026-10-18'));

    $bianchi = prospettoRigaDi($p, $ctx['anagrafiche']['BIANCHI LUCA']);
    $dettaglio = collect($bianchi['celle']['2026-06-01']['dettaglio'])->keyBy('piano_id');

    expect($dettaglio[$ctx['pianoOrdinario']]['importo'])->toBe(-300)
        ->and($dettaglio[$ctx['pianoOrdinario']]['stato'])->toBe('credito')
        ->and($dettaglio[$ctx['pianoStraordinario']]['importo'])->toBe(900)
        ->and($bianchi['sottorighe'])->toHaveCount(2)
        ->and(array_sum(array_column($bianchi['sottorighe'], 'totale')))->toBe($bianchi['totale']);
});

it('coincide con la somma di quotePerAnagrafica dei singoli piani', function () {
    $ctx = setupProspettoUnificato();

    $p = app(ProspettoRateUnificatoService::class)
        ->calcola($ctx['condominio'], $ctx['esercizio'], Carbon::parse('2026-10-18'));

    $quoteService = app(PianoRateQuoteService::class);
    $attesi = [];
    foreach ([$ctx['pianoOrdinario'], $ctx['pianoStraordinario']] as $pianoId) {
        $piano = PianoRate::with('rate.rateQuote.anagrafica')->findOrFail($pianoId);
        foreach ($quoteService->quotePerAnagrafica($piano) as $riga) {
            foreach ($riga['rate'] as $rata) {
                $attesi[$riga['anagrafica']['id']][$rata['scadenza']] =
                    ($attesi[$riga['anagrafica']['id']][$rata['scadenza']] ?? 0) + $rata['importo'];
            }
        }
    }

    foreach ($attesi as $anagraficaId => $perScadenza) {
        $riga = prospettoRigaDi($p, $anagraficaId);
        foreach ($perScadenza as $scadenza => $importo) {
            expect($riga['celle'][$scadenza]['importo'])->toBe($importo);
        }
        expect($riga['totale'])->toBe(array_sum($perScadenza));
    }
});

it('i piani non attivi restano fuori', function () {
    $ctx = setupProspettoUnificato();
    DB::table('piani_rate')->where('id', $ctx['pianoStraordinario'])->update(['attivo' => false]);

    $p = app(ProspettoRateUnificatoService::class)->calcola($ctx['condominio'], $ctx['esercizio']);

    expect($p['piani'])->toHaveCount(1)
        ->and(prospettoRigaDi($p, $ctx['anagrafiche']['ROSSI MARIO'])['totale'])->toBe(25050 * 4);
});

it('la pagina risponde e non viene scambiata per un piano rate', function () {
    $ctx = setupProspettoUnificato();
    $this->travelTo(Carbon::parse('2026-10-18 10:00'));

    $this->actingAs($ctx['user'])
        ->get(route('admin.gestionale.esercizi.piani-rate.prospetto-unificato', [
            'condominio' => $ctx['condominio']->id,
            'esercizio'  => $ctx['esercizio']->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('gestionale/pianiRate/ProspettoRateUnificato')
            ->has('prospetto.righe', 3)
            ->has('prospetto.scadenze', 4)
            ->where('prospetto.totali.versato', 36570)
            ->where('prospetto.totali.scaduto', 16570 + 1350 + 2000)
        );
});

it('la stampa PDF risponde, con e senza dettaglio', function () {
    $ctx = setupProspettoUnificato();

    foreach ([0, 1] as $dettaglio) {
        $this->actingAs($ctx['user'])
            ->get(route('admin.gestionale.esercizi.piani-rate.prospetto-unificato.print', [
                'condominio' => $ctx['condominio']->id,
                'esercizio'  => $ctx['esercizio']->id,
                'dettaglio'  => $dettaglio,
            ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }
});

it("l'esercizio di un altro condominio risponde 404", function () {
    $ctx = setupProspettoUnificato();
    $altro = Condominio::factory()->create();

    $this->actingAs($ctx['user'])
        ->get(route('admin.gestionale.esercizi.piani-rate.prospetto-unificato', [
            'condominio' => $altro->id,
            'esercizio'  => $ctx['esercizio']->id,
        ]))
        ->assertNotFound();
});
