<?php

use App\Services\Gestionale\EstrattoContoAggregatoService;

/*
 * Vista aggregata dell'estratto conto: le emissioni di gestioni diverse con la stessa scadenza
 * (ordinaria + straordinaria) diventano una riga sola, come la rata che il condòmino paga.
 * Il service lavora sulla timeline già costruita: qui la timeline è scritta a mano.
 */

function ecRigaEmissione(int $id, int $scrittura, string $gestione, int $rataId, string $scadenza, int $dare, string $stato, string $unita): array
{
    return [
        'id' => $id, 'data' => '01/06/2026', 'descrizione' => "Emissione Rata n.1 - {$gestione}",
        'gestione' => $gestione, 'protocollo' => "SCR-{$scrittura}", 'note' => null,
        'dettagli' => [], 'tipo_icona' => 'bill', 'dare' => $dare, 'avere' => 0, 'saldo' => 0,
        'breakdown' => ['type' => 'emissione', 'immobile' => $unita],
        'scrittura_id' => $scrittura, 'tipo_riga' => 'dare', 'rata_id' => $rataId,
        'rata_label' => 'Rata n.1 (Scad. 01/06/2026)', 'rata_numero' => 1, 'rata_scadenza' => $scadenza,
        'stato_rata' => $stato, 'immobile_id' => null, 'immobile_label' => $unita,
        'quota_pura' => $dare, 'saldo_usato' => 0, 'totale_richiesto' => $dare, 'n_righe' => 1,
    ];
}

it('unisce ordinaria e straordinaria con la stessa scadenza in una riga', function () {
    $timeline = collect([
        ecRigaEmissione(1, 10, 'Ordinaria', 100, '2026-06-01', 10000, 'pagata', 'Appartamento'),
        ecRigaEmissione(2, 10, 'Ordinaria', 100, '2026-06-01', 2000, 'pagata', 'Garage'),
        ecRigaEmissione(3, 20, 'Straordinaria', 200, '2026-06-01', 1500, 'da_pagare', 'Appartamento'),
        ecRigaEmissione(4, 30, 'Ordinaria', 101, '2026-09-01', 12000, 'da_pagare', 'Appartamento'),
    ]);

    $agg = (new EstrattoContoAggregatoService())->aggrega($timeline, 5000);

    expect($agg)->toHaveCount(2);

    $prima = $agg[0];
    expect($prima['dare'])->toBe(13500)
        ->and($prima['saldo'])->toBe(18500)
        ->and($prima['descrizione'])->toBe('Emissione Rata n.1 (Scad. 01/06/2026)')
        ->and($prima['gestione'])->toBe('2 gestioni')
        ->and($prima['stato_rata'])->toBe('parzialmente_pagata')
        ->and(collect($prima['dettagli'])->where('type', 'rata')->pluck('status')->all())->toBe(['pagata', 'da_pagare']);

    // La scadenza successiva resta per conto suo, e il saldo finale è quello della timeline espansa.
    expect($agg[1]['dare'])->toBe(12000)
        ->and($agg[1]['saldo'])->toBe(5000 + 10000 + 2000 + 1500 + 12000)
        ->and($agg[1]['gestione'])->toBe('Ordinaria');
});
