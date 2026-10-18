<?php

/**
 * Lo scadenziario F24 raccoglie le ritenute di TUTTI gli esercizi.
 *
 * Il versamento delle ritenute è un obbligo del condominio come **sostituto d'imposta** e segue la
 * data del pagamento (art. 25-ter c. 2-bis DPR 600/1973: soglia di 500 €, termini del 16/6, 16/12 e
 * 16/1), non l'esercizio condominiale in cui il pagamento è stato registrato.
 *
 * Fino al 18/10/2026 lo scadenziario guardava solo l'**ultimo esercizio aperto**. Con un esercizio
 * 01/06–31/05 per mesi ne convivono due aperti — il vecchio in attesa del rendiconto e il nuovo — e:
 *
 *  - le ritenute registrate sul vecchio non entravano in nessuna delega;
 *  - il cumulo per la soglia dei 500 € si spezzava in due, e il versamento slittava al 16/12;
 *  - le deleghe ancora da versare del vecchio esercizio sparivano dall'elenco e dai contatori.
 */

use App\Actions\Gestionale\Movimenti\GeneraDelegheF24Action;
use App\Enums\Fiscale\StatoDelegaF24;
use App\Models\Esercizio;
use App\Models\Gestionale\DelegaF24;
use App\Models\User;
use App\Services\Gestionale\CalendarioFiscaleService;
use App\Services\Gestionale\PagamentoFornitoreService;
use App\Services\Gestionale\PlafondRitenuteService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/GestionaleTestHelpers.php';

function f24teGeneratore(): GeneraDelegheF24Action
{
    return new GeneraDelegheF24Action(new PlafondRitenuteService(new CalendarioFiscaleService));
}

/** Contesto con fornitore al 4% e un secondo esercizio aperto, più recente del primo. */
function f24teScenario(): array
{
    $ctx = setupPagamentiService();
    $ctx[3]->update(['soggetto_ritenuta' => true, 'perc_ritenuta' => 4, 'perc_imponibile_ritenuta' => 100]);
    $ctx[3]->refresh();

    $nuovo = Esercizio::create([
        'condominio_id' => $ctx[0]->id,
        'nome' => 'Esercizio 2027',
        'data_inizio' => '2027-01-01',
        'data_fine' => '2027-12-31',
        'stato' => 'aperto',
    ]);

    DB::table('esercizio_gestione')->insertOrIgnore([
        'esercizio_id' => $nuovo->id, 'gestione_id' => $ctx[2]->id, 'attiva' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$ctx, $nuovo];
}

/** Registra una fattura con ritenuta e la paga alla data indicata, sull'esercizio indicato. */
function f24tePaga(array $ctx, string $data, float $imponibile, int $esercizioId)
{
    $fattura = registraFatturaServiceTest($ctx, [
        'applica_ritenuta' => true,
        'righe' => [[
            'descrizione' => 'Manutenzione',
            'importo_imponibile' => $imponibile,
            'aliquota_iva' => 22,
            'conto_id' => $ctx[5]->id,
            'is_sopravvenienza' => false,
        ]],
    ]);

    return (new PagamentoFornitoreService)->registraPagamento(
        datiPagamento($ctx, $fattura, ['data_pagamento' => $data, 'esercizio_id' => $esercizioId])
    );
}

test('le ritenute di due esercizi aperti si sommano per la soglia dei 500 euro', function () {
    [$ctx, $nuovo] = f24teScenario();
    [$condominio, $vecchio] = $ctx;

    // 7.500 € di imponibile → 300 € di ritenuta ciascuno. Da soli restano sotto i 500 €
    // (scadenza 16/12); insieme la soglia si raggiunge a ottobre → 16 novembre.
    $a = f24tePaga($ctx, '2026-09-10', 7_500, $vecchio->id);
    $b = f24tePaga($ctx, '2026-10-05', 7_500, $nuovo->id);

    $deleghe = f24teGeneratore()->esegui($condominio, $nuovo->id);

    expect($deleghe)->toHaveCount(1);

    $delega = $deleghe->first();
    $pagamentiCoperti = $delega->righe->flatMap(fn ($r) => $r->pagamenti->pluck('id'))->sort()->values()->all();

    expect($delega->totale_debito)->toEqual(60_000)
        ->and($delega->data_scadenza->format('Y-m-d'))->toEqual('2026-11-16')
        ->and($pagamentiCoperti)->toEqual(collect([$a->id, $b->id])->sort()->values()->all());
});

test('le ritenute non versate di un esercizio chiuso restano da versare', function () {
    [$ctx, $nuovo] = f24teScenario();
    [$condominio, $vecchio] = $ctx;

    f24tePaga($ctx, '2026-03-10', 20_000, $vecchio->id);   // ritenuta 800 €
    DB::table('esercizi')->where('id', $vecchio->id)->update(['stato' => 'chiuso']);

    expect(f24teGeneratore()->ritenuteDaVersare($condominio))->toHaveCount(1);

    $deleghe = f24teGeneratore()->esegui($condominio, $nuovo->id);

    expect($deleghe)->toHaveCount(1)
        ->and($deleghe->first()->totale_debito)->toEqual(80_000);
});

test('una bozza appoggiata a un altro esercizio non spezza il cumulo', function () {
    [$ctx, $nuovo] = f24teScenario();
    [$condominio, $vecchio] = $ctx;

    // Bozza calcolata quando l'esercizio corrente era ancora il vecchio.
    f24tePaga($ctx, '2026-09-10', 7_500, $vecchio->id);
    f24teGeneratore()->esegui($condominio, $vecchio->id);
    expect(DelegaF24::where('condominio_id', $condominio->id)->count())->toBe(1);

    // Arriva un secondo pagamento sul nuovo esercizio e si ricalcola da lì.
    f24tePaga($ctx, '2026-10-05', 7_500, $nuovo->id);
    $deleghe = f24teGeneratore()->esegui($condominio, $nuovo->id);

    // Una sola bozza con tutte e due le ritenute: quella vecchia è stata rifatta, non lasciata
    // a trattenere il primo pagamento in un mucchio separato.
    expect($deleghe)->toHaveCount(1)
        ->and($deleghe->first()->totale_debito)->toEqual(60_000)
        ->and(DelegaF24::where('condominio_id', $condominio->id)->where('stato', StatoDelegaF24::BOZZA->value)->count())->toBe(1);
});

test('lo scadenziario mostra e conta anche le deleghe e le ritenute degli altri esercizi', function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $utente = User::factory()->create();
    $utente->assignRole($ruolo);

    [$ctx, $nuovo] = f24teScenario();
    [$condominio, $vecchio] = $ctx;

    // Una delega da versare appoggiata al vecchio esercizio...
    f24tePaga($ctx, '2026-03-10', 20_000, $vecchio->id);           // 800 €
    f24teGeneratore()->esegui($condominio, $vecchio->id)->first()
        ->update(['stato' => StatoDelegaF24::CONFERMATA]);

    // ...e una ritenuta registrata sul vecchio, non ancora in nessuna delega.
    f24tePaga($ctx, '2026-09-10', 2_500, $vecchio->id);             // 100 €

    $this->actingAs($utente)
        ->get(route('admin.gestionale.f24.index', $condominio))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('esercizio.id', $nuovo->id)
            ->where('deleghe.meta.total', 1)
            ->where('riepilogo.da_versare_cents', 80_000)
            ->where('da_calcolare.numero', 1)
            ->where('da_calcolare.totale_cents', 10_000)
        );
});
