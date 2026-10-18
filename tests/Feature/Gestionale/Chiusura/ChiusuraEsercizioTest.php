<?php

/**
 * Passaggio d'esercizio guidato, dall'inizio alla fine.
 *
 * Esercizio 2026/27 (01/06–31/05) con due gestioni:
 *
 *  - ordinaria, due rate emesse:
 *      Rossi (int. 1)   300 + 300, paga 300 + 100        → residuo 200 (debito)
 *      Bianchi (int. 2) 200 + 200, paga 500               → residuo −100 (credito su quota strapagata)
 *      Verdi (int. 3)   100 + 100, non paga, e ha 50 di saldo iniziale mai assorbito → residuo 250
 *  - straordinaria «Tetto», rata 1 emessa (Rossi 500, paga 200 → residuo 300), rata 2 non emessa.
 *
 * Chiusura con le scelte predefinite (ordinaria «riporta», Tetto prosegue e «mantiene»), poi il
 * nuovo piano 2027/28 assorbe i saldi riportati in una Rata 0, viene emesso e incassato.
 *
 * Si verifica che nulla conti due volte: estratto conto del nuovo esercizio, saldi, situazione
 * debitoria (quote) e stato patrimoniale cumulativo. Poi l'annullamento e i protocolli.
 */

use App\Actions\Gestionale\Chiusura\ChiudiEsercizioAction;
use App\Actions\Gestionale\Movimenti\StoreIncassoRateAction;
use App\Exceptions\Gestionale\ChiusuraEsercizioException;
use App\Http\Controllers\Gestionale\PianiRate\EstrattoContoAnagraficaController;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ChiusuraEsercizio;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\User;
use App\Services\CondominioService;
use App\Services\Gestionale\StatoPatrimonialeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

function chEsAdmin(): User
{
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $user = User::factory()->create();
    $user->assignRole($ruolo);
    $user->givePermissionTo($permesso);

    return $user;
}

function chEsPersona(Condominio $condominio, string $nome, string $cf, string $interno): array
{
    $anagrafica = Anagrafica::create([
        'condominio_id' => $condominio->id, 'nome' => $nome,
        'email' => Str::slug($nome).'.chiusura@test.it', 'indirizzo' => 'Via Verdi 1', 'cap' => '00100',
        'citta' => 'Roma', 'provincia' => 'RM', 'codice_fiscale' => $cf,
    ]);
    $condominio->anagrafiche()->attach($anagrafica->id);

    $immobile = Immobile::create([
        'condominio_id' => $condominio->id, 'nome' => "Appartamento {$interno}", 'descrizione' => 'Appartamento',
        'interno' => $interno, 'foglio' => '1', 'particella' => '1', 'subalterno' => $interno,
    ]);
    $immobile->anagrafiche()->attach($anagrafica->id, [
        'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2020-01-01',
    ]);

    return [$anagrafica, $immobile];
}

/** Un piano con le sue rate e quote: [numero => [scadenza, [[anagrafica, immobile, importo, regole?]...]]]. */
function chEsPiano(Condominio $condominio, Gestione $gestione, string $nome, array $rate): array
{
    $piano = PianoRate::create([
        'condominio_id' => $condominio->id, 'gestione_id' => $gestione->id,
        'nome' => $nome, 'numero_rate' => count($rate), 'stato' => 'approvato', 'attivo' => true,
    ]);

    $create = [];
    foreach ($rate as $numero => [$scadenza, $quote]) {
        $rata = Rata::create([
            'piano_rate_id' => $piano->id, 'numero_rata' => $numero, 'descrizione' => "{$nome} rata {$numero}",
            'data_scadenza' => $scadenza, 'importo_totale' => array_sum(array_column($quote, 2)), 'stato' => 'bozza',
        ]);
        foreach ($quote as $q) {
            $create[$numero][$q[0]->id] = RataQuote::create([
                'rata_id' => $rata->id, 'anagrafica_id' => $q[0]->id, 'immobile_id' => $q[1]->id,
                'importo' => $q[2], 'importo_pagato' => 0, 'stato' => 'da_pagare', 'data_scadenza' => $scadenza,
                'tipo' => $q[4] ?? 'ordinaria', 'regole_calcolo' => $q[3] ?? null,
            ]);
        }
        $create['rate'][$numero] = $rata;
    }

    return [$piano, $create];
}

function chEsEmetti(object $s, PianoRate $piano, array $rate, string $data): void
{
    test()->actingAs($s->user)
        ->post(route('admin.gestionale.piani-rate.emetti', ['condominio' => $s->condominio->id, 'pianoRate' => $piano->id]), [
            'rate_ids' => collect($rate)->pluck('id')->all(), 'data_emissione' => $data, 'invia_notifiche' => false,
        ])
        ->assertSessionHas('message.type', 'success');
}

/** @param array<int, array{0: RataQuote, 1: int}> $pagamenti  [quota, centesimi] */
function chEsIncassa(object $s, Anagrafica $pagante, Esercizio $esercizio, string $data, array $pagamenti, int $eccedenza = 0): void
{
    test()->actingAs($s->user);
    $totale = array_sum(array_column($pagamenti, 1)) + $eccedenza;

    app(StoreIncassoRateAction::class)->execute([
        'pagante_id' => $pagante->id, 'cassa_id' => $s->cassa->id, 'gestione_id' => null,
        'data_pagamento' => $data, 'descrizione' => 'Incasso '.$pagante->nome,
        'importo_totale' => $totale / 100, 'eccedenza' => $eccedenza / 100,
        'dettaglio_pagamenti' => collect($pagamenti)->map(fn ($p) => ['rata_id' => $p[0]->id, 'importo' => $p[1] / 100])->all(),
    ], $s->condominio, $esercizio);
}

function chEsScenario(): object
{
    $user = chEsAdmin();

    $condominio = app(CondominioService::class)->createCondominioWithEsercizio([
        'nome' => 'Condominio Chiusura', 'uuid' => (string) Str::uuid(),
        'indirizzo' => 'Via Roma 1', 'citta' => 'Milano', 'cap' => '20100', 'provincia' => 'MI',
    ]);

    $vecchio = $condominio->esercizi()->firstOrFail();
    $vecchio->update(['nome' => 'Esercizio 2026/2027', 'descrizione' => 'Dal 01/06/2026 al 31/05/2027',
        'data_inizio' => '2026-06-01', 'data_fine' => '2027-05-31']);

    $ordinaria = $vecchio->gestioni()->where('tipo', 'ordinaria')->firstOrFail();
    $ordinaria->update(['nome' => 'Gestione ordinaria 2026/27']);
    PianoConto::create(['gestione_id' => $ordinaria->id, 'condominio_id' => $condominio->id, 'nome' => 'Piano dei conti 2026/27']);

    $tetto = Gestione::create([
        'condominio_id' => $condominio->id, 'nome' => 'Rifacimento tetto', 'tipo' => 'straordinaria',
        'attiva' => true, 'data_inizio' => '2026-06-01', 'data_fine' => '2028-05-31',
    ]);
    $tetto->esercizi()->attach($vecchio->id, ['attiva' => true]);

    [$rossi, $immRossi] = chEsPersona($condominio, 'Mario Rossi', 'TSTTST80A01H501A', '1');
    [$bianchi, $immBianchi] = chEsPersona($condominio, 'Anna Bianchi', 'TSTTST80A01H501B', '2');
    [$verdi, $immVerdi] = chEsPersona($condominio, 'Luca Verdi', 'TSTTST80A01H501C', '3');

    $contoBanca = ContoContabile::create([
        'condominio_id' => $condominio->id, 'ruolo' => 'conto_bancario', 'codice' => '1010',
        'nome' => 'Banca', 'tipo' => 'attivo', 'categoria' => 'liquidita',
    ]);
    $cassa = Cassa::create([
        'condominio_id' => $condominio->id, 'nome' => 'Banca', 'tipo' => 'banca',
        'conto_contabile_id' => $contoBanca->id, 'saldo_iniziale' => 0, 'attiva' => true,
    ]);

    $s = (object) compact('user', 'condominio', 'vecchio', 'ordinaria', 'tetto', 'rossi', 'immRossi',
        'bianchi', 'immBianchi', 'verdi', 'immVerdi', 'cassa');
    $s->conti = ContoContabile::where('condominio_id', $condominio->id)->get()->keyBy('ruolo');

    // Verdi arriva con 50,00 di debito dall'anno prima, mai assorbito da un piano.
    $s->saldoVerdi = Saldo::create([
        'esercizio_id' => $vecchio->id, 'condominio_id' => $condominio->id, 'gestione_id' => $ordinaria->id,
        'anagrafica_id' => $verdi->id, 'immobile_id' => $immVerdi->id, 'saldo_iniziale' => 5000,
        'origine' => 'manuale', 'is_applicato' => false,
    ]);

    // Ordinaria: due rate.
    [$s->pianoOrd, $q] = chEsPiano($condominio, $ordinaria, 'Ordinario 2026/27', [
        1 => ['2026-09-01', [[$rossi, $immRossi, 30000], [$bianchi, $immBianchi, 20000], [$verdi, $immVerdi, 10000]]],
        2 => ['2027-01-15', [[$rossi, $immRossi, 30000], [$bianchi, $immBianchi, 20000], [$verdi, $immVerdi, 10000]]],
    ]);
    $s->qOrd = $q;
    chEsEmetti($s, $s->pianoOrd, $q['rate'], '2026-08-01');

    // Tetto: la rata 1 è emessa, la 2 no (scade nel prossimo esercizio).
    [$s->pianoTetto, $qt] = chEsPiano($condominio, $tetto, 'Tetto', [
        1 => ['2026-10-01', [[$rossi, $immRossi, 50000]]],
        2 => ['2027-10-01', [[$rossi, $immRossi, 50000]]],
    ]);
    $s->qTetto = $qt;
    chEsEmetti($s, $s->pianoTetto, [$qt['rate'][1]], '2026-09-01');

    // Incassi del 2026/27.
    chEsIncassa($s, $rossi, $vecchio, '2026-09-10', [[$q[1][$rossi->id], 30000], [$q[2][$rossi->id], 10000]]);
    // Bianchi versa 500 su 400 dovuti: i 100 in più restano sulla sua ultima quota (strapagata).
    chEsIncassa($s, $bianchi, $vecchio, '2026-09-12', [[$q[1][$bianchi->id], 20000], [$q[2][$bianchi->id], 20000]], 10000);
    chEsIncassa($s, $rossi, $vecchio, '2026-10-05', [[$qt[1][$rossi->id], 20000]]);

    return $s;
}

function chEsLedger(object $s, Esercizio $esercizio, Anagrafica $anagrafica): int
{
    $metodo = new ReflectionMethod(EstrattoContoAnagraficaController::class, 'buildLedger');
    $metodo->setAccessible(true);
    [, $stats] = $metodo->invoke(app(EstrattoContoAnagraficaController::class), $s->condominio, $esercizio->fresh(), $anagrafica);

    return (int) $stats['saldo_raw'];
}

function chEsConto(object $s, ?Esercizio $esercizio, string $ruolo): int
{
    $sp = app(StatoPatrimonialeService::class)->calcola($s->condominio->fresh(), $esercizio?->fresh());
    $id = $s->conti[$ruolo]->id;
    $voce = collect($sp['attivo']['voci'])->merge($sp['passivo']['voci'])->firstWhere('id', $id);

    return (int) ($voce['saldo'] ?? 0);
}

function chEsChiudi(object $s, array $extra = [])
{
    return test()->actingAs($s->user)->post(route('admin.gestionale.esercizi.chiusura.store', [$s->condominio, $s->vecchio]), $extra + [
        'nome' => 'Esercizio 2027/2028', 'descrizione' => 'Dal 01/06/2027 al 31/05/2028', 'data_fine' => '2028-05-31',
        'numerazione_per_esercizio' => true, 'copia_piano_conti' => true,
        'gestioni' => [
            $s->ordinaria->id => ['modalita' => 'riporta', 'riapri' => true],
            $s->tetto->id => ['modalita' => 'mantieni', 'riapri' => true],
        ],
        'conferma' => true,
    ]);
}

function chEsNuovo(object $s): Esercizio
{
    return Esercizio::where('condominio_id', $s->condominio->id)->where('nome', 'Esercizio 2027/2028')->firstOrFail();
}

test('la pagina mostra controlli, residui per condòmino e gestioni con le scelte predefinite', function () {
    $s = chEsScenario();

    $this->actingAs($s->user)->get(route('admin.gestionale.esercizi.chiusura.show', [$s->condominio, $s->vecchio]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('gestionale/esercizi/ChiusuraEsercizio')
            ->where('proposta.data_inizio', '2027-06-01')
            ->where('proposta.serie', '2027/28')
            ->where('proposta.nome', 'Esercizio 2027/2028')
            ->has('controlli')
            ->has('gestioni', 2)
            ->where('gestioni.0.modalita_predefinita', 'riporta')
            ->where('gestioni.1.modalita_predefinita', 'mantieni')
            ->where('gestioni.1.quote_non_emesse', 1)
            ->has('residui', 4)
            ->where('chiusura', null));
});

test('chiusura: residui riportati una volta sola, estratto conto, quote e stato patrimoniale tornano', function () {
    $s = chEsScenario();

    // La fotografia prima: estratto conto del vecchio esercizio e stato patrimoniale a fine esercizio.
    $ecRossiPrima = chEsLedger($s, $s->vecchio, $s->rossi);
    $crediti1101Prima = chEsConto($s, $s->vecchio, 'crediti_condomini');
    expect($ecRossiPrima)->toBe(50000)                 // 200 ordinaria + 300 tetto
        ->and(chEsLedger($s, $s->vecchio, $s->bianchi))->toBe(-10000)
        ->and(chEsLedger($s, $s->vecchio, $s->verdi))->toBe(25000)  // 200 + 50 di saldo iniziale
        ->and($crediti1101Prima)->toBe(60000);         // 200 + 300 − 100 + 200 (il saldo di Verdi non è a giornale)

    chEsChiudi($s)->assertSessionHasNoErrors()->assertSessionHas('message.type', 'success');

    $nuovo = chEsNuovo($s);
    $chiusura = ChiusuraEsercizio::where('esercizio_chiuso_id', $s->vecchio->id)->firstOrFail();

    // Esercizi: vecchio chiuso, nuovo contiguo, aperto, con la serie dei protocolli.
    expect($s->vecchio->fresh()->stato)->toBe('chiuso')
        ->and($nuovo->stato)->toBe('aperto')
        ->and($nuovo->data_inizio->toDateString())->toBe('2027-06-01')
        ->and($nuovo->data_fine->toDateString())->toBe('2028-05-31')
        ->and($nuovo->serie_protocollo)->toBe('2027/28');

    // Gestioni: nuova ordinaria (con il piano dei conti copiato), tetto riagganciato, vecchia ordinaria spenta.
    $nuovaOrd = $nuovo->gestioni()->where('tipo', 'ordinaria')->firstOrFail();
    expect($nuovaOrd->id)->not->toBe($s->ordinaria->id)
        ->and($nuovaOrd->nome)->toBe('Gestione ordinaria 2027/28')
        ->and(PianoConto::where('gestione_id', $nuovaOrd->id)->value('nome'))->toBe('Piano dei conti 2027/28')
        ->and($nuovo->gestioni()->where('gestioni.id', $s->tetto->id)->exists())->toBeTrue()
        ->and((bool) $s->ordinaria->fresh()->attiva)->toBeFalse();

    // Saldi iniziali del nuovo esercizio.
    $saldi = Saldo::where('esercizio_id', $nuovo->id)->get();
    $liberi = $saldi->where('is_applicato', false);
    $bloccati = $saldi->where('is_applicato', true);
    expect((int) $liberi->where('anagrafica_id', $s->rossi->id)->sum('saldo_iniziale'))->toBe(20000)
        ->and((int) $liberi->where('anagrafica_id', $s->verdi->id)->sum('saldo_iniziale'))->toBe(25000)
        ->and($liberi->pluck('gestione_id')->unique()->all())->toBe([$nuovaOrd->id])
        ->and((int) $bloccati->where('anagrafica_id', $s->rossi->id)->sum('saldo_iniziale'))->toBe(30000)
        ->and((int) $bloccati->where('anagrafica_id', $s->bianchi->id)->sum('saldo_iniziale'))->toBe(-10000)
        ->and($saldi->every(fn ($x) => $x->chiusura_esercizio_id === $chiusura->id))->toBeTrue();

    // Il vecchio saldo di Verdi è passato: non si può più assorbire nel vecchio wallet.
    expect($s->saldoVerdi->fresh()->is_applicato)->toBeTrue();

    // Le quote ordinarie riportate sono chiuse, con il riferimento; le altre restano come erano.
    $q = $s->qOrd;
    expect($q[2][$s->rossi->id]->fresh()->stato)->toBe('pagata')
        ->and($q[2][$s->rossi->id]->fresh()->riferimento_pagamento)->toContain('CHI-2027/28-00001')
        ->and($q[1][$s->verdi->id]->fresh()->stato)->toBe('pagata')
        ->and($q[2][$s->bianchi->id]->fresh()->importo_pagato)->toBe(30000)       // credito resta sulla quota
        ->and($s->qTetto[1][$s->rossi->id]->fresh()->stato)->toBe('parzialmente_pagata');

    // La scrittura di riporto: nel nuovo esercizio, quadrata, DARE 2301 / AVERE 1101 per 400,00.
    $riporto = ScritturaContabile::where('esercizio_id', $nuovo->id)->where('tipo_movimento', 'chiusura')->firstOrFail();
    expect($riporto->numero_protocollo)->toBe('CHI-2027/28-00001')
        ->and((int) $riporto->righe()->where('tipo_riga', 'dare')->sum('importo'))->toBe(40000)
        ->and((int) $riporto->righe()->where('tipo_riga', 'avere')->sum('importo'))->toBe(40000);

    // ── Niente conta due volte ───────────────────────────────────────────────────────────────
    // Il vecchio esercizio non è cambiato.
    expect(chEsLedger($s, $s->vecchio, $s->rossi))->toBe($ecRossiPrima)
        ->and(chEsConto($s, $s->vecchio, 'crediti_condomini'))->toBe($crediti1101Prima);

    // L'estratto conto del nuovo esercizio parte dal residuo, una volta sola.
    expect(chEsLedger($s, $nuovo, $s->rossi))->toBe(50000)
        ->and(chEsLedger($s, $nuovo, $s->bianchi))->toBe(-10000)
        ->and(chEsLedger($s, $nuovo, $s->verdi))->toBe(25000);

    // La situazione debitoria (dalle quote) non propone più le quote riportate: fra le rate emesse
    // Rossi ha aperta solo quella del tetto; il riportato tornerà con la Rata 0 del nuovo piano.
    $emesse = fn () => collect($this->actingAs($s->user)
        ->getJson(route('admin.gestionale.situazione-debitoria', ['condominio' => $s->condominio->id, 'anagrafica_id' => $s->rossi->id]))
        ->assertOk()->json('rate'))->where('is_emitted', true)->values();
    expect($emesse()->pluck('gestione_id')->all())->toBe([$s->tetto->id])
        ->and((float) $emesse()->sum('residuo'))->toBe(300.0);

    // ── Il nuovo piano assorbe i saldi riportati (Rata 0), si emette e si incassa ───────────
    $saldoRossi = $liberi->firstWhere('anagrafica_id', $s->rossi->id);
    $saldoVerdi = $liberi->firstWhere('anagrafica_id', $s->verdi->id);
    $rata0 = fn (int $c) => ['importi' => ['quota_pura_gestione' => 0, 'saldo_usato' => $c, 'totale_calcolato' => $c]];
    [$piano0, $q0] = chEsPiano($s->condominio, $nuovaOrd, 'Ordinario 2027/28', [
        0 => ['2027-07-01', [
            [$s->rossi, $s->immRossi, 20000, $rata0(20000), 'saldo_iniziale'],
            [$s->verdi, $s->immVerdi, 25000, $rata0(25000), 'saldo_iniziale'],
        ]],
    ]);
    Saldo::whereIn('id', [$saldoRossi->id, $saldoVerdi->id])->update(['is_applicato' => true, 'piano_rate_id' => $piano0->id]);

    chEsEmetti($s, $piano0, [$q0['rate'][0]], '2027-06-15');

    // Estratto conto: la Rata 0 porta il riporto, già nel saldo iniziale → nessun raddoppio.
    expect(chEsLedger($s, $nuovo, $s->rossi))->toBe(50000)
        ->and(chEsLedger($s, $nuovo, $s->verdi))->toBe(25000);

    // Stato patrimoniale del nuovo esercizio: 1101 = debiti veri (500 + 250 − 100), 2301 porta
    // solo i 50,00 di Verdi entrati a giornale per la prima volta (come per un saldo d'apertura).
    expect(chEsConto($s, $nuovo, 'crediti_condomini'))->toBe(65000)
        ->and(chEsConto($s, $nuovo, 'passate_gestioni'))->toBe(5000)
        ->and(app(StatoPatrimonialeService::class)->quadra($s->condominio->fresh(), $nuovo->fresh()))->toBeTrue();

    // Rossi paga tutto nel nuovo esercizio: Rata 0 e il residuo del tetto.
    chEsIncassa($s, $s->rossi, $nuovo, '2027-07-10', [[$q0[0][$s->rossi->id], 20000], [$s->qTetto[1][$s->rossi->id], 30000]]);

    expect(chEsLedger($s, $nuovo, $s->rossi))->toBe(0)
        ->and($s->qTetto[1][$s->rossi->id]->fresh()->stato)->toBe('pagata');

    // Resta solo la rata 2 del tetto, non ancora emessa.
    expect($emesse())->toHaveCount(0);

    // 1101 di Rossi, su tutto il giornale, è a zero: niente gonfiato dal riporto.
    $saldo1101Rossi = DB::table('righe_scritture as rs')
        ->join('scritture_contabili as sc', 'sc.id', '=', 'rs.scrittura_id')
        ->whereNull('sc.deleted_at')
        ->where('rs.conto_contabile_id', $s->conti['crediti_condomini']->id)
        ->where('rs.anagrafica_id', $s->rossi->id)
        ->selectRaw("SUM(CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE -rs.importo END) as s")
        ->value('s');
    expect((int) $saldo1101Rossi)->toBe(0);

    // Protocolli: le registrazioni del nuovo esercizio hanno la sua serie, le vecchie restano com'erano.
    expect(ScritturaContabile::where('esercizio_id', $nuovo->id)->where('tipo_movimento', 'incasso_rata')->value('numero_protocollo'))
        ->toBe('INC-2027/28-00001')
        ->and(ScritturaContabile::where('esercizio_id', $nuovo->id)->where('tipo_movimento', 'emissione_rata')->value('numero_protocollo'))
        ->toBe('EMI-2027/28-00001')
        ->and(ScritturaContabile::where('esercizio_id', $s->vecchio->id)->pluck('numero_protocollo')->every(fn ($p) => ! str_contains($p, '/')))
        ->toBeTrue()
        ->and(ScritturaContabile::previewNextProtocolNumber($s->condominio->id, 'RIM'))->toBe('RIM-2027/28-00001');

    // Fotografia dei residui per il rendiconto dell'esercizio chiuso.
    expect($chiusura->residui()->count())->toBe(4)
        ->and((int) $chiusura->residui()->sum('residuo'))->toBe(65000)
        ->and((int) $chiusura->residui()->sum('riportato'))->toBe(45000)
        ->and((int) $chiusura->residui()->sum('mantenuto'))->toBe(20000);
});

test("l'esercizio chiuso non cambia date né nome e non si riapre dal modulo", function () {
    $s = chEsScenario();
    chEsChiudi($s)->assertSessionHas('message.type', 'success');

    $base = ['nome' => 'Esercizio 2026/2027', 'descrizione' => 'altro', 'data_inizio' => '2026-06-01', 'data_fine' => '2027-05-31', 'stato' => 'chiuso'];

    $this->actingAs($s->user)->put(route('admin.gestionale.esercizi.update', [$s->condominio, $s->vecchio]), ['data_fine' => '2027-06-30'] + $base)
        ->assertSessionHasErrors('data_fine');
    $this->actingAs($s->user)->put(route('admin.gestionale.esercizi.update', [$s->condominio, $s->vecchio]), ['nome' => 'Altro nome'] + $base)
        ->assertSessionHasErrors('nome');
    $this->actingAs($s->user)->put(route('admin.gestionale.esercizi.update', [$s->condominio, $s->vecchio]), ['stato' => 'aperto'] + $base)
        ->assertSessionHasErrors('stato');

    // La descrizione resta modificabile.
    $this->actingAs($s->user)->put(route('admin.gestionale.esercizi.update', [$s->condominio, $s->vecchio]), $base)
        ->assertSessionHasNoErrors();
    expect($s->vecchio->fresh()->descrizione)->toBe('altro')
        ->and($s->vecchio->fresh()->data_fine->toDateString())->toBe('2027-05-31');
});

test('i saldi generati dalla chiusura non si toccano a mano', function () {
    $s = chEsScenario();
    chEsChiudi($s);
    $nuovo = chEsNuovo($s);

    $bloccato = Saldo::where('esercizio_id', $nuovo->id)->where('is_applicato', true)->firstOrFail();
    $libero = Saldo::where('esercizio_id', $nuovo->id)->where('is_applicato', false)->firstOrFail();

    $this->actingAs($s->user)->post(route('admin.gestionale.saldi.sblocca', [$s->condominio, $bloccato]))->assertForbidden();
    $this->actingAs($s->user)->post(route('admin.gestionale.saldi.sblocca', [$s->condominio, $s->saldoVerdi]))->assertForbidden();
    $this->actingAs($s->user)->delete(route('admin.gestionale.saldi.destroy', [$s->condominio, $libero]))->assertForbidden();
    $this->actingAs($s->user)->put(route('admin.gestionale.saldi.update', [$s->condominio, $libero]), [
        'saldo_iniziale' => 1, 'gestione_id' => $libero->gestione_id,
    ])->assertSessionHasErrors('saldo');

    expect(Saldo::whereKey($libero->id)->value('saldo_iniziale'))->toBe($libero->saldo_iniziale);
});

test("annullamento: tutto torna com'era, e la chiusura si può rifare", function () {
    $s = chEsScenario();
    $crediti1101Prima = chEsConto($s, $s->vecchio, 'crediti_condomini');
    $qRossi = $s->qOrd[2][$s->rossi->id];

    chEsChiudi($s);
    $nuovo = chEsNuovo($s);
    $nuovaOrdId = $nuovo->gestioni()->where('tipo', 'ordinaria')->value('gestioni.id');

    // Pagina del riepilogo: annullabile.
    $this->actingAs($s->user)->get(route('admin.gestionale.esercizi.chiusura.show', [$s->condominio, $s->vecchio]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('chiusura.annullabile', true)->where('chiusura.esercizio_nuovo.nome', 'Esercizio 2027/2028'));

    $this->actingAs($s->user)->post(route('admin.gestionale.esercizi.chiusura.annulla', [$s->condominio, $s->vecchio]))
        ->assertSessionHas('message.type', 'success');

    expect($s->vecchio->fresh()->stato)->toBe('aperto')
        ->and(Esercizio::find($nuovo->id))->toBeNull()
        ->and(Gestione::find($nuovaOrdId))->toBeNull()
        ->and((bool) $s->ordinaria->fresh()->attiva)->toBeTrue()
        ->and($s->tetto->fresh()->esercizi()->count())->toBe(1)
        ->and($qRossi->fresh()->stato)->toBe('parzialmente_pagata')
        ->and($qRossi->fresh()->importo_pagato)->toBe(10000)
        ->and($qRossi->fresh()->riferimento_pagamento)->toBeNull()
        ->and((bool) $s->saldoVerdi->fresh()->is_applicato)->toBeFalse()
        ->and($s->saldoVerdi->fresh()->chiusura_esercizio_id)->toBeNull()
        ->and(ScritturaContabile::withTrashed()->where('tipo_movimento', 'chiusura')->count())->toBe(0)
        ->and(chEsConto($s, $s->vecchio, 'crediti_condomini'))->toBe($crediti1101Prima)
        ->and(ChiusuraEsercizio::first()->stato)->toBe('annullata');

    // Si chiude di nuovo, con gli stessi numeri (anche il protocollo riparte da 1).
    chEsChiudi($s)->assertSessionHas('message.type', 'success');
    expect(ScritturaContabile::where('tipo_movimento', 'chiusura')->value('numero_protocollo'))->toBe('CHI-2027/28-00001')
        ->and(chEsLedger($s, chEsNuovo($s), $s->rossi))->toBe(50000);
});

test("l'annullamento è rifiutato quando nel nuovo esercizio ci sono movimenti", function () {
    $s = chEsScenario();
    chEsChiudi($s);
    $nuovo = chEsNuovo($s);

    // Verdi paga la sua quota del tetto? Non ne ha: paga un anticipo sull'ordinaria del nuovo esercizio.
    test()->actingAs($s->user);
    app(StoreIncassoRateAction::class)->execute([
        'pagante_id' => $s->verdi->id, 'cassa_id' => $s->cassa->id, 'gestione_id' => null,
        'data_pagamento' => '2027-06-20', 'descrizione' => 'Anticipo',
        'importo_totale' => 10.00, 'eccedenza' => 10.00, 'dettaglio_pagamenti' => [],
    ], $s->condominio, $nuovo);

    $this->actingAs($s->user)->get(route('admin.gestionale.esercizi.chiusura.show', [$s->condominio, $s->vecchio]))
        ->assertInertia(fn (Assert $page) => $page->where('chiusura.annullabile', false));

    $this->actingAs($s->user)->post(route('admin.gestionale.esercizi.chiusura.annulla', [$s->condominio, $s->vecchio]))
        ->assertSessionHas('message.type', 'error');

    expect($s->vecchio->fresh()->stato)->toBe('chiuso')
        ->and(Esercizio::find($nuovo->id))->not->toBeNull();
});

test('la chiusura è bloccata da scritture in bozza e da un esercizio successivo già esistente', function () {
    $s = chEsScenario();

    ScritturaContabile::create([
        'condominio_id' => $s->condominio->id, 'esercizio_id' => $s->vecchio->id, 'gestione_id' => $s->ordinaria->id,
        'data_registrazione' => '2027-05-01', 'data_competenza' => '2027-05-01',
        'causale' => 'Bozza', 'tipo_movimento' => 'rettifica', 'stato' => 'bozza',
    ]);

    chEsChiudi($s)->assertSessionHas('message.type', 'error');
    expect($s->vecchio->fresh()->stato)->toBe('aperto')
        ->and(Esercizio::where('condominio_id', $s->condominio->id)->count())->toBe(1);

    ScritturaContabile::where('stato', 'bozza')->forceDelete();
    Esercizio::create([
        'condominio_id' => $s->condominio->id, 'nome' => 'Successivo a mano', 'descrizione' => 'x',
        'data_inizio' => '2027-06-01', 'data_fine' => '2028-05-31', 'stato' => 'chiuso',
    ]);

    chEsChiudi($s)->assertSessionHas('message.type', 'error');
    expect($s->vecchio->fresh()->stato)->toBe('aperto');
});

test('una straordinaria con rate non emesse non si può «riportare», e una che non prosegue nemmeno', function () {
    $s = chEsScenario();
    $azione = app(ChiudiEsercizioAction::class);

    expect(fn () => $azione->execute($s->vecchio, ['gestioni' => [$s->tetto->id => ['modalita' => 'riporta', 'riapri' => true]]]))
        ->toThrow(ChiusuraEsercizioException::class, 'quote non ancora emesse');

    expect(fn () => $azione->execute($s->vecchio, ['gestioni' => [$s->tetto->id => ['modalita' => 'riporta', 'riapri' => false]]]))
        ->toThrow(ChiusuraEsercizioException::class, 'non prosegue');

    // Nessuna traccia dei tentativi.
    expect($s->vecchio->fresh()->stato)->toBe('aperto')
        ->and(ChiusuraEsercizio::count())->toBe(0);
});

test("«mantieni» sull'ordinaria: le vecchie quote restano da incassare e l'estratto conto torna lo stesso", function () {
    $s = chEsScenario();
    chEsChiudi($s, ['gestioni' => [
        $s->ordinaria->id => ['modalita' => 'mantieni', 'riapri' => true],
        $s->tetto->id => ['modalita' => 'mantieni', 'riapri' => true],
    ]])->assertSessionHas('message.type', 'success');
    $nuovo = chEsNuovo($s);

    expect($s->qOrd[2][$s->rossi->id]->fresh()->stato)->toBe('parzialmente_pagata')
        ->and(ScritturaContabile::where('tipo_movimento', 'chiusura')->count())->toBe(0)
        ->and(chEsLedger($s, $nuovo, $s->rossi))->toBe(50000)
        ->and(chEsLedger($s, $nuovo, $s->verdi))->toBe(25000)
        // Il saldo di Verdi mai assorbito passa comunque alla nuova ordinaria (libero), le quote no.
        ->and((int) Saldo::where('esercizio_id', $nuovo->id)->where('anagrafica_id', $s->verdi->id)->where('is_applicato', false)->sum('saldo_iniziale'))->toBe(5000);

    // Rossi paga la vecchia rata nel nuovo esercizio: l'estratto conto la abbatte una volta.
    chEsIncassa($s, $s->rossi, $nuovo, '2027-07-01', [[$s->qOrd[2][$s->rossi->id], 20000]]);
    expect(chEsLedger($s, $nuovo, $s->rossi))->toBe(30000)
        ->and(chEsConto($s, $nuovo, 'crediti_condomini'))->toBe(40000); // 600 − 200
});

test("fattura tardiva: una data dell'esercizio chiuso è segnalata e guidata verso il debito pregresso", function () {
    $s = chEsScenario();
    $url = fn (string $data) => route('admin.gestionale.esercizi.competenza', ['condominio' => $s->condominio->id, 'data' => $data]);

    $this->actingAs($s->user)->getJson($url('2027-05-20'))->assertOk()->assertJsonPath('tardiva', false);

    chEsChiudi($s);

    $this->actingAs($s->user)->getJson($url('2027-05-20'))
        ->assertOk()
        ->assertJsonPath('tardiva', true)
        ->assertJsonPath('esercizio_competenza.nome', 'Esercizio 2026/2027')
        ->assertJsonPath('esercizio_aperto.nome', 'Esercizio 2027/2028');

    $this->actingAs($s->user)->getJson($url('2027-07-01'))->assertOk()->assertJsonPath('tardiva', false);
});

test("un esercizio di un altro condominio risponde 404", function () {
    $s = chEsScenario();
    $altro = Condominio::create([
        'nome' => 'Altro', 'uuid' => (string) Str::uuid(),
        'indirizzo' => 'Via Milano 2', 'citta' => 'Milano', 'cap' => '20100', 'provincia' => 'MI',
    ]);

    $this->actingAs($s->user)->get(route('admin.gestionale.esercizi.chiusura.show', [$altro, $s->vecchio]))->assertNotFound();
    $this->actingAs($s->user)->post(route('admin.gestionale.esercizi.chiusura.annulla', [$altro, $s->vecchio]))->assertNotFound();
});
