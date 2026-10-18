<?php

/**
 * La causale del bonifico parlante per lavori sulle parti comuni.
 *
 * Dal bonifico devono risultare il riferimento normativo della detrazione, il codice fiscale del
 * CONDOMINIO (per le parti comuni è lui il beneficiario), quello di chi dispone il pagamento quando
 * è noto, e la partita IVA (o il codice fiscale) del fornitore.
 *
 * La versione precedente metteva i codici fiscali dei condòmini e poi troncava a 137 caratteri con
 * «...»: con sei condòmini la partita IVA del fornitore — obbligatoria — restava fuori.
 */

use App\Enums\MetodoPagamento;
use App\Enums\TipoAllocazioneFattura;
use App\Enums\TipoDetrazione;
use App\Models\Anagrafica;
use App\Models\Gestionale\PagamentoFornitore;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/GestionaleTestHelpers.php';

test('la causale ha norma, CF del condominio, CF di chi paga e P.IVA del fornitore', function () {
    $causale = TipoDetrazione::RISTRUTTURAZIONE->generaCausalePartiComuni(
        cfCondominio: '91000000001',
        cfOrdinante: 'TSTTST80A01H501Z',
        idFiscaleFornitore: '01234567890',
        riferimentoFattura: 'Ft 12/A del 01/10/2026',
    );

    expect($causale)
        ->toContain('art. 16-bis DPR 917/1986')
        ->toContain('CF cond. 91000000001')
        ->toContain('CF amm. TSTTST80A01H501Z')
        ->toContain('P.IVA forn. 01234567890')
        ->toContain('Ft 12/A del 01/10/2026')
        ->and(mb_strlen($causale))->toBeLessThanOrEqual(TipoDetrazione::LIMITE_SEPA);
});

test('se non c\'è spazio si toglie il riferimento alla fattura, mai un dato obbligatorio', function () {
    $causale = TipoDetrazione::SUPERBONUS->generaCausalePartiComuni(
        cfCondominio: '91000000001',
        cfOrdinante: 'TSTTST80A01H501Z',
        idFiscaleFornitore: '01234567890',
        riferimentoFattura: 'Ft 2026/000123-CONDOMINIO-VIA-ROMA-12-LAVORI-FACCIATA del 01/10/2026',
    );

    expect($causale)
        ->toContain('art. 119 DL 34/2020')
        ->toContain('CF cond. 91000000001')
        ->toContain('P.IVA forn. 01234567890')
        ->not->toContain('LAVORI-FACCIATA')
        ->not->toContain('...')
        ->and(mb_strlen($causale))->toBeLessThanOrEqual(TipoDetrazione::LIMITE_SEPA);
});

test('oltre il limite SEPA i dati obbligatori restano interi', function () {
    // Condominio senza codice fiscale e sei condòmini: il ripiego sui loro codici fiscali supera
    // i 140 caratteri, e la partita IVA del fornitore deve esserci lo stesso.
    $causale = TipoDetrazione::RISTRUTTURAZIONE->generaCausalePartiComuni(
        cfCondominio: null,
        cfOrdinante: null,
        idFiscaleFornitore: '01234567890',
        riferimentoFattura: 'Ft 1 del 01/10/2026',
        cfBeneficiariRipiego: array_fill(0, 6, 'TSTTST80A01H501Z'),
    );

    expect(mb_strlen($causale))->toBeGreaterThan(TipoDetrazione::LIMITE_SEPA)
        ->and($causale)->toEndWith('P.IVA forn. 01234567890')
        ->not->toContain('...');
});

test('senza nessun codice fiscale la causale lo dice invece di tacerlo', function () {
    $causale = TipoDetrazione::ECOBONUS->generaCausalePartiComuni(null, null, null);

    expect($causale)
        ->toContain('CF cond. DA INDICARE')
        ->toContain('P.IVA forn. DA INDICARE');
});

test('il pagamento registrato porta il CF del condominio e non quelli dei condòmini', function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $utente = User::factory()->create();
    $utente->assignRole($ruolo);

    [$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo] = setupPagamentiHttp();
    $condominio->update(['codice_fiscale' => '91000000001']);
    $fornitore->update(['partita_iva' => '01234567890']);

    // L'amministratore che registra ha un'anagrafica con il suo codice fiscale.
    Anagrafica::forceCreate([
        'nome' => 'Mario Rossi', 'email' => 'rossi@test.it', 'indirizzo' => 'Via Verdi 1',
        'codice_fiscale' => 'TSTTST80A01H501Z', 'user_id' => $utente->id,
    ]);

    $fattura = registraFatturaServiceTest([$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo]);

    $this->actingAs($utente)
        ->post(route('admin.gestionale.pagamenti-fornitori.store', [$condominio]), [
            'fornitore_id' => $fornitore->id,
            'esercizio_id' => $esercizio->id,
            'conto_corrente_id' => $contoCorrenteId,
            'data_pagamento' => now()->toDateString(),
            'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
            'allocazioni' => [[
                'fattura_id' => $fattura->id,
                'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
                'importo_allocato_cents' => $fattura->netto_a_pagare,
            ]],
            'bonifico_parlante' => true,
            'allow_overdraft' => true,
            'tipo_detrazione' => TipoDetrazione::RISTRUTTURAZIONE->value,
            'beneficiari_detrazione' => [['codice_fiscale' => 'TSTTST75B15F205X']],
        ])
        ->assertSessionHasNoErrors();

    $causale = PagamentoFornitore::firstOrFail()->causale_bonifico;

    expect($causale)
        ->toContain('art. 16-bis DPR 917/1986')
        ->toContain('CF cond. 91000000001')
        ->toContain('CF amm. TSTTST80A01H501Z')
        ->toContain('P.IVA forn. 01234567890')
        ->not->toContain('TSTTST75B15F205X')
        ->and(mb_strlen($causale))->toBeLessThanOrEqual(TipoDetrazione::LIMITE_SEPA);
});
