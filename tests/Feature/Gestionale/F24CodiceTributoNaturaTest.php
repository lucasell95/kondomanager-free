<?php

/**
 * 1019 o 1020: il codice tributo delle ritenute sugli appalti dipende dalla natura del percipiente.
 *
 * 1019 = percipiente soggetto IRPEF (persona fisica, ditta individuale, società di persone);
 * 1020 = percipiente soggetto IRES (società di capitali, enti — anche non commerciali, art. 73
 * c. 1 lett. c TUIR).
 *
 * Il generatore delle deleghe, se l'anagrafica non diceva la natura, metteva 1019 in silenzio: per
 * una S.r.l. è il tributo sbagliato. Ora:
 *  - la natura si ricava da ciò che l'utente ha indicato (scatto del pagamento, anagrafica, oppure il
 *    codice tributo scritto a mano sull'anagrafica);
 *  - quando manca il ripiego resta 1019, ma la delega lo dichiara «da verificare»;
 *  - le deleghe già confermate o versate non vengono riscritte.
 */

use App\Actions\Gestionale\Movimenti\GeneraDelegheF24Action;
use App\Enums\Fiscale\NaturaPercipiente;
use App\Enums\Fiscale\StatoDelegaF24;
use App\Enums\Fiscale\TipoRitenuta;
use App\Models\User;
use App\Services\Gestionale\CalendarioFiscaleService;
use App\Services\Gestionale\PagamentoFornitoreService;
use App\Services\Gestionale\PlafondRitenuteService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/GestionaleTestHelpers.php';

function f24ntGeneratore(): GeneraDelegheF24Action
{
    return new GeneraDelegheF24Action(new PlafondRitenuteService(new CalendarioFiscaleService));
}

/** Fornitore al 4% con i campi fiscali indicati, e un pagamento con 800 € di ritenuta. */
function f24ntScenario(array $fornitore = []): array
{
    $ctx = setupPagamentiService();
    $ctx[3]->update(array_merge([
        'soggetto_ritenuta' => true, 'perc_ritenuta' => 4, 'perc_imponibile_ritenuta' => 100,
    ], $fornitore));
    $ctx[3]->refresh();

    $fattura = registraFatturaServiceTest($ctx, [
        'applica_ritenuta' => true,
        'righe' => [[
            'descrizione' => 'Manutenzione', 'importo_imponibile' => 20_000, 'aliquota_iva' => 22,
            'conto_id' => $ctx[5]->id, 'is_sopravvenienza' => false,
        ]],
    ]);

    (new PagamentoFornitoreService)->registraPagamento(
        datiPagamento($ctx, $fattura, ['data_pagamento' => '2026-03-10'])
    );

    return $ctx;
}

test('una società di capitali dichiarata va su 1020', function () {
    $ctx = f24ntScenario(['natura_percipiente' => NaturaPercipiente::SOGGETTO_IRES->value]);

    $delega = f24ntGeneratore()->esegui($ctx[0], $ctx[1]->id)->first();

    expect($delega->righe->first()->codice_tributo)->toBe('1020')
        ->and(f24ntGeneratore()->codiciDaVerificare($delega))->toBe([]);
});

test('anche l\'ente non commerciale è soggetto IRES: 1020', function () {
    expect(NaturaPercipiente::ENTE_NON_COMMERCIALE->ires())->toBeTrue()
        ->and(TipoRitenuta::APPALTO_4->codiceTributo(NaturaPercipiente::ENTE_NON_COMMERCIALE))->toBe('1020')
        ->and(TipoRitenuta::APPALTO_4->codiceTributo(NaturaPercipiente::SOCIETA_PERSONE_IRPEF))->toBe('1019');
});

test('il codice tributo scritto a mano sull\'anagrafica vale come natura', function () {
    $ctx = f24ntScenario(['natura_percipiente' => null, 'codice_tributo' => '1020']);

    $delega = f24ntGeneratore()->esegui($ctx[0], $ctx[1]->id)->first();

    expect($delega->righe->first()->codice_tributo)->toBe('1020')
        ->and(f24ntGeneratore()->codiciDaVerificare($delega))->toBe([]);
});

test('senza natura il 1019 resta, ma la delega lo dichiara da verificare', function () {
    $ctx = f24ntScenario(['natura_percipiente' => null, 'codice_tributo' => null]);

    $delega = f24ntGeneratore()->esegui($ctx[0], $ctx[1]->id)->first();
    $avvisi = f24ntGeneratore()->codiciDaVerificare($delega);

    expect($delega->righe->first()->codice_tributo)->toBe('1019')
        ->and($avvisi)->toHaveCount(1)
        ->and($avvisi[0]['fornitore_id'])->toBe($ctx[3]->id)
        ->and($avvisi[0]['codice_tributo'])->toBe('1019')
        // «Fornitore Test Srl»: la forma societaria è solo un indizio per il messaggio.
        ->and($avvisi[0]['probabile_ires'])->toBeTrue();
});

test('completata l\'anagrafica, il ricalcolo corregge la bozza ma non la delega confermata', function () {
    $ctx = f24ntScenario(['natura_percipiente' => null, 'codice_tributo' => null]);

    $confermata = f24ntGeneratore()->esegui($ctx[0], $ctx[1]->id)->first();
    $confermata->update(['stato' => StatoDelegaF24::CONFERMATA]);

    // Secondo pagamento, ancora in bozza.
    $fattura = registraFatturaServiceTest($ctx, [
        'applica_ritenuta' => true,
        'righe' => [[
            'descrizione' => 'Pulizie', 'importo_imponibile' => 20_000, 'aliquota_iva' => 22,
            'conto_id' => $ctx[5]->id, 'is_sopravvenienza' => false,
        ]],
    ]);
    (new PagamentoFornitoreService)->registraPagamento(
        datiPagamento($ctx, $fattura, ['data_pagamento' => '2026-04-10'])
    );

    $ctx[3]->update(['natura_percipiente' => NaturaPercipiente::SOGGETTO_IRES->value]);

    $bozza = f24ntGeneratore()->esegui($ctx[0], $ctx[1]->id)->first();

    expect($bozza->righe->first()->codice_tributo)->toBe('1020')
        ->and($confermata->fresh()->righe->first()->codice_tributo)->toBe('1019');
});

test('la scheda e l\'elenco F24 portano l\'avviso fino alla pagina', function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $utente = User::factory()->create();
    $utente->assignRole($ruolo);

    $ctx = f24ntScenario(['natura_percipiente' => null, 'codice_tributo' => null]);
    $delega = f24ntGeneratore()->esegui($ctx[0], $ctx[1]->id)->first();

    $this->actingAs($utente)
        ->get(route('admin.gestionale.f24.show', [$ctx[0], $delega]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('codici_da_verificare', 1)
            ->where('codici_da_verificare.0.codice_tributo', '1019')
        );

    $this->actingAs($utente)
        ->get(route('admin.gestionale.f24.index', $ctx[0]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('codici_da_verificare', 1));
});
