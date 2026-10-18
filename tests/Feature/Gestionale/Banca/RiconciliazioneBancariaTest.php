<?php

/**
 * Banca → Riconciliazione: saldo dell'estratto conto contro saldo contabile, con le differenze.
 */

use App\Actions\Gestionale\Movimenti\StoreIncassoRateAction;
use App\Models\Gestionale\Banca\MovimentoBancario;
use App\Models\Gestionale\Banca\SaldoEstrattoConto;
use App\Services\Gestionale\Banca\AbbinamentoBancarioService;
use App\Services\Gestionale\Banca\RiconciliazioneBancariaService;
use Carbon\CarbonImmutable;

require_once __DIR__.'/BancaTestHelpers.php';

function incassoDiProva(array $s, string $chi, int $n, string $data): \App\Models\Gestionale\ScritturaContabile
{
    return app(StoreIncassoRateAction::class)->execute([
        'pagante_id' => $s[$chi === 'Rossi Mario' ? 'rossi' : 'bianchi']->id,
        'cassa_id' => $s['cassa']->id, 'gestione_id' => $s['gestione']->id,
        'data_pagamento' => $data, 'importo_totale' => 100.00, 'descrizione' => 'Rata '.$n.' '.$chi,
        'eccedenza' => 0,
        'dettaglio_pagamenti' => [['rata_id' => $s['quote'][$chi][$n]->id, 'importo' => 100.00]],
    ], $s['condominio'], $s['esercizio']);
}

it('spiega la differenza fra saldo della banca e saldo contabile', function () {
    $s = scenarioBanca();
    $this->actingAs(adminBanca());

    // A giornale: due incassi di febbraio. In banca: uno dei due, più una commissione non
    // ancora registrata. L'altro incasso non è (ancora) passato dalla banca.
    $abbinato = incassoDiProva($s, 'Rossi Mario', 1, '2026-02-05');
    $soloGiornale = incassoDiProva($s, 'Bianchi Anna', 1, '2026-02-20');

    $m1 = movimentoBanca($s, ['data_contabile' => '2026-02-05', 'importo' => 10000]);
    app(AbbinamentoBancarioService::class)->collega($m1, $abbinato->id, MovimentoBancario::ABBINAMENTO_ESISTENTE, null);
    $commissione = movimentoBanca($s, ['data_contabile' => '2026-02-28', 'importo' => -250, 'descrizione' => 'Commissioni', 'hash' => str_repeat('a', 64)]);

    // Saldo dell'estratto: 0 a fine gennaio, 97,50 a fine febbraio.
    SaldoEstrattoConto::imposta($s['condominio']->id, $s['cassa']->id, '2026-01-31', 0, 'manuale');
    SaldoEstrattoConto::imposta($s['condominio']->id, $s['cassa']->id, '2026-02-28', 9750, 'manuale');

    $r = app(RiconciliazioneBancariaService::class)->calcola(
        $s['cassa'], CarbonImmutable::parse('2026-02-01'), CarbonImmutable::parse('2026-02-28'), null, null
    );

    expect($r['saldo_banca_iniziale'])->toBe(0)
        ->and($r['saldo_banca_finale'])->toBe(9750)
        ->and($r['saldo_banca_calcolato'])->toBe(9750)
        ->and($r['saldo_contabile_iniziale'])->toBe(0)
        ->and($r['saldo_contabile_finale'])->toBe(20000)
        ->and($r['differenza_finale'])->toBe(9750 - 20000)
        ->and($r['residuo_non_spiegato'])->toBe(0)
        ->and($r['movimenti_banca']['abbinati'])->toBe(1)
        ->and($r['movimenti_banca']['da_abbinare'])->toBe(1);

    $perTipo = collect($r['differenze'])->keyBy('tipo');

    expect($perTipo['solo_banca']['movimento_id'])->toBe($commissione->id)
        ->and($perTipo['solo_banca']['effetto'])->toBe(-250)
        ->and($perTipo['solo_giornale']['scrittura_id'])->toBe($soloGiornale->id)
        ->and($perTipo['solo_giornale']['effetto'])->toBe(-10000)
        ->and($r['differenze'])->toHaveCount(2);
});

it('segnala un residuo non spiegato quando mancano movimenti nell\'import', function () {
    $s = scenarioBanca();
    $abbinato = incassoDiProva($s, 'Rossi Mario', 1, '2026-02-05');
    $m1 = movimentoBanca($s, ['data_contabile' => '2026-02-05', 'importo' => 10000]);
    app(AbbinamentoBancarioService::class)->collega($m1, $abbinato->id, MovimentoBancario::ABBINAMENTO_ESISTENTE, null);

    // L'estratto dice 150,00 a fine mese, ma l'import ha solo il bonifico da 100,00.
    $r = app(RiconciliazioneBancariaService::class)->calcola(
        $s['cassa'], CarbonImmutable::parse('2026-02-01'), CarbonImmutable::parse('2026-02-28'), 0, 15000
    );

    expect($r['differenze'])->toBe([])
        ->and($r['saldo_banca_calcolato'])->toBe(10000)
        ->and($r['residuo_non_spiegato'])->toBe(5000);
});

it('la pagina della riconciliazione si apre e salva il saldo inserito a mano', function () {
    $s = scenarioBanca();
    $this->actingAs(adminBanca());

    $this->get(route('admin.gestionale.banca.riconciliazione', [
        'condominio' => $s['condominio']->id, 'cassa_id' => $s['cassa']->id, 'da' => '2026-02-01', 'a' => '2026-02-28',
    ]))->assertOk()->assertInertia(fn ($page) => $page
        ->component('gestionale/banca/BancaRiconciliazione')
        ->where('cassa_id', $s['cassa']->id)
        ->where('riconciliazione.periodo.da', '2026-02-01')
        ->where('riconciliazione.saldo_banca_finale', null)
    );

    $this->post(route('admin.gestionale.banca.riconciliazione.saldo', $s['condominio']), [
        'cassa_id' => $s['cassa']->id, 'data' => '2026-02-28', 'saldo' => 12345,
    ])->assertSessionHasNoErrors();

    // Una seconda volta sullo stesso giorno sostituisce, non duplica.
    $this->post(route('admin.gestionale.banca.riconciliazione.saldo', $s['condominio']), [
        'cassa_id' => $s['cassa']->id, 'data' => '2026-02-28', 'saldo' => 23456,
    ])->assertSessionHasNoErrors();

    expect(SaldoEstrattoConto::where('cassa_id', $s['cassa']->id)->count())->toBe(1)
        ->and(SaldoEstrattoConto::where('cassa_id', $s['cassa']->id)->value('saldo'))->toBe(23456);
});
