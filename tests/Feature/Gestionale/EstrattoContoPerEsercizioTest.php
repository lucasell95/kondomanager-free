<?php

/**
 * Estratto conto per anagrafica: **un esercizio alla volta, e solo i conti del condòmino**.
 *
 * Lo scenario è il passaggio d'esercizio di un condominio con esercizio 01/06–31/05:
 *
 * - esercizio 2026/27 (chiuso): rata da 100,00, pagati 60,00 → residuo 40,00;
 * - esercizio 2027/28 (aperto): saldo iniziale 40,00 (il residuo), rata da 120,00, incasso di
 *   40,00 che paga il vecchio debito, eccedenza di 10,00 finita sugli Anticipi (2101);
 * - Rossi è anche il referente di un fornitore: la fattura registra 50,00 sui Debiti v/fornitori
 *   con la sua anagrafica.
 *
 * Prima della correzione il 2027/28 mostrava anche le righe del 2026/27 (il residuo contato due
 * volte: nel saldo iniziale e nei movimenti che lo avevano prodotto) e la riga del fornitore.
 */

use App\Http\Controllers\Gestionale\PianiRate\EstrattoContoAnagraficaController;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RigaScrittura;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function ecEsScenario(): object
{
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $role = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);
    $user->givePermissionTo('Accesso pannello amministratore');

    $condominio = Condominio::create([
        'nome' => 'Condominio Passaggio Esercizio', 'uuid' => (string) Str::uuid(),
        'indirizzo' => 'Via Roma 1', 'citta' => 'Milano', 'cap' => '20100', 'provincia' => 'MI',
    ]);

    $vecchio = Esercizio::create([
        'condominio_id' => $condominio->id, 'nome' => 'Esercizio 2026/2027', 'descrizione' => '2026/2027',
        'data_inizio' => '2026-06-01', 'data_fine' => '2027-05-31', 'stato' => 'chiuso',
    ]);
    $nuovo = Esercizio::create([
        'condominio_id' => $condominio->id, 'nome' => 'Esercizio 2027/2028', 'descrizione' => '2027/2028',
        'data_inizio' => '2027-06-01', 'data_fine' => '2028-05-31', 'stato' => 'aperto',
    ]);

    $gestione = Gestione::create([
        'condominio_id' => $condominio->id, 'nome' => 'ORDINARIA', 'tipo' => 'ordinaria', 'data_inizio' => '2026-06-01',
    ]);
    $gestione->esercizi()->attach($vecchio->id, ['attiva' => true]);
    $gestione->esercizi()->attach($nuovo->id, ['attiva' => true]);

    $anagrafica = Anagrafica::create([
        'condominio_id' => $condominio->id, 'nome' => 'Mario Rossi',
        'email' => 'rossi.esercizi@test.it', 'indirizzo' => 'Via Verdi 1', 'cap' => '00100',
        'citta' => 'Roma', 'provincia' => 'RM', 'codice_fiscale' => 'TSTTST80A01H501E',
    ]);
    $condominio->anagrafiche()->attach($anagrafica->id);

    $immobile = Immobile::create([
        'condominio_id' => $condominio->id, 'nome' => 'Appartamento', 'descrizione' => 'Appartamento',
        'interno' => '1', 'foglio' => '1', 'particella' => '1', 'subalterno' => '1',
    ]);
    $immobile->anagrafiche()->attach($anagrafica->id, [
        'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2026-06-01',
    ]);

    $conti = [];
    foreach ([
        'crediti_condomini' => ['1101', 'attivo', 'crediti'],
        'anticipi_condomini' => ['2101', 'passivo', 'debiti'],
        'debiti_fornitori' => ['2201', 'passivo', 'debiti'],
    ] as $ruolo => [$codice, $tipo, $categoria]) {
        $conti[$ruolo] = ContoContabile::create([
            'condominio_id' => $condominio->id, 'ruolo' => $ruolo, 'codice' => $codice,
            'nome' => $ruolo, 'tipo' => $tipo, 'categoria' => $categoria,
        ]);
    }

    $piano = PianoRate::create([
        'condominio_id' => $condominio->id, 'gestione_id' => $gestione->id,
        'nome' => 'ORDINARIO', 'numero_rate' => 2,
    ]);
    $rataVecchia = Rata::create([
        'piano_rate_id' => $piano->id, 'numero_rata' => 1,
        'data_scadenza' => '2026-09-01', 'importo_totale' => 10000, 'stato' => 'emessa',
    ]);
    $rataNuova = Rata::create([
        'piano_rate_id' => $piano->id, 'numero_rata' => 2,
        'data_scadenza' => '2027-09-01', 'importo_totale' => 12000, 'stato' => 'emessa',
    ]);

    $s = (object) compact('user', 'condominio', 'vecchio', 'nuovo', 'gestione', 'anagrafica', 'immobile', 'conti');

    // Esercizio 2026/27: emissione 100,00, incasso 60,00.
    ecEsScrittura($s, $vecchio, 'emissione_rata', '2026-08-01', [['crediti_condomini', 'dare', 10000, $rataVecchia->id]]);
    ecEsScrittura($s, $vecchio, 'incasso_rata', '2026-09-10', [['crediti_condomini', 'avere', 6000, $rataVecchia->id]]);

    // Esercizio 2027/28: il residuo arriva come saldo iniziale.
    Saldo::create([
        'esercizio_id' => $nuovo->id, 'condominio_id' => $condominio->id, 'gestione_id' => $gestione->id,
        'anagrafica_id' => $anagrafica->id, 'immobile_id' => $immobile->id,
        'saldo_iniziale' => 4000, 'origine' => 'manuale', 'is_applicato' => false,
    ]);
    ecEsScrittura($s, $nuovo, 'emissione_rata', '2027-08-01', [['crediti_condomini', 'dare', 12000, $rataNuova->id]]);
    // Paga il vecchio debito (rata del 2026/27) con un incasso del 2027/28, più 10,00 di anticipo.
    ecEsScrittura($s, $nuovo, 'incasso_rata', '2027-08-20', [
        ['crediti_condomini', 'avere', 4000, $rataVecchia->id],
        ['anticipi_condomini', 'avere', 1000, null],
    ]);
    // Rossi referente di un fornitore: la fattura gli intesta il debito v/fornitori.
    ecEsScrittura($s, $nuovo, 'fattura_acquisto', '2027-07-15', [['debiti_fornitori', 'avere', 5000, null]]);

    return $s;
}

/** @param list<array{0: string, 1: string, 2: int, 3: ?int}> $righe  [ruolo conto, tipo_riga, importo, rata_id] */
function ecEsScrittura(object $s, Esercizio $esercizio, string $tipo, string $data, array $righe): void
{
    $scrittura = ScritturaContabile::create([
        'condominio_id' => $s->condominio->id, 'esercizio_id' => $esercizio->id, 'gestione_id' => $s->gestione->id,
        'data_registrazione' => $data, 'data_competenza' => $data,
        'causale' => $tipo, 'tipo_movimento' => $tipo, 'stato' => 'registrata',
    ]);

    foreach ($righe as [$ruolo, $tipoRiga, $importo, $rataId]) {
        RigaScrittura::create([
            'scrittura_id' => $scrittura->id, 'conto_contabile_id' => $s->conti[$ruolo]->id, 'tipo_riga' => $tipoRiga,
            'importo' => $importo, 'immobile_id' => $s->immobile->id, 'anagrafica_id' => $s->anagrafica->id, 'rata_id' => $rataId,
        ]);
    }
}

function ecEsLedger(object $s, Esercizio $esercizio): array
{
    $metodo = new ReflectionMethod(EstrattoContoAnagraficaController::class, 'buildLedger');
    $metodo->setAccessible(true);

    return $metodo->invoke(app(EstrattoContoAnagraficaController::class), $s->condominio, $esercizio, $s->anagrafica);
}

it("conta solo i movimenti dell'esercizio: il residuo dell'anno prima non si somma due volte", function () {
    $s = ecEsScenario();

    [$timeline, $stats, $saldoIniziale] = ecEsLedger($s, $s->nuovo);

    // 40,00 di saldo iniziale + 120,00 di rata − 40,00 di incasso − 10,00 di anticipo.
    expect((int) $saldoIniziale)->toBe(4000)
        ->and($timeline)->toHaveCount(3)
        ->and($timeline->sum('dare'))->toBe(12000)
        ->and($timeline->sum('avere'))->toBe(5000)
        ->and($stats['saldo_raw'])->toBe(11000);

    // Il primo esercizio, da solo, chiude sul residuo che il secondo riceve come saldo iniziale.
    [$timelineVecchia, $statsVecchie, $saldoInizialeVecchio] = ecEsLedger($s, $s->vecchio);

    expect((int) $saldoInizialeVecchio)->toBe(0)
        ->and($timelineVecchia)->toHaveCount(2)
        ->and($statsVecchie['saldo_raw'])->toBe(4000);
});

it('lascia fuori le righe dei fornitori intestate al condòmino referente, e tiene gli anticipi', function () {
    $s = ecEsScenario();

    [$timeline] = ecEsLedger($s, $s->nuovo);

    $conti = RigaScrittura::whereIn('id', $timeline->pluck('id'))->pluck('conto_contabile_id')->unique()->sort()->values()->all();

    expect($conti)->toBe(collect([$s->conti['crediti_condomini']->id, $s->conti['anticipi_condomini']->id])->sort()->values()->all())
        ->and($timeline->pluck('avere')->all())->not->toContain(5000);
});

it("la pagina mostra l'esercizio aperto per default e quello scelto con ?esercizio=, e il PDF lo segue", function () {
    $s = ecEsScenario();
    $rotta = fn (string $nome, array $q = []) => route($nome, [
        'condominio' => $s->condominio->id, 'anagrafica' => $s->anagrafica->id,
    ] + $q);

    $this->actingAs($s->user)->get($rotta('admin.gestionale.anagrafiche.estratto-conto'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('gestionale/pianiRate/EstrattoContoAnagrafica')
            ->where('esercizio.id', $s->nuovo->id)
            ->where('stats.saldo_raw', 11000)
            ->has('esercizi', 2)
            ->where('esercizi.0.id', $s->nuovo->id));

    $this->actingAs($s->user)->get($rotta('admin.gestionale.anagrafiche.estratto-conto', ['esercizio' => $s->vecchio->id, 'vista' => 'unita']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('esercizio.id', $s->vecchio->id)
            ->where('vista', 'unita')
            ->where('stats.saldo_raw', 4000)
            ->has('timeline', 2));

    $this->actingAs($s->user)->get($rotta('admin.gestionale.anagrafiche.estratto-conto.print', ['esercizio' => $s->vecchio->id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it("un esercizio di un altro condominio risponde 404", function () {
    $s = ecEsScenario();

    $altro = Condominio::create([
        'nome' => 'Altro Condominio', 'uuid' => (string) Str::uuid(),
        'indirizzo' => 'Via Milano 2', 'citta' => 'Milano', 'cap' => '20100', 'provincia' => 'MI',
    ]);
    $esercizioAltrui = Esercizio::create([
        'condominio_id' => $altro->id, 'nome' => 'Altrui', 'descrizione' => 'Altrui',
        'data_inizio' => '2027-06-01', 'data_fine' => '2028-05-31', 'stato' => 'aperto',
    ]);

    $this->actingAs($s->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [
        'condominio' => $s->condominio->id, 'anagrafica' => $s->anagrafica->id, 'esercizio' => $esercizioAltrui->id,
    ]))->assertNotFound();
});

it('con tutti gli esercizi chiusi mostra il più recente invece di andare in errore', function () {
    $s = ecEsScenario();
    $s->nuovo->update(['stato' => 'chiuso']);

    $this->actingAs($s->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [
        'condominio' => $s->condominio->id, 'anagrafica' => $s->anagrafica->id,
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('esercizio.id', $s->nuovo->id));
});
