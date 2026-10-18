<?php

use App\Actions\Cassa\CreateCassaAccountAction;
use App\Actions\Cassa\RegistraAperturaCassaAction;
use App\Actions\Gestionale\Movimenti\RegistraGirocontoAction;
use App\Actions\Gestionale\Movimenti\StoreIncassoRateAction;
use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Contracts\IA\GeneratoreTesto;
use App\Contracts\IA\RisultatoGenerazione;
use App\Enums\MetodoPagamento;
use App\Enums\TipoAllocazioneFattura;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\RendicontoEsercizio;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Models\User;
use App\Services\CondominioService;
use App\Services\Gestionale\FatturaPassivaService;
use App\Services\Gestionale\PagamentoFornitoreService;
use App\Services\Gestionale\Rendiconto\NotaRendicontoService;
use App\Services\Gestionale\Rendiconto\RendicontoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Rendiconto 1130-bis di un esercizio completo 01/06/2026–31/05/2027, costruito con le stesse
 * azioni che usa il programma: apertura della banca, preventivo, piani rate, emissione, incassi,
 * fatture (una ad personam), pagamenti con commissione, giroconto al fondo lavori, e una
 * gestione straordinaria separata.
 *
 * Numeri dello scenario (centesimi):
 * - tre unità A/B/C con 500/300/200 millesimi; Rossi Mario (A), Bianchi Luigi (B),
 *   Verdi Anna (C) proprietari, Rossi Paola inquilina di C;
 * - ordinaria: Pulizia scale 1.200,00 a preventivo (50% proprietario, 50% inquilino) e
 *   Assicurazione 600,00 (100% proprietario), due rate; spese effettive 1.220,00 e 500,00,
 *   più 100,00 ad personam sull'unità B e 1,50 di commissione bancaria senza voce;
 * - straordinaria «Rifacimento tetto»: preventivo 3.000,00 in una rata, spesa 2.000,00;
 * - Bianchi paga solo la prima rata ordinaria e niente della straordinaria; Verdi ha un saldo
 *   iniziale di 50,00 a debito.
 */
function rendTestAdmin(): User
{
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $user = User::factory()->create();
    $user->assignRole($ruolo);

    return $user;
}

function rendTestAnagrafica(Condominio $condominio, string $nome, string $cf): Anagrafica
{
    $a = Anagrafica::factory()->create([
        'nome' => $nome,
        'codice_fiscale' => $cf,
        'email' => Str::slug($nome).'@test.it',
        'email_secondaria' => null,
        'pec' => null,
    ]);
    $condominio->anagrafiche()->attach($a->id);

    return $a;
}

function rendTestVoce(PianoConto $piano, int $contoContabileId, int $tabellaId, string $nome, int $importo, array $ripartizioni): Conto
{
    $conto = Conto::create([
        'piano_conto_id' => $piano->id, 'conto_contabile_id' => $contoContabileId,
        'nome' => $nome, 'tipo' => 'spesa', 'importo' => $importo,
    ]);
    $pivot = DB::table('conto_tabella_millesimale')->insertGetId([
        'conto_id' => $conto->id, 'tabella_id' => $tabellaId, 'coefficiente' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ($ripartizioni as $soggetto => $percentuale) {
        DB::table('conto_tabella_ripartizioni')->insert([
            'conto_tabella_millesimale_id' => $pivot, 'soggetto' => $soggetto, 'percentuale' => $percentuale,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $conto;
}

/** Incassa per intero le quote indicate, a nome del loro intestatario. */
function rendTestIncassa($quote, Anagrafica $pagante, Cassa $cassa, Gestione $gestione, Condominio $condominio, Esercizio $esercizio, string $data): int
{
    $totale = (int) $quote->sum('importo');
    app(StoreIncassoRateAction::class)->execute([
        'pagante_id' => $pagante->id, 'cassa_id' => $cassa->id, 'gestione_id' => $gestione->id,
        'data_pagamento' => $data, 'descrizione' => 'Versamento rate', 'importo_totale' => $totale / 100, 'eccedenza' => 0,
        'dettaglio_pagamenti' => $quote->map(fn ($q) => ['rata_id' => $q->id, 'importo' => $q->importo / 100])->values()->all(),
    ], $condominio, $esercizio);

    return $totale;
}

function rendTestFattura(array $ctx, Gestione $gestione, string $numero, string $data, array $righe): FatturaPassiva
{
    return (new FatturaPassivaService())->registraFattura([
        'fornitore_id' => $ctx['fornitore_id'], 'esercizio_id' => $ctx['esercizio']->id, 'gestione_id' => $gestione->id,
        'tipo_documento' => 'fattura', 'numero_documento' => $numero, 'data_documento' => $data,
        'data_scadenza' => $data, 'modalita_pagamento' => 'bonifico',
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => null],
        'righe' => $righe,
    ], $ctx['condominio']->id);
}

function rendTestPaga(array $ctx, FatturaPassiva $fattura, string $data, int $commissioni = 0): void
{
    app(PagamentoFornitoreService::class)->registraPagamento([
        'fornitore_id' => $ctx['fornitore_id'], 'condominio_id' => $ctx['condominio']->id,
        'esercizio_id' => $ctx['esercizio']->id, 'conto_corrente_id' => $ctx['banca']->conto_contabile_id,
        'data_pagamento' => $data, 'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
        'iban_beneficiario' => null, 'importo_commissioni_cents' => $commissioni, 'bonifico_parlante' => false,
        'allow_overdraft' => true, 'iban_confermato_manualmente' => true, 'conferma_duplicato_verificato' => true,
        'allocazioni' => [[
            'fattura_id' => $fattura->id, 'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
            'importo_allocato_cents' => $fattura->netto_a_pagare,
        ]],
    ]);
}

function rendTestEmetti($test, User $user, Condominio $condominio, PianoRate $piano): void
{
    $test->actingAs($user)
        ->post(route('admin.gestionale.piani-rate.emetti', ['condominio' => $condominio->id, 'pianoRate' => $piano->id]), [
            'rate_ids' => $piano->rate()->pluck('id')->all(), 'data_emissione' => '2026-07-01', 'invia_notifiche' => false,
        ])
        ->assertSessionHas('message.type', 'success');
}

/** Lo scenario completo. Restituisce gli oggetti che servono alle asserzioni. */
function rendTestScenario($test): array
{
    Event::fake([
        \App\Events\Gestionale\FatturaRegistrata::class,
        \App\Events\Gestionale\PagamentoRegistrato::class,
    ]);
    Carbon::setTestNow('2026-07-01 10:00:00');

    $user = rendTestAdmin();
    $test->actingAs($user);

    $condominio = app(CondominioService::class)->createCondominioWithEsercizio([
        'nome' => 'Condominio Rendiconto Test', 'uuid' => (string) Str::uuid(),
        'indirizzo' => 'Via Roma 1', 'citta' => 'Milano', 'cap' => '20100', 'provincia' => 'MI',
    ]);
    $esercizio = $condominio->esercizi()->firstOrFail();
    $esercizio->update(['nome' => '2026/2027', 'data_inizio' => '2026-06-01', 'data_fine' => '2027-05-31']);
    $esercizio->refresh();

    $ordinaria = $condominio->gestioni()->where('tipo', 'ordinaria')->firstOrFail();
    $ordinaria->update(['data_inizio' => '2026-06-01', 'data_fine' => '2027-05-31']);
    $straordinaria = Gestione::create([
        'condominio_id' => $condominio->id, 'nome' => 'Rifacimento tetto', 'tipo' => 'straordinaria',
        'attiva' => true, 'data_inizio' => '2026-06-01', 'data_fine' => '2027-05-31',
    ]);
    $straordinaria->esercizi()->attach($esercizio->id, ['attiva' => true]);

    $costi = ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'costi_servizi')->firstOrFail();

    // Casse: banca con saldo di apertura a giornale, fondo speciale lavori.
    $contoBanca = app(CreateCassaAccountAction::class)->execute($condominio, ['tipo' => 'banca', 'nome' => 'Banca Test']);
    $banca = Cassa::create([
        'condominio_id' => $condominio->id, 'nome' => 'Banca Test', 'tipo' => 'banca',
        'conto_contabile_id' => $contoBanca->id, 'saldo_iniziale' => 100000, 'attiva' => true,
    ]);
    app(RegistraAperturaCassaAction::class)->execute($banca);
    $contoFondo = app(CreateCassaAccountAction::class)->execute($condominio, ['tipo' => 'fondo', 'nome' => 'Fondo lavori']);
    $fondo = Cassa::create([
        'condominio_id' => $condominio->id, 'nome' => 'Fondo lavori', 'tipo' => 'fondo', 'sottotipo_fondo' => 'vincolato_lavori',
        'vincolo_descrizione' => 'Rifacimento tetto', 'conto_contabile_id' => $contoFondo->id, 'saldo_iniziale' => 0, 'attiva' => true,
    ]);

    // Unità, tabella, persone.
    $tabella = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'Proprietà generale', 'quota' => 'millesimi']);
    $unita = [];
    foreach (['A' => 500, 'B' => 300, 'C' => 200] as $nome => $millesimi) {
        $unita[$nome] = Immobile::create(['condominio_id' => $condominio->id, 'nome' => "Appartamento {$nome}", 'interno' => $nome, 'descrizione' => '']);
        DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $unita[$nome]->id, 'valore' => $millesimi, 'created_at' => now(), 'updated_at' => now()]);
    }
    $rossi = rendTestAnagrafica($condominio, 'Rossi Mario', 'TSTTST80A01F205A');
    $bianchi = rendTestAnagrafica($condominio, 'Bianchi Luigi', 'TSTTST80A01F205B');
    $verdi = rendTestAnagrafica($condominio, 'Verdi Anna', 'TSTTST80A01F205C');
    $paola = rendTestAnagrafica($condominio, 'Rossi Paola', 'TSTTST80A01F205D');
    foreach ([[$rossi, 'A', 'proprietario'], [$bianchi, 'B', 'proprietario'], [$verdi, 'C', 'proprietario'], [$paola, 'C', 'inquilino']] as [$a, $u, $ruolo]) {
        DB::table('anagrafica_immobile')->insert([
            'anagrafica_id' => $a->id, 'immobile_id' => $unita[$u]->id, 'tipologia' => $ruolo,
            'quota' => 100, 'attivo' => true, 'data_inizio' => '2026-01-01',
        ]);
    }

    // Preventivi.
    $pcOrd = PianoConto::create(['condominio_id' => $condominio->id, 'gestione_id' => $ordinaria->id, 'nome' => 'Ordinaria 2026/2027']);
    $pulizia = rendTestVoce($pcOrd, $costi->id, $tabella->id, 'Pulizia scale', 120000, ['proprietario' => 50, 'inquilino' => 50]);
    $assicurazione = rendTestVoce($pcOrd, $costi->id, $tabella->id, 'Assicurazione', 60000, ['proprietario' => 100]);
    $pcStr = PianoConto::create(['condominio_id' => $condominio->id, 'gestione_id' => $straordinaria->id, 'nome' => 'Tetto']);
    $tetto = rendTestVoce($pcStr, $costi->id, $tabella->id, 'Lavori tetto', 300000, ['proprietario' => 100]);

    // Piani rate, approvazione, emissione.
    $pianoOrd = PianoRate::create(['gestione_id' => $ordinaria->id, 'condominio_id' => $condominio->id, 'nome' => 'Rate ordinarie', 'stato' => 'bozza', 'tipo' => 'ordinario', 'numero_rate' => 2]);
    app(GeneratePianoRateAction::class)->execute($pianoOrd);
    $pianoOrd->update(['stato' => 'approvato']);
    $pianoStr = PianoRate::create(['gestione_id' => $straordinaria->id, 'condominio_id' => $condominio->id, 'nome' => 'Rata tetto', 'stato' => 'bozza', 'tipo' => 'ordinario', 'numero_rate' => 1]);
    app(GeneratePianoRateAction::class)->execute($pianoStr);
    $pianoStr->update(['stato' => 'approvato']);

    rendTestEmetti($test, $user, $condominio, $pianoOrd);
    rendTestEmetti($test, $user, $condominio, $pianoStr->fresh());

    // Saldo iniziale di Verdi (non assorbito dai piani).
    Saldo::create([
        'esercizio_id' => $esercizio->id, 'condominio_id' => $condominio->id, 'anagrafica_id' => $verdi->id,
        'immobile_id' => $unita['C']->id, 'gestione_id' => $ordinaria->id, 'saldo_iniziale' => 5000, 'origine' => 'manuale',
    ]);

    // Incassi.
    $quoteDi = fn (PianoRate $p, Anagrafica $a) => RataQuote::whereHas('rata', fn ($q) => $q->where('piano_rate_id', $p->id))
        ->where('anagrafica_id', $a->id)->with('rata')->get()->sortBy(fn ($q) => $q->rata->numero_rata)->values();

    $versatoOrd = [];
    foreach ([$rossi, $verdi, $paola] as $a) {
        $versatoOrd[$a->id] = rendTestIncassa($quoteDi($pianoOrd, $a), $a, $banca, $ordinaria, $condominio, $esercizio, '2026-07-10');
    }
    $primaBianchi = $quoteDi($pianoOrd, $bianchi)->take(1);
    $versatoOrd[$bianchi->id] = rendTestIncassa($primaBianchi, $bianchi, $banca, $ordinaria, $condominio, $esercizio, '2026-07-12');

    $versatoStr = [];
    foreach ([$rossi, $verdi] as $a) {
        $versatoStr[$a->id] = rendTestIncassa($quoteDi($pianoStr, $a), $a, $banca, $straordinaria, $condominio, $esercizio, '2026-07-15');
    }

    // Fatture e pagamenti.
    $fornitoreId = DB::table('fornitori')->insertGetId([
        'ragione_sociale' => 'Servizi Test Srl', 'soggetto_ritenuta' => false, 'perc_imponibile_ritenuta' => 100,
        'perc_ritenuta' => 4, 'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $ctx = ['fornitore_id' => $fornitoreId, 'condominio' => $condominio, 'esercizio' => $esercizio, 'banca' => $banca];

    $ftPulizia = rendTestFattura($ctx, $ordinaria, 'FT-R1', '2026-09-15', [[
        'descrizione' => 'Pulizie', 'importo_imponibile' => 1000, 'aliquota_iva' => 22, 'conto_id' => $pulizia->id, 'is_sopravvenienza' => false,
    ]]);
    $ftAssic = rendTestFattura($ctx, $ordinaria, 'FT-R2', '2026-09-20', [[
        'descrizione' => 'Polizza', 'importo_imponibile' => 500, 'aliquota_iva' => 0, 'conto_id' => $assicurazione->id, 'is_sopravvenienza' => false,
    ]]);
    rendTestFattura($ctx, $ordinaria, 'FT-R3', '2026-10-05', [[
        'descrizione' => 'Riparazione balcone', 'importo_imponibile' => 100, 'aliquota_iva' => 0, 'immobile_id' => $unita['B']->id, 'is_sopravvenienza' => false,
    ]]);
    $ftTetto = rendTestFattura($ctx, $straordinaria, 'FT-R4', '2026-11-10', [[
        'descrizione' => 'Lavori tetto', 'importo_imponibile' => 2000, 'aliquota_iva' => 0, 'conto_id' => $tetto->id, 'is_sopravvenienza' => false,
    ]]);

    rendTestPaga($ctx, $ftPulizia, '2026-10-01', 150);
    rendTestPaga($ctx, $ftAssic, '2026-10-02');
    rendTestPaga($ctx, $ftTetto, '2026-12-01');

    // Accantonamento al fondo lavori.
    app(RegistraGirocontoAction::class)->execute([
        'importo' => 100, 'cassa_origine_id' => $banca->id, 'cassa_destinazione_id' => $fondo->id,
        'gestione_id' => $straordinaria->id, 'data_operazione' => '2026-12-15', 'causale' => 'Accantonamento fondo tetto',
    ], $condominio, $esercizio);

    return compact('user', 'condominio', 'esercizio', 'ordinaria', 'straordinaria', 'banca', 'fondo', 'unita',
        'rossi', 'bianchi', 'verdi', 'paola', 'versatoOrd', 'versatoStr', 'pulizia', 'assicurazione', 'tetto');
}

/** La riga del riparto di un'anagrafica. */
function rendTestRiga(array $riparto, int $anagraficaId): array
{
    foreach ($riparto['righe'] as $riga) {
        if ($riga['anagrafica_id'] === $anagraficaId) return $riga;
    }
    throw new RuntimeException("Anagrafica {$anagraficaId} assente dal riparto");
}

afterEach(fn () => Carbon::setTestNow());

test('scenario completo: registro, riepilogo, consuntivo, riparto e conguagli tornano al centesimo', function () {
    $s = rendTestScenario($this);
    $r = app(RendicontoService::class)->calcola($s['condominio'], $s['esercizio']);

    // ─── Registro e cassa ─────────────────────────────────────────────────────────────
    // Entrate: incassi 1.530,00 ordinaria + 2.100,00 straordinaria + 100,00 giroconto nel fondo.
    // Uscite: 1.221,50 + 500,00 + 2.000,00 pagamenti, 100,00 giroconto dalla banca.
    $incassiOrd = array_sum($s['versatoOrd']);
    $incassiStr = array_sum($s['versatoStr']);
    expect($incassiStr)->toBe(210000);

    $t = $r['registro']['totali'];
    expect($t['saldo_iniziale'])->toBe(100000)           // l'apertura non è un'entrata
        ->and($t['entrate'])->toBe($incassiOrd + $incassiStr + 10000)
        ->and($t['uscite'])->toBe(122150 + 50000 + 200000 + 10000)
        ->and($t['saldo_finale'])->toBe($t['saldo_iniziale'] + $t['entrate'] - $t['uscite']);

    $conti = collect($r['riepilogo']['conti'])->keyBy('conto_id');
    $banca = $conti[$s['banca']->conto_contabile_id];
    $fondo = $conti[$s['fondo']->conto_contabile_id];
    expect($banca['saldo_finale'])->toBe(100000 + $incassiOrd + $incassiStr - 372150 - 10000)
        ->and($banca['saldo_finale'])->toBe($banca['saldo_contabile'])
        ->and($fondo['saldo_finale'])->toBe(10000)
        ->and($fondo['saldo_contabile'])->toBe(10000)
        ->and($r['riepilogo']['fondo_speciale'])->toBe(10000);

    // Il registro è cronologico e non contiene l'apertura.
    $date = array_column($r['registro']['righe'], 'data');
    $ordinate = $date;
    sort($ordinate);
    expect($date)->toBe($ordinate)
        ->and(collect($r['registro']['righe'])->pluck('tipo')->contains('apertura'))->toBeFalse();

    // Categorie: versamenti per gestione separati, giroconti a saldo zero.
    $cat = collect($r['riepilogo']['categorie'])->keyBy('chiave');
    expect($cat["versamenti:{$s['ordinaria']->id}"]['netto'])->toBe($incassiOrd)
        ->and($cat["versamenti:{$s['straordinaria']->id}"]['netto'])->toBe($incassiStr)
        ->and($cat['giroconti']['netto'])->toBe(0);

    // ─── Consuntivo e preventivo ──────────────────────────────────────────────────────
    [$ord, $str] = $r['consuntivo']['gestioni'];
    expect($ord['id'])->toBe($s['ordinaria']->id)
        ->and($ord['totali']['preventivo'])->toBe(180000)
        ->and($ord['totali']['voci'])->toBe(172000)
        ->and($ord['totali']['ad_personam'])->toBe(10000)
        ->and($ord['totali']['senza_voce'])->toBe(150)
        ->and($ord['totali']['consuntivo'])->toBe(182150)
        ->and($ord['totali']['scostamento'])->toBe(2150);
    $voci = collect($ord['voci'])->keyBy('id');
    expect($voci[$s['pulizia']->id]['preventivo'])->toBe(120000)
        ->and($voci[$s['pulizia']->id]['consuntivo'])->toBe(122000)
        ->and($voci[$s['pulizia']->id]['scostamento'])->toBe(2000)
        ->and($voci[$s['assicurazione']->id]['scostamento'])->toBe(-10000);
    // La straordinaria resta fuori dall'ordinaria.
    expect($str['id'])->toBe($s['straordinaria']->id)
        ->and($str['totali']['preventivo'])->toBe(300000)
        ->and($str['totali']['consuntivo'])->toBe(200000)
        ->and($voci->has($s['tetto']->id))->toBeFalse();

    // ─── Riparto consuntivo e conguagli: ordinaria ────────────────────────────────────
    [$ripOrd, $ripStr] = $r['riparto'];
    // Pulizia 1.220,00 su 500/300/200, metà a proprietario e metà a inquilino (sull'unità A e B,
    // senza inquilino, la metà dell'inquilino ricade sul proprietario); Assicurazione 500,00
    // solo proprietari; 100,00 ad personam a Bianchi.
    $quoteAttese = [
        $s['rossi']->id   => 61000 + 25000,
        $s['bianchi']->id => 36600 + 15000 + 10000,
        $s['verdi']->id   => 12200 + 10000,
        $s['paola']->id   => 12200,
    ];
    foreach ($quoteAttese as $aid => $quota) {
        $riga = rendTestRiga($ripOrd, $aid);
        $saldo = $aid === $s['verdi']->id ? 5000 : 0;
        expect($riga['quota'])->toBe($quota)
            ->and($riga['versato'])->toBe($s['versatoOrd'][$aid])
            ->and($riga['saldo_iniziale'])->toBe($saldo)
            ->and($riga['conguaglio'])->toBe($saldo + $quota - $s['versatoOrd'][$aid]);
    }
    // Il riparto somma esattamente alle spese ripartibili, i conguagli alla differenza.
    expect($ripOrd['totali']['spese'])->toBe(182000)
        ->and($ripOrd['totali']['ripartito'])->toBe(182000)
        ->and($ripOrd['totali']['non_ripartito'])->toBe(0)
        ->and($ripOrd['totali']['conguaglio'])->toBe(5000 + 182000 - $incassiOrd)
        ->and(rendTestRiga($ripOrd, $s['rossi']->id)['conguaglio'])->toBeLessThan(0)   // ha versato più del consuntivo
        ->and(rendTestRiga($ripOrd, $s['bianchi']->id)['conguaglio'])->toBeGreaterThan(0);
    // Colonna delle spese individuali e capitoli.
    $colonne = collect($ripOrd['colonne'])->keyBy('chiave');
    expect($colonne['diretto']['totale'])->toBe(10000)
        ->and($colonne['c'.$s['pulizia']->id]['totale'])->toBe(122000);

    // ─── Straordinaria ────────────────────────────────────────────────────────────────
    expect($ripStr['totali']['ripartito'])->toBe(200000)
        ->and(rendTestRiga($ripStr, $s['rossi']->id)['quota'])->toBe(100000)
        ->and(rendTestRiga($ripStr, $s['bianchi']->id)['conguaglio'])->toBe(60000)
        ->and(rendTestRiga($ripStr, $s['verdi']->id)['conguaglio'])->toBe(40000 - 60000)
        ->and($ripStr['totali']['conguaglio'])->toBe(200000 - 210000);

    // ─── Crediti, debiti, stato patrimoniale ──────────────────────────────────────────
    $crediti = collect($r['riepilogo']['crediti']['righe'])->keyBy('anagrafica_id');
    $rateBianchi = (int) RataQuote::where('anagrafica_id', $s['bianchi']->id)->sum('importo');
    expect($crediti[$s['bianchi']->id]['saldo'])->toBe($rateBianchi - $s['versatoOrd'][$s['bianchi']->id])
        ->and($r['riepilogo']['crediti']['anticipazioni_ad_personam'])->toBe(10000)
        ->and($r['riepilogo']['debiti']['totale_fatture'])->toBe(10000)
        ->and($r['riepilogo']['debiti']['mastro_fornitori'])->toBe(10000)
        ->and($r['riepilogo']['stato_patrimoniale']['quadra'])->toBeTrue();

    // ─── Verifiche ────────────────────────────────────────────────────────────────────
    $v = collect($r['verifiche'])->keyBy('codice');
    expect($v['giornale']['esito'])->toBe('ok')
        ->and($v['saldi_cassa']['esito'])->toBe('ok')
        ->and($v['estratto_conto']['esito'])->toBe('avviso')     // estratto conto non ancora indicato
        ->and($v["riparto_{$s['ordinaria']->id}"]['esito'])->toBe('ok')
        ->and($v["riparto_{$s['straordinaria']->id}"]['esito'])->toBe('ok')
        ->and($v["conguagli_{$s['ordinaria']->id}"]['esito'])->toBe('ok')
        ->and($v["conguagli_{$s['straordinaria']->id}"]['esito'])->toBe('ok')
        ->and($v["senza_voce_{$s['ordinaria']->id}"]['esito'])->toBe('avviso')
        ->and($v['versamenti']['esito'])->toBe('ok')
        ->and($v['stato_patrimoniale']['esito'])->toBe('ok')
        ->and($r['esito_verifiche'])->toBe('avviso');
});

test('la pagina, il PDF, l\'Excel, gli estratti conto e la nota funzionano', function () {
    $s = rendTestScenario($this);
    $param = ['condominio' => $s['condominio']->id, 'esercizio' => $s['esercizio']->id];

    $this->actingAs($s['user'])
        ->get(route('admin.gestionale.esercizi.rendiconto.show', $param))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('gestionale/rendiconto/RendicontoShow')
            ->where('rendiconto.esercizio.id', $s['esercizio']->id)
            ->where('iaDisponibile', false)
            ->where('nota.salvata', false)
            ->has('rendiconto.riparto', 2));

    // Estratto conto della banca e nota con segnaposto.
    $r = app(RendicontoService::class)->calcola($s['condominio'], $s['esercizio']);
    $saldoBanca = collect($r['riepilogo']['conti'])->firstWhere('conto_id', $s['banca']->conto_contabile_id)['saldo_finale'];

    $this->actingAs($s['user'])
        ->put(route('admin.gestionale.esercizi.rendiconto.aggiorna', $param), [
            'saldi_estratto_conto' => [
                $s['banca']->conto_contabile_id => number_format($saldoBanca / 100, 2, ',', '.'),
                $s['fondo']->conto_contabile_id => '100,00',
                999999 => '1,00',   // conto di un altro condominio o inesistente: ignorato
            ],
            'nota_sintetica' => "Saldo finale {{F:cassa.finale}}. Ordinaria: {{F:g1.consuntivo}}.",
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $salvati = RendicontoEsercizio::where('esercizio_id', $s['esercizio']->id)->firstOrFail();
    expect($salvati->estrattiConto())->toBe([
        $s['banca']->conto_contabile_id => $saldoBanca,
        $s['fondo']->conto_contabile_id => 10000,
    ])->and($salvati->fonte_nota)->toBe('manuale');

    $r = app(RendicontoService::class)->calcola($s['condominio'], $s['esercizio']);
    $nota = app(RendicontoService::class)->nota($r, $salvati);
    expect(collect($r['verifiche'])->firstWhere('codice', 'estratto_conto')['esito'])->toBe('ok')
        ->and($nota['compilata'])->toBe('Saldo finale € '.number_format($r['riepilogo']['totali']['saldo_finale'] / 100, 2, ',', '.').'. Ordinaria: € 1.821,50.')
        ->and($nota['mancanti'])->toBe([]);

    // Un estratto conto diverso è un errore.
    $this->actingAs($s['user'])->put(route('admin.gestionale.esercizi.rendiconto.aggiorna', $param), [
        'saldi_estratto_conto' => [$s['banca']->conto_contabile_id => '1,00'],
    ]);
    $r = app(RendicontoService::class)->calcola($s['condominio'], $s['esercizio']);
    expect(collect($r['verifiche'])->firstWhere('codice', 'estratto_conto')['esito'])->toBe('errore');

    // Bozza senza IA: il modello predefinito, con i segnaposto risolti.
    $this->actingAs($s['user'])
        ->postJson(route('admin.gestionale.esercizi.rendiconto.bozza-nota', $param))
        ->assertOk()
        ->assertJsonPath('fonte', 'modello')
        ->assertJsonPath('mancanti', []);

    // PDF completo, solo riparto (orizzontale) ed Excel.
    $pdf = $this->actingAs($s['user'])->get(route('admin.gestionale.esercizi.rendiconto.pdf', $param));
    $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr($pdf->getContent(), 0, 4))->toBe('%PDF');

    $this->actingAs($s['user'])
        ->get(route('admin.gestionale.esercizi.rendiconto.pdf', $param + ['sezione' => 'riparto']))
        ->assertOk();

    $xlsx = $this->actingAs($s['user'])->get(route('admin.gestionale.esercizi.rendiconto.xlsx', $param));
    $xlsx->assertOk()->assertDownload('rendiconto_2026-2027.xlsx');
});

test('il rendiconto di un altro condominio non si apre', function () {
    $s = rendTestScenario($this);
    $altro = Condominio::factory()->create();

    $this->actingAs($s['user'])
        ->get(route('admin.gestionale.esercizi.rendiconto.show', ['condominio' => $altro->id, 'esercizio' => $s['esercizio']->id]))
        ->assertNotFound();
});

test('con l\'IA disponibile la bozza passa contesto e schema, e non manda cifre fuori dai fatti', function () {
    $s = rendTestScenario($this);

    $finta = new class implements GeneratoreTesto {
        public array $chiamate = [];

        public function disponibile(): bool { return true; }

        public function genera(string $tipoDocumento, array $contesto, array $schema, ?string $istruzioni = null): RisultatoGenerazione
        {
            $this->chiamate[] = compact('tipoDocumento', 'contesto', 'schema', 'istruzioni');

            return new RisultatoGenerazione(ok: true, dati: [
                'sezioni' => [['titolo' => 'Sintesi del risultato', 'testo' => 'Le spese ordinarie sono state {{F:g1.consuntivo}}.']],
                'note_per_revisore' => ['Completare i rapporti in corso.'],
            ], avvisi: [], modello: 'modello-finto');
        }
    };
    app()->instance(GeneratoreTesto::class, $finta);

    $param = ['condominio' => $s['condominio']->id, 'esercizio' => $s['esercizio']->id];
    $this->actingAs($s['user'])
        ->postJson(route('admin.gestionale.esercizi.rendiconto.bozza-nota', $param), ['istruzioni' => 'Tono sobrio'])
        ->assertOk()
        ->assertJsonPath('fonte', 'ia')
        ->assertJsonPath('modello_ia', 'modello-finto')
        ->assertJsonPath('note_per_revisore.0', 'Completare i rapporti in corso.')
        ->assertJsonPath('compilata', "SINTESI DEL RISULTATO\nLe spese ordinarie sono state € 1.821,50.");

    expect($finta->chiamate)->toHaveCount(1);
    $chiamata = $finta->chiamate[0];
    expect($chiamata['tipoDocumento'])->toBe(NotaRendicontoService::TIPO_DOCUMENTO_IA)
        ->and($chiamata['istruzioni'])->toBe('Tono sobrio')
        ->and($chiamata['contesto']['condominio_id'])->toBe($s['condominio']->id)
        ->and($chiamata['contesto']['fatti'])->toHaveKey('g1.consuntivo')
        ->and($chiamata['schema']['required'])->toContain('note_per_revisore');

    // Le cifre stanno solo nei fatti (e negli articoli di legge del modello).
    $senzaFatti = $chiamata['contesto'];
    unset($senzaFatti['fatti'], $senzaFatti['condominio_id'], $senzaFatti['modello'], $senzaFatti['norme_ammesse']);
    $testo = json_encode($senzaFatti, JSON_UNESCAPED_UNICODE);
    expect(preg_replace('/"codice":"g\d+"/', '', $testo))->not->toMatch('/\d/');
});
