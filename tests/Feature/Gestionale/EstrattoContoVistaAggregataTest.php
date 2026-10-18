<?php

/**
 * Estratto conto: la vista aggregata (una riga per movimento) e quella per unità immobiliare
 * raccontano lo stesso conto.
 *
 * Lo scenario è quello che ha fatto nascere la vista aggregata: un condòmino con appartamento,
 * cantina e garage. L'emissione di una rata sono tre righe contabili, un incasso che copre due
 * rate su tre unità ne sono cinque. Per il condòmino sono tre movimenti.
 *
 * La proprietà che conta: **totali e saldo finale identici fra le due viste**, e il saldo
 * progressivo della vista aggregata che arriva allo stesso numero.
 */

use App\Http\Controllers\Gestionale\PianiRate\EstrattoContoAnagraficaController;
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
use App\Services\Gestionale\EstrattoContoAggregatoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function ecAggScenario(): object
{
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $role = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);
    $user->givePermissionTo('Accesso pannello amministratore');

    $condominio = Condominio::create([
        'nome' => 'Condominio Vista Aggregata', 'uuid' => (string) Str::uuid(),
        'indirizzo' => 'Via Roma 1', 'citta' => 'Milano', 'cap' => '20100', 'provincia' => 'MI',
    ]);
    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id, 'nome' => '2026',
        'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto',
    ]);
    $gestione = Gestione::create([
        'condominio_id' => $condominio->id, 'nome' => 'ORDINARIA', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01',
    ]);
    $gestione->esercizi()->attach($esercizio->id, ['attiva' => true]);

    $anagrafica = Anagrafica::create([
        'condominio_id' => $condominio->id, 'nome' => 'Mario', 'cognome' => 'Rossi',
        'email' => 'rossi.aggregata@test.it', 'indirizzo' => 'Via Verdi 1', 'cap' => '00100',
        'citta' => 'Roma', 'provincia' => 'RM', 'codice_fiscale' => 'TSTTST80A01H501X',
    ]);

    $immobili = [];
    foreach (['Appartamento' => '1', 'Cantina' => 'C1', 'Garage' => 'G1'] as $nome => $interno) {
        $imm = Immobile::create([
            'condominio_id' => $condominio->id, 'nome' => $nome, 'descrizione' => $nome,
            'interno' => $interno, 'foglio' => '1', 'particella' => '1', 'subalterno' => $interno,
        ]);
        $imm->anagrafiche()->attach($anagrafica->id, [
            'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2026-01-01',
        ]);
        $immobili[] = $imm;
    }

    $conto = ContoContabile::create([
        'condominio_id' => $condominio->id, 'ruolo' => 'crediti_condomini', 'codice' => 'CRED-COND',
        'nome' => 'Crediti verso Condomini', 'tipo' => 'attivo', 'categoria' => 'crediti',
    ]);

    $piano = PianoRate::create([
        'condominio_id' => $condominio->id, 'gestione_id' => $gestione->id,
        'nome' => 'ORDINARIO 2026', 'numero_rate' => 2,
    ]);

    $s = (object) compact('user', 'condominio', 'esercizio', 'gestione', 'anagrafica', 'immobili', 'conto', 'piano');

    // Quote per unità: appartamento 200,00, cantina 30,00, garage 50,00 per ogni rata.
    $importi = [20000, 3000, 5000];
    $s->rate = [];
    foreach ([1, 2] as $n) {
        $rata = Rata::create([
            'piano_rate_id' => $piano->id, 'numero_rata' => $n,
            'data_scadenza' => sprintf('2026-%02d-01', $n * 3), 'importo_totale' => array_sum($importi), 'stato' => 'emessa',
        ]);
        foreach ($immobili as $i => $imm) {
            RataQuote::create([
                'rata_id' => $rata->id, 'anagrafica_id' => $anagrafica->id, 'immobile_id' => $imm->id,
                'importo' => $importi[$i], 'importo_pagato' => $n === 1 ? $importi[$i] : 0,
                'stato' => $n === 1 ? 'pagata' : 'da_pagare',
                'data_scadenza' => sprintf('2026-%02d-01', $n * 3),
            ]);
        }
        $s->rate[$n] = $rata;
    }

    // 1) Emissione rata 1: tre righe in dare, una per unità.
    ecAggScrittura($s, 'emissione_rata', '2026-03-01', array_map(
        fn ($i) => ['dare', $importi[$i], $immobili[$i]->id, $s->rate[1]->id], [0, 1, 2]
    ));

    // 2) Un incasso da 330,00: salda la rata 1 (tre righe) e anticipa 50,00 sulla rata 2 (due righe).
    ecAggScrittura($s, 'incasso_rata', '2026-03-10', [
        ['avere', 20000, $immobili[0]->id, $s->rate[1]->id],
        ['avere', 3000, $immobili[1]->id, $s->rate[1]->id],
        ['avere', 5000, $immobili[2]->id, $s->rate[1]->id],
        ['avere', 3000, $immobili[1]->id, $s->rate[2]->id],
        ['avere', 2000, $immobili[2]->id, $s->rate[2]->id],
    ]);

    // 3) Emissione rata 2: altre tre righe in dare.
    ecAggScrittura($s, 'emissione_rata', '2026-06-01', array_map(
        fn ($i) => ['dare', $importi[$i], $immobili[$i]->id, $s->rate[2]->id], [0, 1, 2]
    ));

    return $s;
}

/** @param list<array{0: string, 1: int, 2: int, 3: int}> $righe  [tipo_riga, importo, immobile_id, rata_id] */
function ecAggScrittura(object $s, string $tipo, string $data, array $righe): void
{
    $scrittura = ScritturaContabile::create([
        'condominio_id' => $s->condominio->id, 'esercizio_id' => $s->esercizio->id, 'gestione_id' => $s->gestione->id,
        'data_registrazione' => '2026-10-18', 'data_competenza' => $data,
        'causale' => $tipo === 'incasso_rata' ? 'Incasso rate' : 'Emissione rata', 'tipo_movimento' => $tipo, 'stato' => 'registrata',
    ]);

    foreach ($righe as [$tipoRiga, $importo, $immobileId, $rataId]) {
        RigaScrittura::create([
            'scrittura_id' => $scrittura->id, 'conto_contabile_id' => $s->conto->id, 'tipo_riga' => $tipoRiga,
            'importo' => $importo, 'immobile_id' => $immobileId, 'anagrafica_id' => $s->anagrafica->id, 'rata_id' => $rataId,
        ]);
    }
}

/** La timeline espansa e le statistiche, esattamente come le costruisce il controller. */
function ecAggLedger(object $s): array
{
    $metodo = new ReflectionMethod(EstrattoContoAnagraficaController::class, 'buildLedger');
    $metodo->setAccessible(true);

    return $metodo->invoke(app(EstrattoContoAnagraficaController::class), $s->condominio, $s->esercizio, $s->anagrafica);
}

it('la vista aggregata ha gli stessi totali e lo stesso saldo finale della vista per unità', function () {
    $s = ecAggScenario();
    [$espansa, $stats, $saldoIniziale] = ecAggLedger($s);

    $aggregata = app(EstrattoContoAggregatoService::class)->aggrega($espansa, (int) $saldoIniziale);

    expect($espansa)->toHaveCount(11)
        ->and($aggregata)->toHaveCount(3)
        ->and($aggregata->count())->toBeLessThanOrEqual($espansa->count())
        ->and($aggregata->sum('dare'))->toBe($espansa->sum('dare'))
        ->and($aggregata->sum('avere'))->toBe($espansa->sum('avere'))
        ->and($aggregata->last()['saldo'])->toBe($espansa->last()['saldo'])
        ->and($aggregata->last()['saldo'])->toBe($stats['saldo_raw']);

    // Ogni riga aggregata porta il saldo progressivo giusto, partendo dallo stesso saldo iniziale.
    $saldo = (int) $saldoIniziale;
    foreach ($aggregata as $riga) {
        $saldo += $riga['dare'] - $riga['avere'];
        expect($riga['saldo'])->toBe($saldo);
    }
});

it('una riga per movimento: emissione per rata, incasso col suo totale', function () {
    $s = ecAggScenario();
    [$espansa, , $saldoIniziale] = ecAggLedger($s);

    $aggregata = app(EstrattoContoAggregatoService::class)->aggrega($espansa, (int) $saldoIniziale)->values();

    [$emissione1, $incasso, $emissione2] = $aggregata->all();

    expect($emissione1['dare'])->toBe(28000)
        ->and($emissione1['n_righe'])->toBe(3)
        ->and($emissione1['stato_rata'])->toBe('pagata')
        ->and(collect($emissione1['dettagli'])->firstWhere('type', 'immobile')['text'])
            ->toContain('Appartamento')->toContain('Cantina')->toContain('Garage');

    expect($incasso['avere'])->toBe(33000)
        ->and($incasso['n_righe'])->toBe(5)
        ->and(collect($incasso['dettagli'])->where('type', 'rata')->count())->toBe(2);   // copre due rate

    expect($emissione2['dare'])->toBe(28000)
        ->and($emissione2['stato_rata'])->toBe('da_pagare');
});

it('la pagina usa la vista aggregata per default e quella per unità su richiesta, con le stesse statistiche', function () {
    $s = ecAggScenario();
    $rotta = fn (array $q = []) => route('admin.gestionale.anagrafiche.estratto-conto', [
        'condominio' => $s->condominio->id, 'anagrafica' => $s->anagrafica->id,
    ] + $q);

    $stats = [];
    $ultimoSaldo = [];

    $this->actingAs($s->user)->get($rotta())
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$stats, &$ultimoSaldo) {
            $page->component('gestionale/pianiRate/EstrattoContoAnagrafica')
                ->where('vista', 'aggregata')
                ->has('timeline', 3);
            $props = $page->toArray()['props'];
            $stats['aggregata'] = $props['stats'];
            $ultimoSaldo['aggregata'] = end($props['timeline'])['saldo'];
        });

    $this->actingAs($s->user)->get($rotta(['vista' => 'unita']))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$stats, &$ultimoSaldo) {
            $page->where('vista', 'unita')->has('timeline', 11);
            $props = $page->toArray()['props'];
            $stats['unita'] = $props['stats'];
            $ultimoSaldo['unita'] = end($props['timeline'])['saldo'];
        });

    expect($stats['aggregata'])->toBe($stats['unita'])
        ->and($ultimoSaldo['aggregata'])->toBe($ultimoSaldo['unita']);

    // Un valore sconosciuto ripiega sulla vista predefinita.
    $this->actingAs($s->user)->get($rotta(['vista' => 'qualunque']))
        ->assertInertia(fn (Assert $page) => $page->where('vista', 'aggregata'));
});

it('la stampa PDF risponde in entrambe le viste', function () {
    $s = ecAggScenario();

    foreach (['aggregata', 'unita'] as $vista) {
        $this->actingAs($s->user)
            ->get(route('admin.gestionale.anagrafiche.estratto-conto.print', [
                'condominio' => $s->condominio->id, 'anagrafica' => $s->anagrafica->id, 'vista' => $vista,
            ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }
});

it("la data è quella dell'operazione, e ordine e saldo progressivo la seguono", function () {
    $s = ecAggScenario();

    // Un incasso del 15/02 registrato per ultimo (id più alto, registrazione 18/10): per data di
    // registrazione finiva in coda; per data dell'operazione è il primo movimento dell'anno.
    ecAggScrittura($s, 'incasso_rata', '2026-02-15', [
        ['avere', 1000, $s->immobili[0]->id, $s->rate[1]->id],
    ]);

    [$espansa, $stats, $saldoIniziale] = ecAggLedger($s);
    $aggregata = app(EstrattoContoAggregatoService::class)->aggrega($espansa, (int) $saldoIniziale);

    foreach (['per unità' => $espansa, 'aggregata' => $aggregata] as $vista => $timeline) {
        $prima = $timeline->first();

        expect($prima['avere'])->toBe(1000, $vista)
            ->and($prima['data'])->toBe('15/02/2026')
            ->and($prima['data_registrazione'])->toBe('18/10/2026')
            ->and($prima['saldo'])->toBe((int) $saldoIniziale - 1000)
            ->and($timeline->last()['saldo'])->toBe($stats['saldo_raw']);

        // Le date non scendono mai lungo la timeline.
        $date = $timeline->map(fn ($r) => \Illuminate\Support\Carbon::createFromFormat('d/m/Y', $r['data'])->format('Y-m-d'))->all();
        $ordinate = $date;
        sort($ordinate);
        expect($date)->toBe($ordinate);
    }

    // L'emissione della rata 1 porta la data di emissione, non quella di registrazione.
    expect($aggregata->firstWhere('dare', 28000)['data'])->toBe('01/03/2026');
});
