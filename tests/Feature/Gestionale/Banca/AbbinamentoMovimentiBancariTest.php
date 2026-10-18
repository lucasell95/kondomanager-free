<?php

/**
 * Banca → Movimenti da abbinare: proposte e conferme.
 *
 * Il punto che conta di più: ogni conferma passa dai flussi contabili esistenti. Un incasso
 * abbinato dalla banca produce **la stessa scrittura** della maschera incassi; un pagamento
 * passa da `PagamentoFornitoreService`; le commissioni dalla regolazione immediata.
 */

use App\Models\Gestionale\Banca\MovimentoBancario;
use App\Models\Gestionale\Banca\RegolaBancaria;
use App\Models\Gestionale\Banca\RiconoscimentoControparte;
use App\Models\Gestionale\PagamentoFornitore;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\Banca\ProposteAbbinamentoService;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/BancaTestHelpers.php';

it('l\'incasso abbinato dalla banca crea la stessa scrittura della maschera incassi', function () {
    $user = adminBanca();
    $this->actingAs($user);

    // Maschera incassi, in un condominio.
    $a = scenarioBanca();
    $this->post(route('admin.gestionale.movimenti-rate.store', $a['condominio']), [
        'pagante_id' => $a['rossi']->id,
        'cassa_id' => $a['cassa']->id,
        'gestione_id' => null,
        'data_pagamento' => '2026-02-05',
        'importo_totale' => 150.00,
        'descrizione' => 'Bonifico Rossi',
        'eccedenza' => 0,
        'dettaglio_pagamenti' => [
            ['rata_id' => $a['quote']['Rossi Mario'][1]->id, 'importo' => 100.00],
            ['rata_id' => $a['quote']['Rossi Mario'][2]->id, 'importo' => 50.00],
        ],
    ])->assertSessionHasNoErrors();

    $daMaschera = ScritturaContabile::where('condominio_id', $a['condominio']->id)->where('tipo_movimento', 'incasso_rata')->firstOrFail();

    // Banca, in un condominio identico, con lo stesso bonifico.
    $b = scenarioBanca();
    $movimento = movimentoBanca($b, ['importo' => 15000, 'data_contabile' => '2026-02-05', 'controparte' => 'ROSSI MARIO']);

    $this->post(route('admin.gestionale.banca.movimenti.incasso', [$b['condominio'], $movimento->id]), [
        'pagante_id' => $b['rossi']->id,
        'righe' => [
            ['quota_id' => $b['quote']['Rossi Mario'][1]->id, 'importo' => 10000],
            ['quota_id' => $b['quote']['Rossi Mario'][2]->id, 'importo' => 5000],
        ],
        'eccedenza' => 0,
        'descrizione' => 'Bonifico Rossi',
    ])->assertSessionHasNoErrors();

    $daBanca = ScritturaContabile::where('condominio_id', $b['condominio']->id)->where('tipo_movimento', 'incasso_rata')->firstOrFail();

    expect(righeNormalizzate($daBanca->id))->toBe(righeNormalizzate($daMaschera->id))
        ->and($daBanca->causale)->toBe($daMaschera->causale)
        ->and($daBanca->data_competenza->toDateString())->toBe('2026-02-05')
        ->and($daBanca->gestione_id)->toBe($b['gestione']->id)
        // Le quote pagate sono le stesse: la prima saldata, la seconda a metà.
        ->and($b['quote']['Rossi Mario'][1]->fresh()->stato)->toBe($a['quote']['Rossi Mario'][1]->fresh()->stato)
        ->and($b['quote']['Rossi Mario'][2]->fresh()->importo_pagato)->toBe(5000);

    assertQuadraturaPerfetta($daBanca->id);

    $movimento->refresh();
    expect($movimento->stato)->toBe(MovimentoBancario::STATO_ABBINATO)
        ->and($movimento->tipo_abbinamento)->toBe(MovimentoBancario::ABBINAMENTO_INCASSO)
        ->and($movimento->scrittura_contabile_id)->toBe($daBanca->id)
        ->and($daBanca->fresh()->stato)->toBe('riconciliata');
});

it('propone il condòmino per somiglianza del nome e le sue rate dalla più vecchia', function () {
    $s = scenarioBanca();
    $m = movimentoBanca($s, ['importo' => 15000, 'descrizione' => 'BONIFICO A VOSTRO FAVORE MITT.: BIANCHI ANNA INFO: RATE']);

    $p = app(ProposteAbbinamentoService::class)->proponi($m);

    expect($p['incasso']['anagrafica']['id'])->toBe($s['bianchi']->id)
        ->and($p['incasso']['quote'])->toHaveCount(2)
        ->and($p['incasso']['quote'][0]['quota_id'])->toBe($s['quote']['Bianchi Anna'][1]->id)
        ->and($p['incasso']['quote'][0]['da_pagare'])->toBe(10000)
        ->and($p['incasso']['quote'][1]['da_pagare'])->toBe(5000)
        ->and($p['incasso']['eccedenza'])->toBe(0)
        // Solo il nome, senza memoria: non abbastanza sicuro per la conferma multipla.
        ->and($p['migliore']['tipo'])->toBe('incasso')
        ->and($p['migliore']['automatica'])->toBeFalse();
});

it('ricorda IBAN e nome della controparte e li riconosce al movimento successivo', function () {
    $s = scenarioBanca();
    $this->actingAs(adminBanca());

    $primo = movimentoBanca($s, [
        'importo' => 10000, 'controparte' => 'MARIO R.', 'iban_controparte' => IBAN_ROSSI,
        'descrizione' => 'Bonifico da conto cointestato',
    ]);

    $this->post(route('admin.gestionale.banca.movimenti.incasso', [$s['condominio'], $primo->id]), [
        'pagante_id' => $s['rossi']->id,
        'righe' => [['quota_id' => $s['quote']['Rossi Mario'][1]->id, 'importo' => 10000]],
    ])->assertSessionHasNoErrors();

    expect(RiconoscimentoControparte::where('tipo', 'iban')->where('valore', IBAN_ROSSI)->value('anagrafica_id'))->toBe($s['rossi']->id)
        ->and(RiconoscimentoControparte::where('tipo', 'nome')->where('valore', 'mario r')->value('anagrafica_id'))->toBe($s['rossi']->id);

    // Il mese dopo: stesso IBAN, descrizione che non nomina nessuno.
    $secondo = movimentoBanca($s, [
        'data_contabile' => '2026-03-05', 'importo' => 10000, 'iban_controparte' => IBAN_ROSSI,
        'descrizione' => 'Bonifico SEPA', 'hash' => str_repeat('b', 64),
    ]);

    $p = app(ProposteAbbinamentoService::class)->proponi($secondo);

    expect($p['incasso']['anagrafica']['id'])->toBe($s['rossi']->id)
        ->and($p['incasso']['punteggio'])->toBe(100)
        ->and($p['incasso']['motivo'])->toContain('IBAN')
        ->and($p['incasso']['quote'][0]['quota_id'])->toBe($s['quote']['Rossi Mario'][2]->id)
        // IBAN riconosciuto, rata coperta per intero e già emessa: va bene per la conferma multipla.
        ->and($p['migliore']['automatica'])->toBeTrue();

    $this->post(route('admin.gestionale.banca.movimenti.multipla', $s['condominio']), ['ids' => [$secondo->id]])
        ->assertSessionHasNoErrors();

    expect($secondo->fresh()->stato)->toBe(MovimentoBancario::STATO_ABBINATO)
        ->and($s['quote']['Rossi Mario'][2]->fresh()->importo_pagato)->toBe(10000);
});

it('rifiuta un incasso le cui rate non fanno l\'importo della banca', function () {
    $s = scenarioBanca();
    $this->actingAs(adminBanca());
    $m = movimentoBanca($s, ['importo' => 12000]);

    $this->post(route('admin.gestionale.banca.movimenti.incasso', [$s['condominio'], $m->id]), [
        'pagante_id' => $s['rossi']->id,
        'righe' => [['quota_id' => $s['quote']['Rossi Mario'][1]->id, 'importo' => 10000]],
    ])->assertSessionHasErrors('abbinamento');

    expect($m->fresh()->stato)->toBe(MovimentoBancario::STATO_DA_ABBINARE)
        ->and(ScritturaContabile::where('tipo_movimento', 'incasso_rata')->count())->toBe(0);
});

it('l\'eccedenza resta credito del condòmino come nella maschera incassi', function () {
    $s = scenarioBanca();
    $this->actingAs(adminBanca());
    $m = movimentoBanca($s, ['importo' => 25000]);

    $this->post(route('admin.gestionale.banca.movimenti.incasso', [$s['condominio'], $m->id]), [
        'pagante_id' => $s['rossi']->id,
        'righe' => [
            ['quota_id' => $s['quote']['Rossi Mario'][1]->id, 'importo' => 10000],
            ['quota_id' => $s['quote']['Rossi Mario'][2]->id, 'importo' => 10000],
        ],
        'eccedenza' => 5000,
    ])->assertSessionHasNoErrors();

    $scrittura = ScritturaContabile::where('tipo_movimento', 'incasso_rata')->firstOrFail();
    assertQuadraturaPerfetta($scrittura->id);

    // 250 entrati, 200 sulle rate, 50 di strapagato sull'ultima quota.
    expect($s['quote']['Rossi Mario'][2]->fresh()->importo_pagato)->toBe(15000);
});

it('registra il pagamento di una fattura passiva con il flusso pagamenti', function () {
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class, \App\Events\Gestionale\PagamentoRegistrato::class]);

    $ctx = setupPagamentiService();
    [$condominio] = $ctx;
    $fattura = registraFatturaServiceTest($ctx);
    $cassa = \App\Models\Gestionale\Cassa::where('condominio_id', $condominio->id)->firstOrFail();
    $fattura->fornitore->update(['ragione_sociale' => 'Verdi Impianti Srl']);

    $this->actingAs(adminBanca());

    $m = \App\Models\Gestionale\Banca\MovimentoBancario::create([
        'condominio_id' => $condominio->id,
        'cassa_id' => $cassa->id,
        'data_contabile' => now()->toDateString(),
        'importo' => -$fattura->netto_a_pagare,
        'descrizione' => 'BONIFICO A FAVORE DI VERDI IMPIANTI SRL FATTURA '.$fattura->numero_documento,
        'controparte' => 'VERDI IMPIANTI SRL',
        'iban_controparte' => IBAN_BIANCHI,
        'hash' => str_repeat('c', 64),
    ]);

    $p = app(ProposteAbbinamentoService::class)->proponi($m);
    expect($p['pagamenti'][0]['fattura_id'])->toBe($fattura->id)
        ->and($p['pagamenti'][0]['importo_esatto'])->toBeTrue();

    $this->post(route('admin.gestionale.banca.movimenti.pagamento', [$condominio, $m->id]), [
        'allocazioni' => [['fattura_id' => $fattura->id, 'importo' => $fattura->netto_a_pagare]],
        'allow_overdraft' => true,
        'nota_override' => 'Test: il saldo di cassa non è il punto di questo test.',
    ])->assertSessionHasNoErrors();

    $pagamento = PagamentoFornitore::firstOrFail();
    $m->refresh();

    expect($m->stato)->toBe(MovimentoBancario::STATO_ABBINATO)
        ->and($m->scrittura_contabile_id)->toBe($pagamento->scrittura_contabile_id)
        // La chiave di idempotenza è quella del movimento: una seconda conferma non raddoppia.
        ->and($pagamento->idempotency_key)->toBe($m->idempotency_key)
        ->and($pagamento->iban_beneficiario)->toBe(IBAN_BIANCHI)
        ->and($fattura->fresh()->stato_pagamento->value)->toBe('pagata')
        ->and(RiconoscimentoControparte::where('valore', IBAN_BIANCHI)->value('fornitore_id'))->toBe($fattura->fornitore_id);
});

it('registra le commissioni con la regola sul capitolo scelto, anche in conferma multipla', function () {
    $s = scenarioBanca();
    $this->actingAs(adminBanca());

    RegolaBancaria::create([
        'condominio_id' => $s['condominio']->id,
        'nome' => 'Commissioni bancarie',
        'verso' => 'uscita',
        'testo_contiene' => 'commissioni',
        'azione' => 'regolazione',
        'conto_id' => $s['capitolo']->id,
        'gestione_id' => $s['gestione']->id,
        'causale' => 'Commissioni bancarie',
        'attiva' => true,
    ]);

    $commissione = movimentoBanca($s, ['importo' => -250, 'descrizione' => 'COMMISSIONI TENUTA CONTO', 'data_contabile' => '2026-02-28']);
    $ignoto = movimentoBanca($s, ['importo' => -999, 'descrizione' => 'ADDEBITO SCONOSCIUTO', 'hash' => str_repeat('d', 64)]);

    $this->post(route('admin.gestionale.banca.movimenti.multipla', $s['condominio']), ['ids' => [$commissione->id, $ignoto->id]])
        ->assertSessionHasNoErrors();

    $commissione->refresh();
    $scrittura = $commissione->scrittura;

    expect($commissione->stato)->toBe(MovimentoBancario::STATO_ABBINATO)
        ->and($commissione->tipo_abbinamento)->toBe(MovimentoBancario::ABBINAMENTO_REGOLAZIONE)
        ->and($scrittura->tipo_movimento->value)->toBe('regolazione_immediata')
        ->and($scrittura->causale)->toBe('Commissioni bancarie')
        ->and($scrittura->righe()->where('tipo_riga', 'dare')->value('voce_spesa_id'))->toBe($s['capitolo']->id)
        ->and($scrittura->righe()->where('tipo_riga', 'avere')->value('cassa_id'))->toBe($s['cassa']->id)
        // Senza proposta sicura il movimento resta da guardare.
        ->and($ignoto->fresh()->stato)->toBe(MovimentoBancario::STATO_DA_ABBINARE);

    assertQuadraturaPerfetta($scrittura->id);
});

it('registra una spesa sul capitolo scelto e ne fa una regola per la volta dopo', function () {
    $s = scenarioBanca();
    $this->actingAs(adminBanca());
    $m = movimentoBanca($s, ['importo' => -855, 'descrizione' => 'IMPOSTA DI BOLLO E/C']);

    $this->post(route('admin.gestionale.banca.movimenti.regolazione', [$s['condominio'], $m->id]), [
        'conto_id' => $s['capitolo']->id,
        'gestione_id' => $s['gestione']->id,
        'causale' => 'Imposta di bollo',
        'crea_regola' => true,
        'testo_regola' => 'imposta di bollo',
    ])->assertSessionHasNoErrors();

    expect($m->fresh()->stato)->toBe(MovimentoBancario::STATO_ABBINATO)
        ->and(RegolaBancaria::where('testo_contiene', 'imposta di bollo')->exists())->toBeTrue();

    $prossimo = movimentoBanca($s, ['importo' => -855, 'descrizione' => 'IMPOSTA DI BOLLO E/C', 'data_contabile' => '2026-03-31', 'hash' => str_repeat('e', 64)]);
    $p = app(ProposteAbbinamentoService::class)->proponi($prossimo);

    expect($p['migliore']['tipo'])->toBe('regola')
        ->and($p['migliore']['automatica'])->toBeTrue();
});

it('collega a una scrittura esistente, e annullando l\'abbinamento la scrittura resta', function () {
    $s = scenarioBanca();
    $this->actingAs(adminBanca());

    $scrittura = app(\App\Actions\Gestionale\Movimenti\StoreIncassoRateAction::class)->execute([
        'pagante_id' => $s['bianchi']->id, 'cassa_id' => $s['cassa']->id, 'gestione_id' => $s['gestione']->id,
        'data_pagamento' => '2026-02-03', 'importo_totale' => 100.00, 'descrizione' => 'Rata Bianchi', 'eccedenza' => 0,
        'dettaglio_pagamenti' => [['rata_id' => $s['quote']['Bianchi Anna'][1]->id, 'importo' => 100.00]],
    ], $s['condominio'], $s['esercizio']);

    $m = movimentoBanca($s, ['importo' => 10000, 'controparte' => 'BIANCHI A.', 'descrizione' => 'Bonifico']);

    $p = app(ProposteAbbinamentoService::class)->proponi($m);
    expect($p['gia_registrate'][0]['id'])->toBe($scrittura->id)
        ->and($p['migliore']['tipo'])->toBe('esistente');

    // Un importo diverso non si collega senza conferma esplicita.
    $altro = movimentoBanca($s, ['importo' => 9000, 'hash' => str_repeat('f', 64)]);
    $this->post(route('admin.gestionale.banca.movimenti.collega', [$s['condominio'], $altro->id]), ['scrittura_id' => $scrittura->id])
        ->assertSessionHasErrors(['abbinamento', 'codice']);

    $this->post(route('admin.gestionale.banca.movimenti.collega', [$s['condominio'], $m->id]), ['scrittura_id' => $scrittura->id])
        ->assertSessionHasNoErrors();

    expect($m->fresh()->stato)->toBe(MovimentoBancario::STATO_ABBINATO)
        ->and($scrittura->fresh()->stato)->toBe('riconciliata')
        // Dal collegamento si impara anche chi è la controparte.
        ->and(RiconoscimentoControparte::where('valore', 'bianchi a')->value('anagrafica_id'))->toBe($s['bianchi']->id);

    $this->post(route('admin.gestionale.banca.movimenti.scollega', [$s['condominio'], $m->id]))->assertSessionHasNoErrors();

    expect($m->fresh()->stato)->toBe(MovimentoBancario::STATO_DA_ABBINARE)
        ->and($m->fresh()->scrittura_contabile_id)->toBeNull()
        ->and($scrittura->fresh()->stato)->toBe('registrata')
        ->and(ScritturaContabile::whereKey($scrittura->id)->exists())->toBeTrue();
});

it('ignora un movimento con il motivo e lo rimette da abbinare', function () {
    $s = scenarioBanca();
    $this->actingAs(adminBanca());
    $m = movimentoBanca($s, ['importo' => -100]);

    $this->post(route('admin.gestionale.banca.movimenti.ignora', [$s['condominio'], $m->id]), ['nota' => 'Addebito stornato dalla banca il giorno dopo'])
        ->assertSessionHasNoErrors();

    expect($m->fresh()->stato)->toBe(MovimentoBancario::STATO_IGNORATO)
        ->and($m->fresh()->note)->toContain('stornato');

    // Un movimento ignorato non si abbina finché non lo si rimette in coda.
    $this->post(route('admin.gestionale.banca.movimenti.regolazione', [$s['condominio'], $m->id]), [
        'conto_id' => $s['capitolo']->id, 'gestione_id' => $s['gestione']->id,
    ])->assertSessionHasErrors('abbinamento');

    $this->post(route('admin.gestionale.banca.movimenti.scollega', [$s['condominio'], $m->id]));
    expect($m->fresh()->stato)->toBe(MovimentoBancario::STATO_DA_ABBINARE);
});

it('mostra l\'elenco con filtri e le proposte in JSON', function () {
    $s = scenarioBanca();
    $this->actingAs(adminBanca());
    $entrata = movimentoBanca($s, ['importo' => 10000, 'descrizione' => 'Bonifico Rossi Mario']);
    movimentoBanca($s, ['importo' => -250, 'descrizione' => 'Commissioni', 'hash' => str_repeat('a', 64)]);

    $this->get(route('admin.gestionale.banca.movimenti.index', ['condominio' => $s['condominio']->id, 'verso' => 'entrate']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('gestionale/banca/BancaMovimenti')
            ->has('movimenti.data', 1)
            ->where('movimenti.data.0.id', $entrata->id)
            ->where('conteggi.da_abbinare', 1)
        );

    $this->getJson(route('admin.gestionale.banca.movimenti.proposte', [$s['condominio'], $entrata->id]))
        ->assertOk()
        ->assertJsonPath('proposte.incasso.anagrafica.id', $s['rossi']->id);
});
