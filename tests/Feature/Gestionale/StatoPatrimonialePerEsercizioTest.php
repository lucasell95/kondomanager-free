<?php

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Services\Gestionale\StatoPatrimonialeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Stato patrimoniale «per esercizio» dal secondo esercizio in poi.
 *
 * Senza scritture di chiusura e riapertura, i conti patrimoniali di fine esercizio sono la somma
 * di tutti i movimenti dall'inizio: la banca a fine 2027/28 contiene anche quello che è successo
 * nel 2026/27. Costi e ricavi, invece, sono del solo esercizio. Il risultato degli anni prima,
 * che i patrimoniali portano dentro, va nel patrimonio netto come voce a sé, e l'equazione
 * Attivo = Passivo + Netto continua a chiudere.
 */

/** Conto contabile minimo, restituisce l'id. */
function spEsConto(int $condominioId, string $codice, string $tipo, string $categoria, ?string $ruolo = null): int
{
    return DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominioId, 'ruolo' => $ruolo, 'codice' => $codice, 'nome' => $codice,
        'tipo' => $tipo, 'categoria' => $categoria, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Scrittura a due righe (dare / avere) dello stesso importo. */
function spEsScrittura(Condominio $condominio, Esercizio $esercizio, string $data, int $contoDare, int $contoAvere, int $importo): void
{
    $id = DB::table('scritture_contabili')->insertGetId([
        'condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id,
        'data_registrazione' => $data, 'data_competenza' => $data,
        'numero_protocollo' => 'SCR-'.uniqid(), 'causale' => 'Test', 'tipo_movimento' => 'giroconto',
        'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('righe_scritture')->insert([
        ['scrittura_id' => $id, 'conto_contabile_id' => $contoDare, 'tipo_riga' => 'dare', 'importo' => $importo, 'created_at' => now(), 'updated_at' => now()],
        ['scrittura_id' => $id, 'conto_contabile_id' => $contoAvere, 'tipo_riga' => 'avere', 'importo' => $importo, 'created_at' => now(), 'updated_at' => now()],
    ]);
}

function spEsScenario(): object
{
    $condominio = Condominio::factory()->create();

    $primo = Esercizio::create([
        'condominio_id' => $condominio->id, 'nome' => '2026/2027', 'descrizione' => '2026/2027',
        'data_inizio' => '2026-06-01', 'data_fine' => '2027-05-31', 'stato' => 'chiuso',
    ]);
    $secondo = Esercizio::create([
        'condominio_id' => $condominio->id, 'nome' => '2027/2028', 'descrizione' => '2027/2028',
        'data_inizio' => '2027-06-01', 'data_fine' => '2028-05-31', 'stato' => 'aperto',
    ]);

    $banca    = spEsConto($condominio->id, '1010', 'attivo', 'liquidita');
    $crediti  = spEsConto($condominio->id, '1101', 'attivo', 'crediti', 'crediti_condomini');
    $debiti   = spEsConto($condominio->id, '2201', 'passivo', 'debiti', 'debiti_fornitori');
    $rate     = spEsConto($condominio->id, '3001', 'passivo', 'fondi', 'gestione_rate');
    $costi    = spEsConto($condominio->id, '6001', 'costo', 'costi', 'costi_servizi');

    // 2026/27: rate per 1.000,00 tutte incassate, spese per 500,00 tutte pagate.
    spEsScrittura($condominio, $primo, '2026-07-01', $crediti, $rate, 100_000);
    spEsScrittura($condominio, $primo, '2026-07-10', $banca, $crediti, 100_000);
    spEsScrittura($condominio, $primo, '2026-09-01', $costi, $debiti, 50_000);
    spEsScrittura($condominio, $primo, '2026-09-30', $debiti, $banca, 50_000);

    // 2027/28: una fattura da 100,00 non ancora pagata, nessuna rata ancora emessa.
    spEsScrittura($condominio, $secondo, '2027-07-01', $costi, $debiti, 10_000);

    return (object) compact('condominio', 'primo', 'secondo');
}

test('il primo esercizio è quello di sempre', function () {
    $s = spEsScenario();

    $sp = app(StatoPatrimonialeService::class)->calcola($s->condominio, $s->primo);

    expect($sp['attivo']['totale'])->toBe(50_000)
        ->and($sp['passivo']['totale'])->toBe(100_000)
        ->and($sp['costi'])->toBe(50_000)
        ->and($sp['risultato_esercizio'])->toBe(-50_000)
        ->and($sp['risultato_esercizi_precedenti'])->toBe(0)
        ->and($sp['quadra'])->toBeTrue();
})->group('stato-patrimoniale');

test('dal secondo esercizio i patrimoniali sono cumulativi e gli economici dell\'anno', function () {
    $s = spEsScenario();

    $sp = app(StatoPatrimonialeService::class)->calcola($s->condominio, $s->secondo);

    // La banca a fine 2027/28 ha ancora i 500,00 avanzati nel 2026/27; Gestione rate i 1.000,00
    // emessi allora; i debiti la fattura aperta.
    expect($sp['attivo']['totale'])->toBe(50_000)
        ->and($sp['passivo']['totale'])->toBe(110_000)
        // Spese dell'anno: solo la fattura da 100,00.
        ->and($sp['costi'])->toBe(10_000)
        ->and($sp['risultato_esercizio'])->toBe(-10_000)
        // I 500,00 spesi nel 2026/27 stanno nel netto come risultato degli esercizi precedenti.
        ->and($sp['risultato_esercizi_precedenti'])->toBe(-50_000)
        ->and($sp['sbilancio'])->toBe(0)
        ->and($sp['quadra'])->toBeTrue();
})->group('stato-patrimoniale');

test('un esercizio successivo non entra nella fotografia di quello prima', function () {
    $s = spEsScenario();

    $primo = app(StatoPatrimonialeService::class)->calcola($s->condominio, $s->primo);

    // La fattura del 2027/28 non tocca i debiti di fine 2026/27.
    $debiti = collect($primo['passivo']['voci'])->firstWhere('codice', '2201');
    expect($debiti['saldo'] ?? 0)->toBe(0);
})->group('stato-patrimoniale');

test('senza esercizio il prospetto resta sull\'intero giornale', function () {
    $s = spEsScenario();

    $sp = app(StatoPatrimonialeService::class)->calcola($s->condominio);

    expect($sp['costi'])->toBe(60_000)
        ->and($sp['risultato_esercizi_precedenti'])->toBe(0)
        ->and($sp['quadra'])->toBeTrue();
})->group('stato-patrimoniale');

test("la diagnosi dello sbilancio vede una scrittura rotta dell'esercizio precedente", function () {
    $s = spEsScenario();

    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo('Accesso pannello amministratore');
    $user = \App\Models\User::factory()->create();
    $user->assignRole($ruolo);

    // Una scrittura del 2026/27 con una riga sola: dare senza avere.
    $conto = DB::table('conti_contabili')->where('condominio_id', $s->condominio->id)->where('codice', '1010')->value('id');
    $rotta = DB::table('scritture_contabili')->insertGetId([
        'condominio_id' => $s->condominio->id, 'esercizio_id' => $s->primo->id,
        'data_registrazione' => '2027-01-10', 'data_competenza' => '2027-01-10',
        'numero_protocollo' => 'SCR-ROTTA', 'causale' => 'Rotta', 'tipo_movimento' => 'giroconto',
        'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('righe_scritture')->insert([
        'scrittura_id' => $rotta, 'conto_contabile_id' => $conto, 'tipo_riga' => 'dare', 'importo' => 1_000,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('admin.gestionale.esercizi.scritture.index', [$s->condominio, $s->secondo]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('quadratura.quadra', false)
            ->where('quadratura.risultato_esercizi_precedenti', -50_000)
            ->has('diagnosi.scritture_non_quadrate', 1)
            ->where('diagnosi.scritture_non_quadrate.0.id', $rotta));
})->group('stato-patrimoniale');
