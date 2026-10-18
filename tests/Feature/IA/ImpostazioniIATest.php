<?php

/**
 * La pagina delle impostazioni IA: permessi, chiave API che non arriva mai al browser,
 * salvataggio cifrato con «lascia vuoto per non cambiare», prova di connessione.
 */

use App\Enums\Role as RoleEnum;
use App\Models\User;
use App\Models\UtilizzoIA;
use App\Settings\IASettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

const CHIAVE_PAGINA_IA = 'sk-ant-api03-chiave-segreta-di-prova-000000ABCD';

beforeEach(function () {
    Sleep::fake();
    Http::preventStrayRequests();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->assignRole(RoleEnum::AMMINISTRATORE->value);

    $this->collaboratore = User::factory()->create(['email_verified_at' => now()]);
    $this->collaboratore->assignRole(RoleEnum::COLLABORATORE->value);

    $this->utente = User::factory()->create(['email_verified_at' => now()]);
    $this->utente->assignRole(RoleEnum::UTENTE->value);
});

function salvaChiaveIAPerPagina(): IASettings
{
    $settings = app(IASettings::class);
    $settings->api_key = Crypt::encryptString(CHIAVE_PAGINA_IA);
    $settings->save();

    return $settings;
}

function moduloIA(array $modifiche = []): array
{
    return array_merge([
        'ia_abilitata'         => false,
        'api_key'              => null,
        'rimuovi_chiave'       => false,
        'modello'              => 'claude-sonnet-5-5',
        'tetto_spesa_mensile'  => null,
        'conferma_informativa' => false,
    ], $modifiche);
}

it('mostra la pagina a chi gestisce le impostazioni generali, con l\'IA spenta di default', function () {
    $this->actingAs($this->admin)
        ->get(route('impostazioni.ia'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $pagina) => $pagina
            ->component('impostazioni/impostazioniIA', false)
            ->where('impostazioni.ia_abilitata', false)
            ->where('impostazioni.modello', 'claude-sonnet-5-5')
            ->where('chiave.impostata', false)
        );
});

it('nega pagina, salvataggio e prova a chi non gestisce le impostazioni generali', function (string $chi) {
    $settings = salvaChiaveIAPerPagina();
    $chiaveCifrata = $settings->api_key;
    Http::fake();

    $this->actingAs($this->{$chi})->get(route('impostazioni.ia'))->assertForbidden();

    $this->actingAs($this->{$chi})
        ->post(route('impostazioni.ia.update'), moduloIA(['api_key' => 'sk-ant-api03-chiave-dell-intruso-0000000000']))
        ->assertForbidden();

    $this->actingAs($this->{$chi})->postJson(route('impostazioni.ia.test'), [])->assertForbidden();

    expect(app(IASettings::class)->refresh()->api_key)->toBe($chiaveCifrata);
    Http::assertNothingSent();
})->with(['collaboratore', 'utente']);

it('richiede l\'accesso', function () {
    $this->get(route('impostazioni.ia'))->assertRedirect(route('login'));
});

it('non manda mai la chiave al browser, né in chiaro né cifrata', function () {
    $settings = salvaChiaveIAPerPagina();
    $props = null;

    $risposta = $this->actingAs($this->admin)
        ->get(route('impostazioni.ia'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $pagina) use (&$props) {
            $props = $pagina->toArray()['props'];
        });

    $json = json_encode($props, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    expect($props['chiave'])->toBe(['impostata' => true, 'leggibile' => true, 'finale' => 'ABCD'])
        ->and($json)->not->toContain(CHIAVE_PAGINA_IA)
        ->and($json)->not->toContain('chiave-segreta')
        ->and($json)->not->toContain($settings->api_key)
        ->and($risposta->getContent())->not->toContain('chiave-segreta');
});

it('salva la chiave cifrata e la conserva se il campo resta vuoto', function () {
    $this->actingAs($this->admin)
        ->post(route('impostazioni.ia.update'), moduloIA([
            'ia_abilitata' => true,
            'api_key' => CHIAVE_PAGINA_IA,
            'tetto_spesa_mensile' => '5.50',
            'conferma_informativa' => true,
        ]))
        ->assertSessionHasNoErrors();

    $settings = app(IASettings::class)->refresh();
    expect($settings->ia_abilitata)->toBeTrue()
        ->and($settings->api_key)->not->toBe(CHIAVE_PAGINA_IA)
        ->and($settings->chiaveInChiaro())->toBe(CHIAVE_PAGINA_IA)
        ->and($settings->tetto_spesa_mensile_centesimi)->toBe(550)
        ->and($settings->informativa_confermata_il)->not->toBeNull();

    // Secondo salvataggio senza chiave: resta quella di prima.
    $this->actingAs($this->admin)
        ->post(route('impostazioni.ia.update'), moduloIA(['ia_abilitata' => true, 'modello' => 'claude-opus-5-5']))
        ->assertSessionHasNoErrors();

    $settings->refresh();
    expect($settings->chiaveInChiaro())->toBe(CHIAVE_PAGINA_IA)
        ->and($settings->modello)->toBe('claude-opus-5-5')
        ->and($settings->tetto_spesa_mensile_centesimi)->toBeNull();
});

it('per attivare l\'IA pretende chiave e conferma dell\'informativa', function () {
    $this->actingAs($this->admin)
        ->post(route('impostazioni.ia.update'), moduloIA(['ia_abilitata' => true]))
        ->assertSessionHasErrors(['api_key', 'conferma_informativa']);

    expect(app(IASettings::class)->refresh()->ia_abilitata)->toBeFalse();
});

it('rifiuta una chiave che non è di Anthropic e un modello con caratteri strani', function () {
    $this->actingAs($this->admin)
        ->post(route('impostazioni.ia.update'), moduloIA(['api_key' => 'chiave-qualsiasi-di-altro-fornitore', 'modello' => 'Claude Sonnet!']))
        ->assertSessionHasErrors(['api_key', 'modello']);
});

it('rimuove la chiave e spegne l\'IA', function () {
    $settings = salvaChiaveIAPerPagina();
    $settings->ia_abilitata = true;
    $settings->informativa_confermata_il = now()->toIso8601String();
    $settings->save();

    $this->actingAs($this->admin)
        ->post(route('impostazioni.ia.update'), moduloIA(['ia_abilitata' => false, 'rimuovi_chiave' => true]))
        ->assertSessionHasNoErrors();

    $settings->refresh();
    expect($settings->api_key)->toBeNull()->and($settings->ia_abilitata)->toBeFalse();
});

it('prova la connessione con la chiave salvata senza consumare token', function () {
    salvaChiaveIAPerPagina();
    Http::fake(['https://api.anthropic.com/v1/models/claude-sonnet-5-5' => Http::response([
        'type' => 'model', 'id' => 'claude-sonnet-5-5', 'display_name' => 'Claude Sonnet 5.5',
    ])]);

    $this->actingAs($this->admin)
        ->postJson(route('impostazioni.ia.test'), ['modello' => 'claude-sonnet-5-5'])
        ->assertOk()
        ->assertJson(['success' => true, 'modello' => 'claude-sonnet-5-5'])
        ->assertJsonPath('message', 'Connessione riuscita: modello Claude Sonnet 5.5 disponibile.');

    Http::assertSent(fn (Request $r) => $r->method() === 'GET'
        && $r->hasHeader('x-api-key', CHIAVE_PAGINA_IA)
        && $r->hasHeader('anthropic-version', '2023-06-01'));
    expect(UtilizzoIA::count())->toBe(0);
});

it('nella prova di connessione spiega in italiano chiave e modello sbagliati', function (int $stato, string $messaggio) {
    Http::fake(['https://api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'x', 'message' => 'x']], $stato)]);

    $this->actingAs($this->admin)
        ->postJson(route('impostazioni.ia.test'), ['api_key' => 'sk-ant-api03-chiave-scritta-nel-modulo-00000', 'modello' => 'claude-inesistente-1'])
        ->assertStatus(422)
        ->assertJson(['success' => false])
        ->assertJsonPath('message', fn (string $m) => str_contains($m, $messaggio));
})->with([
    'chiave non valida' => [401, 'La chiave API non è valida'],
    'modello sconosciuto' => [404, 'Modello non trovato'],
]);

it('senza nessuna chiave la prova non chiama nessuno', function () {
    Http::fake();

    $this->actingAs($this->admin)
        ->postJson(route('impostazioni.ia.test'), [])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Nessuna chiave API: scrivila nel campo e riprova.');

    Http::assertNothingSent();
});

it('mostra la spesa del mese e le ultime richieste, senza testi', function () {
    UtilizzoIA::create([
        'tipo_documento' => 'verbale', 'modello' => 'claude-sonnet-5-5', 'token_ingresso' => 1000,
        'token_uscita' => 500, 'costo_microdollari' => 7000, 'esito' => 'ok',
    ]);

    $this->actingAs($this->admin)
        ->get(route('impostazioni.ia'))
        ->assertInertia(fn (AssertableInertia $pagina) => $pagina
            ->where('spesa.mese_usd', 0.007)
            ->where('spesa.chiamate', 1)
            ->has('utilizzi', 1)
            ->where('utilizzi.0.tipo_documento', 'verbale')
            ->where('utilizzi.0.costo_usd', 0.007)
        );
});
