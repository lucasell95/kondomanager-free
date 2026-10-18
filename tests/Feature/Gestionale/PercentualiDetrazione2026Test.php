<?php

/**
 * Le percentuali indicative delle detrazioni seguono le aliquote in vigore per le spese 2025-2026.
 *
 * Erano 50% per la ristrutturazione e 110-90% per il superbonus. Per il 2025 e il 2026
 * ristrutturazione, ecobonus e sismabonus valgono 50% sull'abitazione principale e 36% negli altri
 * casi (L. 207/2024, prorogata dalla L. 199/2025); il superbonus è residuale. Non entrano in nessun
 * calcolo: sono testo per l'amministratore, e quindi non devono raccontargli numeri superati.
 */

use App\Enums\TipoDetrazione;

test('ristrutturazione, ecobonus e sismabonus: 50% / 36% per le spese 2025-2026', function () {
    foreach ([TipoDetrazione::RISTRUTTURAZIONE, TipoDetrazione::ECOBONUS, TipoDetrazione::SISMABONUS] as $tipo) {
        expect($tipo->percentualeDetrazione())
            ->toContain('50% abitazione principale')
            ->toContain('36%')
            ->not->toContain('65%')
            ->not->toContain('90%');
    }
});

test('il superbonus non promette più il 110%', function () {
    expect(TipoDetrazione::SUPERBONUS->percentualeDetrazione())
        ->not->toContain('110%')
        ->toContain('Residuale');
});
