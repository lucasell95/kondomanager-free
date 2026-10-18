<?php

/**
 * Promemoria «F24 Ritenuta» dell'Inbox allineato allo scadenzario F24 (collaudo 1, E1).
 *
 * Il listener metteva ogni ritenuta al 16 del mese successivo al pagamento, ignorando le soglie
 * di legge: due ritenute 4% da 6,00 € pagate a luglio diventavano due promemoria al 17/08,
 * mentre lo scadenziario F24 proponeva una sola delega da 12,00 € entro il 16/12. Inoltre la
 * descrizione scriveva «Cod. » vuoto quando il codice tributo dell'anagrafica non era compilato.
 */

use App\Actions\Gestionale\Movimenti\GeneraDelegheF24Action;
use App\Enums\Fiscale\StatoDelegaF24;
use App\Events\Gestionale\PagamentoRegistrato;
use App\Events\Gestionale\PagamentoStornato;
use App\Listeners\Gestionale\SyncF24WithPagamento;
use App\Models\CategoriaEvento;
use App\Models\Evento;
use App\Models\Gestionale\DelegaF24;
use App\Models\Gestionale\PagamentoFornitore;
use App\Models\User;
use App\Services\Gestionale\FatturaPassivaService;
use App\Services\Gestionale\PagamentoFornitoreService;
use App\Services\Gestionale\PromemoriaRitenuteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class)->group('inbox', 'f24');

beforeEach(function () {
    CategoriaEvento::firstOrCreate(
        ['name' => 'Scadenze amministrative'],
        ['description' => 'Test', 'color' => '#000000', 'icon' => 'test']
    );

    if (! User::find(1)) {
        User::factory()->create(['id' => 1, 'email' => 'verdi.sistema@test.it']);
    }

    // Tutte le date dei pagamenti sono nel passato.
    Carbon::setTestNow(Carbon::parse('2026-12-20 10:00:00', 'UTC'));
});

afterEach(fn () => Carbon::setTestNow());

/** Il contesto con il fornitore classificato nel regime indicato. */
function promTestContesto(string $tipo = 'appalto_4', ?string $natura = 'persona_fisica_irpef', ?string $codiceTributo = null): array
{
    $ctx = setupPagamentiService();
    $ctx[3]->update([
        'ragione_sociale' => 'Rossi Impianti',
        'soggetto_ritenuta' => true,
        'perc_ritenuta' => $tipo === 'appalto_4' ? 4 : 20,
        'perc_imponibile_ritenuta' => 100,
        'tipo_ritenuta' => $tipo,
        'natura_percipiente' => $natura,
        'codice_tributo' => $codiceTributo,
    ]);

    return $ctx;
}

/** Fattura e pagamento alla data indicata, con la ritenuta fissata a `$ritenuta` centesimi. */
function promTestPagamento(array $ctx, string $data, int $ritenuta): PagamentoFornitore
{
    [$condominio, $esercizio, $gestione, $fornitore, , $capitolo] = $ctx;

    $fattura = (new FatturaPassivaService)->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore->fresh()], [
            'data_documento' => $data,
            'data_scadenza' => $data,
            'applica_ritenuta' => true,
            'righe' => [[
                'descrizione' => 'Manutenzione impianto',
                'importo_imponibile' => 100,
                'aliquota_iva' => 0,
                'conto_id' => $capitolo->id,
                'is_sopravvenienza' => false,
            ]],
        ]),
        $condominio->id
    );

    $pagamento = (new PagamentoFornitoreService)->registraPagamento(
        datiPagamento($ctx, $fattura->fresh(), ['data_pagamento' => $data])
    );

    // Importo deterministico per ragionare sulle soglie.
    DB::table('pagamenti_fornitori')->where('id', $pagamento->id)->update(['importo_ritenuta' => $ritenuta]);

    $pagamento = $pagamento->fresh();
    (new SyncF24WithPagamento)->handle(new PagamentoRegistrato($pagamento));

    return $pagamento;
}

function promTestEvento(PagamentoFornitore $pagamento): ?Evento
{
    return Evento::where('meta->context->pagamento_id', $pagamento->id)
        ->where('meta->type', 'versamento_ritenuta')
        ->first();
}

function promTestScadenza(PagamentoFornitore $pagamento): string
{
    return Carbon::parse(promTestEvento($pagamento)->start_time)->format('Y-m-d');
}

test('appalto 4% sotto i 500 € pagato a luglio: promemoria al 16/12, come lo scadenzario', function () {
    $ctx = promTestContesto();

    $p1 = promTestPagamento($ctx, '2026-07-22', 600);
    $p2 = promTestPagamento($ctx, '2026-07-28', 600);

    expect(promTestScadenza($p1))->toBe('2026-12-16')
        ->and(promTestScadenza($p2))->toBe('2026-12-16');

    $evento = promTestEvento($p1);
    expect($evento->description)->toContain('cod. tributo 1019')
        ->and($evento->description)->toContain('un solo F24 da 12,00 €')
        ->and($evento->description)->not->toContain('Cod. )')
        ->and($evento->meta['codice_tributo'])->toBe('1019')
        ->and($evento->meta['scadenza_f24'])->toBe('2026-12-16')
        ->and((int) $evento->meta['importo'])->toBe(600)
        ->and((bool) $evento->is_completed)->toBeFalse();

    // Stessa data della delega che produce «Aggiorna scadenze».
    $deleghe = app(GeneraDelegheF24Action::class)->esegui($ctx[0], $ctx[1]->id);
    expect($deleghe)->toHaveCount(1)
        ->and($deleghe->first()->data_scadenza->toDateString())->toBe('2026-12-16')
        ->and((int) $deleghe->first()->totale_debito)->toBe(1200);
});

test('quando il cumulo supera i 500 € anche il promemoria del pagamento precedente si anticipa', function () {
    $ctx = promTestContesto();

    $luglio = promTestPagamento($ctx, '2026-07-10', 30000);
    expect(promTestScadenza($luglio))->toBe('2026-12-16');

    // 300 + 250 = 550 € ad agosto: si versa entro il 16/09.
    $agosto = promTestPagamento($ctx, '2026-08-05', 25000);

    expect(promTestScadenza($agosto))->toBe('2026-09-16')
        ->and(promTestScadenza($luglio))->toBe('2026-09-16');

    // Lo storno del pagamento di agosto riporta luglio sotto soglia, quindi al 16/12.
    DB::table('pagamenti_fornitori')->where('id', $agosto->id)->update(['stato' => 'stornato']);
    (new SyncF24WithPagamento)->handleStornato(new PagamentoStornato($agosto->fresh(), 'Errore'));

    expect((bool) promTestEvento($agosto)->is_completed)->toBeTrue()
        ->and(promTestScadenza($luglio))->toBe('2026-12-16');
});

test('le ritenute di dicembre vanno al 16 gennaio (primo giorno lavorativo)', function () {
    $ctx = promTestContesto();

    $p = promTestPagamento($ctx, '2026-12-10', 1000);

    // 16/01/2027 è sabato: si va al lunedì 18.
    expect(promTestScadenza($p))->toBe('2027-01-18');
});

test('lavoro autonomo 20%: soglia annuale di 100 € e codice 1040', function () {
    $ctx = promTestContesto('lavoro_autonomo_20', 'persona_fisica_irpef');

    $marzo = promTestPagamento($ctx, '2026-03-10', 5000);
    expect(promTestScadenza($marzo))->toBe('2026-12-16');

    // 50 + 60 = 110 € ad aprile: 16/05/2026 è sabato → lunedì 18/05.
    $aprile = promTestPagamento($ctx, '2026-04-15', 6000);
    expect(promTestScadenza($aprile))->toBe('2026-05-18')
        ->and(promTestScadenza($marzo))->toBe('2026-05-18')
        ->and(promTestEvento($marzo)->description)->toContain('cod. tributo 1040');
});

test('senza codice tributo in anagrafica il promemoria usa quello del regime (1020 per una società)', function () {
    $ctx = promTestContesto('appalto_4', 'soggetto_ires', null);

    $p = promTestPagamento($ctx, '2026-07-22', 600);
    $descrizione = promTestEvento($p)->description;

    expect($descrizione)->toContain('cod. tributo 1020')
        ->and($descrizione)->not->toMatch('/[Cc]od\. (tributo )?\)/');
});

test('dopo il versamento della delega il promemoria si chiude, e un promemoria chiuso a mano non si riapre da solo', function () {
    $ctx = promTestContesto();

    $p1 = promTestPagamento($ctx, '2026-07-22', 600);
    $p2 = promTestPagamento($ctx, '2026-07-28', 600);

    // Chiuso a mano dall'amministratore: un nuovo pagamento non deve riaprirlo.
    promTestEvento($p1)->update(['is_completed' => true]);
    $p3 = promTestPagamento($ctx, '2026-09-01', 600);
    expect((bool) promTestEvento($p1)->is_completed)->toBeTrue()
        ->and((bool) promTestEvento($p3)->is_completed)->toBeFalse();

    // Versamento: le ritenute della delega escono dall'Inbox.
    $delega = app(GeneraDelegheF24Action::class)->esegui($ctx[0], $ctx[1]->id)->first();
    $delega->update(['stato' => StatoDelegaF24::VERSATA]);
    app(PromemoriaRitenuteService::class)->sincronizza($ctx[0]);

    foreach ([$p1, $p2, $p3] as $p) {
        expect((bool) promTestEvento($p)->is_completed)->toBeTrue();
    }

    // Storno del versamento: le ritenute tornano da versare e i promemoria si riaprono.
    $delega->update(['stato' => StatoDelegaF24::STORNATA]);
    app(PromemoriaRitenuteService::class)->sincronizza($ctx[0], [$p1->id, $p2->id, $p3->id]);

    foreach ([$p1, $p2, $p3] as $p) {
        expect((bool) promTestEvento($p)->is_completed)->toBeFalse()
            ->and(promTestScadenza($p))->toBe('2026-12-16');
    }
});

test('una delega confermata impone la sua scadenza al promemoria', function () {
    $ctx = promTestContesto();
    $p = promTestPagamento($ctx, '2026-07-22', 600);

    $delega = app(GeneraDelegheF24Action::class)->esegui($ctx[0], $ctx[1]->id)->first();
    $delega->update(['stato' => StatoDelegaF24::CONFERMATA, 'data_scadenza' => '2026-11-16']);

    app(PromemoriaRitenuteService::class)->sincronizza($ctx[0]);

    expect(promTestScadenza($p))->toBe('2026-11-16')
        ->and(promTestEvento($p)->meta['context']['delega_f24_id'])->toBe($delega->id);
});

test('il ricalcolo dello scadenzario via HTTP aggiorna anche i promemoria', function () {
    $ctx = promTestContesto();
    $p = promTestPagamento($ctx, '2026-07-22', 600);

    // Promemoria disallineato (com'era prima della correzione).
    promTestEvento($p)->update(['start_time' => '2026-08-17 09:00:00']);

    $permesso = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $admin = User::factory()->create(['email' => 'rossi.f24@test.it']);
    $admin->assignRole($ruolo);

    $this->actingAs($admin)
        ->post(route('admin.gestionale.f24.genera', ['condominio' => $ctx[0]->id]))
        ->assertSessionHasNoErrors();

    expect(DelegaF24::where('condominio_id', $ctx[0]->id)->count())->toBe(1)
        ->and(promTestScadenza($p))->toBe('2026-12-16');
});
