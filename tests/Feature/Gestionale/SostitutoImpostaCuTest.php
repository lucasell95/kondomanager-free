<?php

/**
 * CU e 770 del condominio in autonomia: il prospetto annuale del sostituto d'imposta.
 *
 * Scenari (dati fittizi):
 *  - professionista al 20% con contributo integrativo 4%, IVA e un rimborso di spese anticipate;
 *  - appalto al 4% a una società (codice 1020);
 *  - fornitore forfetario senza ritenuta (esonerato dalla CU, elencato a parte);
 *  - ritenuta versata in ritardo, con stima del ravvedimento e annotazione degli interessi;
 *  - una fattura pagata a cavallo d'anno, che si divide fra due CU (principio di cassa);
 *  - ritenuta non versata e scaduta;
 *  - pagine, stampe ed esportazioni.
 */

use App\Actions\Gestionale\Movimenti\ConfermaVersamentoF24Action;
use App\Actions\Gestionale\Movimenti\GeneraDelegheF24Action;
use App\Enums\TipoAllocazioneFattura;
use App\Models\Condominio;
use App\Models\Fornitore;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PagamentoFornitore;
use App\Models\Gestionale\RavvedimentoF24;
use App\Models\User;
use App\Services\Gestionale\Fiscale\AnagraficaPercipiente;
use App\Services\Gestionale\Fiscale\ProspettoSostitutoService;
use App\Services\Gestionale\Fiscale\RavvedimentoService;
use App\Services\Gestionale\PagamentoFornitoreService;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/GestionaleTestHelpers.php';

function cu770Ctx(): array
{
    $ctx = setupPagamentiService();
    $ctx[0]->forceFill([
        'codice_fiscale' => '90000000001',
        'comune' => 'Bergamo',
        'provincia' => 'BG',
        'cap' => '24100',
        'indirizzo' => 'Via Verdi 1',
    ])->save();

    return $ctx;
}

function cu770Fornitore(array $dati): Fornitore
{
    return Fornitore::create(array_merge([
        'giorni_scadenza' => 30,
        'modalita_pagamento_default' => 'bonifico',
        'perc_imponibile_ritenuta' => 100,
        'stato' => 'attivo',
        'indirizzo' => 'Via Bianchi 2',
        'cap' => '24100',
        'comune' => 'Bergamo',
        'provincia' => 'BG',
    ], $dati));
}

/** Il contesto dei test con un altro fornitore al posto di quello di default. */
function cu770Con(array $ctx, Fornitore $fornitore): array
{
    $ctx[3] = $fornitore;

    return $ctx;
}

/** @param array<int,array<string,mixed>> $righe */
function cu770Fattura(array $ctx, array $righe): FatturaPassiva
{
    $righe = array_map(fn ($r) => array_merge([
        'aliquota_iva' => 22,
        'conto_id' => $ctx[5]->id,
        'is_sopravvenienza' => false,
    ], $r), $righe);

    return registraFatturaServiceTest($ctx, ['applica_ritenuta' => true, 'righe' => $righe]);
}

function cu770Paga(array $ctx, FatturaPassiva $fattura, string $data, ?int $importoCents = null): PagamentoFornitore
{
    $extra = ['data_pagamento' => $data];

    if ($importoCents !== null) {
        $extra['allocazioni'] = [[
            'fattura_id' => $fattura->id,
            'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
            'importo_allocato_cents' => $importoCents,
        ]];
    }

    return (new PagamentoFornitoreService)->registraPagamento(datiPagamento($ctx, $fattura->fresh(), $extra));
}

/** Calcola le deleghe e le versa tutte alla data indicata. */
function cu770Versa(array $ctx, string $data): void
{
    $deleghe = app(GeneraDelegheF24Action::class)->esegui($ctx[0], $ctx[1]->id);

    foreach ($deleghe as $delega) {
        app(ConfermaVersamentoF24Action::class)->esegui($delega, $data, $ctx[4]);
    }
}

function cu770Prospetto(array $ctx, int $anno, string $oggi = '2027-02-01'): array
{
    return app(ProspettoSostitutoService::class)->prospetto($ctx[0]->fresh(), $anno, CarbonImmutable::parse($oggi));
}

function cu770Professionista(array $ctx): array
{
    $fornitore = cu770Fornitore([
        'ragione_sociale' => 'Studio Ing. Rossi Mario',
        'codice_fiscale' => 'TSTTST80A01H501X',
        'soggetto_ritenuta' => true,
        'perc_ritenuta' => 20,
        'codice_tributo' => '1040',
        'tipo_ritenuta' => 'lavoro_autonomo_20',
        'cognome' => 'Rossi',
        'nome' => 'Mario',
        'sesso' => 'M',
        'data_nascita' => '1980-01-01',
        'comune_nascita' => 'Roma',
        'provincia_nascita' => 'RM',
        'codice_catastale_nascita' => 'H501',
    ]);

    $ctx = cu770Con($ctx, $fornitore);

    $fattura = cu770Fattura($ctx, [
        ['descrizione' => 'Onorario direzione lavori', 'importo_imponibile' => 1000, 'natura_riga_ritenuta' => 'imponibile', 'concorre_base_ritenuta' => true],
        ['descrizione' => 'Contributo integrativo 4%', 'importo_imponibile' => 40, 'natura_riga_ritenuta' => 'cassa_professionale', 'concorre_base_ritenuta' => false],
        ['descrizione' => 'Diritti anticipati', 'importo_imponibile' => 50, 'aliquota_iva' => 0, 'natura_riga_ritenuta' => 'rimborso_art15', 'concorre_base_ritenuta' => false],
    ]);

    return [$ctx, $fornitore, $fattura];
}

function cu770Admin(): User
{
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $utente = User::factory()->create();
    $utente->assignRole($ruolo);

    return $utente;
}

// ── Professionista 20% con cassa 4% e IVA ───────────────────────────────────

test('professionista: compenso al netto di IVA e cassa, rimborso fra le somme non soggette', function () {
    $ctx = cu770Ctx();
    [$ctx, $fornitore, $fattura] = cu770Professionista($ctx);

    // 1.000 onorario + 40 cassa + 50 rimborso, IVA 22% su 1.040: totale 1.318,80, ritenuta 200.
    expect($fattura->importo_ritenuta)->toBe(20_000)
        ->and($fattura->netto_a_pagare)->toBe(111_880);

    cu770Paga($ctx, $fattura, '2026-03-10');
    cu770Versa($ctx, '2026-04-15');

    $p = cu770Prospetto($ctx, 2026);

    expect($p['percipienti'])->toHaveCount(1);
    $cu = $p['percipienti'][0];

    expect($cu['causale'])->toBe('A')
        ->and($cu['punti']['4'])->toBe(105_000)        // onorario + rimborso, senza cassa e IVA
        ->and($cu['punti']['6'])->toBe('22')
        ->and($cu['punti']['7'])->toBe(5_000)
        ->and($cu['punti']['8'])->toBe(100_000)
        ->and($cu['punti']['9'])->toBe(20_000)
        ->and($cu['punti']['10'])->toBe(0)
        ->and($cu['ritenute_versate'])->toBe(20_000)
        ->and($cu['differenza'])->toBe(0)
        ->and($cu['anomalie'])->toBe([])
        ->and($cu['anagrafica']['persona_fisica'])->toBeTrue()
        ->and($cu['anagrafica']['cognome'])->toBe('Rossi');

    $pag = $cu['pagamenti'][0];
    expect($pag['lordo_con_iva'])->toBe(131_880)
        ->and($pag['codice_tributo'])->toBe('1040')
        ->and($pag['scadenza_legale'])->toBe('2026-04-16')
        ->and($pag['stato_versamento'])->toBe('regolare');

    expect($p['quadratura']['in_quadratura'])->toBeTrue();

    expect($p['st'])->toHaveCount(1);
    expect($p['st'][0])->toMatchArray([
        'periodo' => '03/2026',
        'codice_tributo' => '1040',
        'ritenute_operate' => 20_000,
        'interessi' => 0,
        'ravvedimento' => false,
        'importo_versato' => 20_000,
        'data_versamento' => '2026-04-15',
    ]);
});

// ── Appalto 4% a una società ────────────────────────────────────────────────

test('appalto al 4% a una società: causale W, codice 1020, nessun dato di nascita richiesto', function () {
    $ctx = cu770Ctx();
    $fornitore = cu770Fornitore([
        'ragione_sociale' => 'Bianchi Costruzioni Srl',
        'codice_fiscale' => '00000000001',
        'partita_iva' => '00000000001',
        'soggetto_ritenuta' => true,
        'perc_ritenuta' => 4,
        'tipo_ritenuta' => 'appalto_4',
        'natura_percipiente' => 'soggetto_ires',
    ]);
    $ctx = cu770Con($ctx, $fornitore);

    $fattura = cu770Fattura($ctx, [['descrizione' => 'Manutenzione tetto', 'importo_imponibile' => 5000]]);
    cu770Paga($ctx, $fattura, '2026-05-20');
    cu770Versa($ctx, '2026-06-16');

    $cu = cu770Prospetto($ctx, 2026)['percipienti'][0];

    expect($cu['causale'])->toBe('W')
        ->and($cu['punti']['4'])->toBe(500_000)
        ->and($cu['punti']['7'])->toBe(0)
        ->and($cu['punti']['8'])->toBe(500_000)
        ->and($cu['punti']['9'])->toBe(20_000)
        ->and($cu['anagrafica']['persona_fisica'])->toBeFalse()
        ->and($cu['anomalie'])->toBe([])
        ->and($cu['pagamenti'][0]['codice_tributo'])->toBe('1020')
        // Sotto i 500 €: si versa entro il 16 giugno.
        ->and($cu['pagamenti'][0]['scadenza_legale'])->toBe('2026-06-16')
        ->and($cu['pagamenti'][0]['stato_versamento'])->toBe('regolare');
});

// ── Forfetario ──────────────────────────────────────────────────────────────

test('forfetario: nessuna ritenuta, fuori dalle CU ed elencato a parte con il compenso', function () {
    $ctx = cu770Ctx();
    $fornitore = cu770Fornitore([
        'ragione_sociale' => 'Verdi Giardini di Verdi Anna',
        'codice_fiscale' => 'TSTTST85M41F205Y',
        'soggetto_ritenuta' => true,
        'perc_ritenuta' => 4,
        'tipo_ritenuta' => 'appalto_4',
        'regime_forfetario' => true,
        'forfetario_dichiarato_il' => '2026-01-10',
    ]);
    $ctx = cu770Con($ctx, $fornitore);

    $fattura = cu770Fattura($ctx, [['descrizione' => 'Potatura', 'importo_imponibile' => 300, 'aliquota_iva' => 0]]);
    expect($fattura->importo_ritenuta)->toBe(0);

    cu770Paga($ctx, $fattura, '2026-07-01');

    $p = cu770Prospetto($ctx, 2026);

    expect($p['percipienti'])->toBe([])
        ->and($p['forfetari'])->toHaveCount(1)
        ->and($p['forfetari'][0]['compenso'])->toBe(30_000)
        ->and($p['forfetari'][0]['codice_fiscale'])->toBe('TSTTST85M41F205Y')
        ->and($p['st'])->toBe([]);
});

// ── Ritenuta versata in ritardo ─────────────────────────────────────────────

test('ritenuta versata in ritardo: stato tardivo, stima del ravvedimento e casella del quadro ST', function () {
    $ctx = cu770Ctx();
    $fornitore = cu770Fornitore([
        'ragione_sociale' => 'Rossi Impianti di Rossi Luca',
        'codice_fiscale' => 'TSTTST70C10A794Z',
        'soggetto_ritenuta' => true,
        'perc_ritenuta' => 4,
        'tipo_ritenuta' => 'appalto_4',
        'natura_percipiente' => 'persona_fisica_irpef',
    ]);
    $ctx = cu770Con($ctx, $fornitore);

    // 15.000 € → ritenuta 600 €: soglia raggiunta a febbraio, scadenza il 16 marzo.
    $fattura = cu770Fattura($ctx, [['descrizione' => 'Rifacimento impianto', 'importo_imponibile' => 15000]]);
    cu770Paga($ctx, $fattura, '2026-02-10');
    cu770Versa($ctx, '2026-04-10');

    $p = cu770Prospetto($ctx, 2026);
    $pag = $p['percipienti'][0]['pagamenti'][0];

    expect($pag['codice_tributo'])->toBe('1019')
        ->and($pag['scadenza_legale'])->toBe('2026-03-16')
        ->and($pag['stato_versamento'])->toBe('tardivo')
        ->and($pag['giorni_ritardo'])->toBe(25)
        // 600 € × 1,6% × 25/365 = 0,66 €; sanzione ridotta 1,25% entro 30 giorni = 7,50 €.
        ->and($pag['stima_ravvedimento']['interessi'])->toBe(66)
        ->and($pag['stima_ravvedimento']['sanzione'])->toBe(750)
        ->and($pag['stima_ravvedimento']['da_verificare'])->toBeTrue();

    expect($p['quadratura']['ravvedimenti'])->toHaveCount(1)
        ->and($p['quadratura']['in_quadratura'])->toBeFalse();

    // Mancano i dati di nascita della ditta individuale: segnalati, con i suggerimenti dal CF.
    $anagrafica = $p['percipienti'][0]['anagrafica'];
    expect($anagrafica['persona_fisica'])->toBeTrue()
        ->and($anagrafica['suggeriti_da_cf']['sesso'])->toBe('M')
        ->and($anagrafica['suggeriti_da_cf']['data_nascita'])->toBe('1970-03-10')
        ->and($anagrafica['suggeriti_da_cf']['codice_catastale'])->toBe('A794')
        ->and(collect($p['percipienti'][0]['anomalie'])->contains(fn ($a) => str_contains($a, 'mancano cognome')))->toBeTrue();

    expect($p['st'][0]['ravvedimento'])->toBeTrue()
        ->and($p['st'][0]['interessi'])->toBe(0);

    // Annotati gli interessi versati, il quadro ST li riporta e li somma al versato.
    $rigaId = $p['quadratura']['ravvedimenti'][0]['riga_f24_id'];
    expect($rigaId)->not->toBeNull();

    $this->actingAs(cu770Admin())
        ->post(route('admin.gestionale.fiscale.ravvedimenti.store', $ctx[0]), [
            'riga_f24_id' => $rigaId,
            'interessi' => '0,66',
            'sanzione' => '7,50',
            'codice_tributo_sanzione' => '8948',
            'data_versamento' => '2026-04-10',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $dopo = cu770Prospetto($ctx, 2026);
    expect($dopo['st'][0]['interessi'])->toBe(66)
        ->and($dopo['st'][0]['importo_versato'])->toBe(60_066)
        ->and($dopo['st'][0]['ritenute_operate'])->toBe(60_000)
        ->and($dopo['percipienti'][0]['pagamenti'][0]['ravvedimento']['sanzione'])->toBe(750);
});

test('una ritenuta non versata e scaduta risulta omessa con la differenza nella quadratura', function () {
    $ctx = cu770Ctx();
    $ctx[3]->update(['soggetto_ritenuta' => true, 'perc_ritenuta' => 4, 'tipo_ritenuta' => 'appalto_4', 'natura_percipiente' => 'soggetto_ires']);
    $ctx[3]->refresh();

    $fattura = cu770Fattura($ctx, [['descrizione' => 'Pulizie', 'importo_imponibile' => 2500]]);
    cu770Paga($ctx, $fattura, '2026-03-10');

    // Sotto soglia: scade il 16 giugno. Al 1° luglio è omessa.
    $p = cu770Prospetto($ctx, 2026, '2026-07-01');
    $pag = $p['percipienti'][0]['pagamenti'][0];

    expect($pag['stato_versamento'])->toBe('omesso')
        ->and($pag['giorni_ritardo'])->toBe(15)
        ->and($p['quadratura']['differenze'])->toHaveCount(1)
        ->and($p['quadratura']['differenze'][0]['differenza'])->toBe(10_000)
        ->and($p['percipienti'][0]['differenza'])->toBe(10_000);

    // Prima della scadenza la stessa ritenuta è semplicemente da versare.
    $prima = cu770Prospetto($ctx, 2026, '2026-06-01');
    expect($prima['percipienti'][0]['pagamenti'][0]['stato_versamento'])->toBe('da_versare')
        ->and($prima['quadratura']['da_versare'])->toHaveCount(1);
});

// ── Pagamenti a cavallo d'anno ──────────────────────────────────────────────

test('una fattura pagata metà a dicembre e metà a gennaio si divide fra due CU', function () {
    $ctx = cu770Ctx();
    $ctx[3]->update(['soggetto_ritenuta' => true, 'perc_ritenuta' => 4, 'tipo_ritenuta' => 'appalto_4', 'natura_percipiente' => 'soggetto_ires']);
    $ctx[3]->refresh();

    // 10.000 € + IVA → ritenuta 400 €, netto 11.800 €.
    $fattura = cu770Fattura($ctx, [['descrizione' => 'Facciata', 'importo_imponibile' => 10000]]);
    expect($fattura->netto_a_pagare)->toBe(1_180_000);

    cu770Paga($ctx, $fattura, '2026-12-20', 590_000);
    cu770Paga($ctx, $fattura, '2027-01-15', 590_000);

    $p2026 = cu770Prospetto($ctx, 2026, '2026-12-31');
    $p2027 = cu770Prospetto($ctx, 2027, '2027-02-01');

    expect($p2026['percipienti'][0]['punti']['4'])->toBe(500_000)
        ->and($p2026['percipienti'][0]['punti']['9'])->toBe(20_000)
        ->and($p2027['percipienti'][0]['punti']['4'])->toBe(500_000)
        ->and($p2027['percipienti'][0]['punti']['9'])->toBe(20_000);

    // Le ritenute di dicembre si versano entro il 16 gennaio (2027: sabato → lunedì 18).
    expect($p2026['percipienti'][0]['pagamenti'][0]['scadenza_legale'])->toBe('2027-01-18')
        ->and($p2027['percipienti'][0]['pagamenti'][0]['scadenza_legale'])->toBe('2027-06-16');
});

// ── Servizi puri ────────────────────────────────────────────────────────────

test('dal codice fiscale si ricavano sesso, data e codice catastale di nascita', function () {
    expect(AnagraficaPercipiente::datiDaCodiceFiscale('TSTTST85M41F205Y'))->toBe([
        'sesso' => 'F',
        'data_nascita' => '1985-08-01',
        'codice_catastale' => 'F205',
    ])
        ->and(AnagraficaPercipiente::datiDaCodiceFiscale('00000000001'))->toBeNull()
        ->and(AnagraficaPercipiente::cfPersonaFisica('TSTTST80A01H501X'))->toBeTrue();
});

test('la stima del ravvedimento segue le fasce configurate', function () {
    $s = new RavvedimentoService;

    expect($s->percentualeSanzione(10))->toBe(0.833)
        ->and($s->percentualeSanzione(30))->toBe(1.25)
        ->and($s->percentualeSanzione(60))->toBe(1.389)
        ->and($s->percentualeSanzione(200))->toBe(3.125)
        ->and($s->percentualeSanzione(400))->toBe(3.571);

    $nessuna = $s->stima(10_000, CarbonImmutable::parse('2026-03-16'), CarbonImmutable::parse('2026-03-16'));
    expect($nessuna['giorni'])->toBe(0)->and($nessuna['sanzione'])->toBe(0);
});

// ── Pagine, stampe ed esportazioni ──────────────────────────────────────────

test('la pagina CU e 770 mostra il prospetto dell\'anno richiesto', function () {
    $ctx = cu770Ctx();
    [$ctx] = cu770Professionista($ctx);
    $fattura = FatturaPassiva::latest('id')->first();
    cu770Paga($ctx, $fattura, '2026-03-10');

    $this->actingAs(cu770Admin())
        ->get(route('admin.gestionale.fiscale.cu770.index', ['condominio' => $ctx[0]->id, 'anno' => 2026]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('gestionale/fiscale/SostitutoImposta')
            ->where('anno', 2026)
            ->where('prospetto.totali.percipienti', 1)
            ->where('prospetto.totali.ritenute_operate', 20_000)
            ->where('prospetto.percipienti.0.causale', 'A')
        );
});

test('prospetto PDF, CSV, XLSX e CU sintetica si scaricano; la CU di un estraneo no', function () {
    $ctx = cu770Ctx();
    [$ctx, $fornitore, $fattura] = cu770Professionista($ctx);
    cu770Paga($ctx, $fattura, '2026-03-10');
    $admin = cu770Admin();
    $c = $ctx[0]->id;

    $this->actingAs($admin)
        ->get(route('admin.gestionale.fiscale.cu770.pdf', ['condominio' => $c, 'anno' => 2026]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $csv = $this->actingAs($admin)
        ->get(route('admin.gestionale.fiscale.cu770.export', ['condominio' => $c, 'anno' => 2026, 'formato' => 'csv']).'?sezione=percipienti');
    $csv->assertOk();
    expect($csv->getContent())->toContain('TSTTST80A01H501X')
        ->and($csv->getContent())->toContain('1050,00')
        ->and($csv->getContent())->toContain(';');

    $this->actingAs($admin)
        ->get(route('admin.gestionale.fiscale.cu770.export', ['condominio' => $c, 'anno' => 2026, 'formato' => 'xlsx']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $this->actingAs($admin)
        ->get(route('admin.gestionale.fiscale.cu770.cu', ['condominio' => $c, 'anno' => 2026, 'fornitoreId' => $fornitore->id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    // Un fornitore a cui il condominio non ha pagato niente con ritenuta non ha CU.
    $this->actingAs($admin)
        ->get(route('admin.gestionale.fiscale.cu770.cu', ['condominio' => $c, 'anno' => 2026, 'fornitoreId' => $ctx[3]->id + 999]))
        ->assertNotFound();
});

test('i dati del firmatario si salvano sul condominio e spariscono dalle anomalie', function () {
    $ctx = cu770Ctx();
    $admin = cu770Admin();

    $prima = app(ProspettoSostitutoService::class)->sostituto($ctx[0]->fresh());
    expect(collect($prima['anomalie'])->contains(fn ($a) => str_contains($a, 'firmatario')))->toBeTrue();

    $this->actingAs($admin)
        ->put(route('admin.gestionale.fiscale.sostituto.update', $ctx[0]), [
            'firmatario_cognome' => 'Bianchi',
            'firmatario_nome' => 'Luigi',
            'firmatario_codice_fiscale' => 'tsttst75b02a794k',
            'firmatario_codice_carica' => '13',
            'firmatario_data_carica' => '2025-06-01',
            'sostituto_telefono' => '035000000',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $dopo = app(ProspettoSostitutoService::class)->sostituto($ctx[0]->fresh());
    expect($dopo['firmatario']['codice_fiscale'])->toBe('TSTTST75B02A794K')
        ->and($dopo['firmatario']['codice_carica'])->toBe('13')
        ->and($dopo['firmatario']['data_carica'])->toBe('2025-06-01')
        ->and($dopo['anomalie'])->toBe([]);

    // Codice carica non previsto: rifiutato.
    $this->actingAs($admin)
        ->put(route('admin.gestionale.fiscale.sostituto.update', $ctx[0]), ['firmatario_codice_carica' => '99'])
        ->assertSessionHasErrors('firmatario_codice_carica');
});

test('il ravvedimento di un altro condominio non si tocca', function () {
    $ctx = cu770Ctx();
    $altro = Condominio::factory()->create();
    $admin = cu770Admin();

    $ctx[3]->update(['soggetto_ritenuta' => true, 'perc_ritenuta' => 4, 'tipo_ritenuta' => 'appalto_4', 'natura_percipiente' => 'soggetto_ires']);
    $fattura = cu770Fattura($ctx, [['descrizione' => 'Lavori', 'importo_imponibile' => 15000]]);
    cu770Paga($ctx, $fattura, '2026-02-10');
    cu770Versa($ctx, '2026-04-10');

    $rigaId = \App\Models\Gestionale\RigaF24::query()->value('id');
    $ravv = RavvedimentoF24::create([
        'condominio_id' => $ctx[0]->id,
        'riga_f24_id' => $rigaId,
        'interessi' => 66,
        'sanzione' => 750,
        'data_versamento' => '2026-04-10',
    ]);

    // Dall'indirizzo di un altro condominio: 404, e l'annotazione resta.
    $this->actingAs($admin)
        ->delete(route('admin.gestionale.fiscale.ravvedimenti.destroy', ['condominio' => $altro->id, 'ravvedimentoId' => $ravv->id]))
        ->assertNotFound();

    expect(RavvedimentoF24::find($ravv->id))->not->toBeNull();

    // Né si annota su una riga F24 altrui.
    $this->actingAs($admin)
        ->post(route('admin.gestionale.fiscale.ravvedimenti.store', $altro), [
            'riga_f24_id' => $rigaId, 'interessi' => '1', 'sanzione' => '1', 'data_versamento' => '2026-04-10',
        ])
        ->assertNotFound();
});

test('i dati per la CU si salvano dalla scheda del fornitore', function () {
    $fornitore = cu770Fornitore(['ragione_sociale' => 'Studio Verdi', 'codice_fiscale' => 'TSTTST80A01H501X']);

    $this->actingAs(cu770Admin())
        ->put(route('admin.fornitori.update', $fornitore), [
            'ragione_sociale' => 'Studio Verdi',
            'codice_fiscale' => 'TSTTST80A01H501X',
            'stato' => 'attivo',
            'modalita_pagamento_default' => 'bonifico',
            'giorni_scadenza' => 30,
            'cognome' => 'Verdi',
            'nome' => 'Paolo',
            'sesso' => 'm',
            'data_nascita' => '1980-01-01',
            'comune_nascita' => 'Roma',
            'provincia_nascita' => 'rm',
            'codice_catastale_nascita' => 'h501',
        ])
        ->assertSessionHasNoErrors();

    $fornitore->refresh();
    expect($fornitore->cognome)->toBe('Verdi')
        ->and($fornitore->sesso)->toBe('M')
        ->and($fornitore->data_nascita->toDateString())->toBe('1980-01-01')
        ->and($fornitore->provincia_nascita)->toBe('RM')
        ->and($fornitore->codice_catastale_nascita)->toBe('H501');
});
