<?php

/**
 * Termini della convocazione (art. 66 disp. att., art. 1136 c. 3 c.c.) e dell'impugnazione (art. 1137 c.c.).
 */

use App\Enums\Assemblee\MezzoConvocazione;
use App\Models\Assemblee\DestinatarioConvocazione;
use App\Services\Assemblee\ControlliConvocazioneService;
use App\Services\Assemblee\ImpugnazioneService;
use Illuminate\Support\Carbon;

function assRicezione(?string $data, MezzoConvocazione $mezzo = MezzoConvocazione::RACCOMANDATA): array
{
    $d = new DestinatarioConvocazione(['nominativo' => 'Rossi Mario', 'mezzo' => $mezzo, 'data_ricezione' => $data]);

    return (new ControlliConvocazioneService)->statoRicezione($d, Carbon::parse('2026-11-20 21:00'));
}

it('l\'avviso ricevuto meno di cinque giorni prima della prima convocazione non rispetta il termine', function () {
    expect(assRicezione('2026-11-16')['codice'])->toBe('termine_non_rispettato')
        ->and(assRicezione('2026-11-16')['livello'])->toBe('error')
        ->and(assRicezione('2026-11-20')['codice'])->toBe('termine_non_rispettato');
});

it('cinque giorni esatti sono nel termine ma al limite, sei sono sicuri', function () {
    expect(assRicezione('2026-11-15')['codice'])->toBe('termine_al_limite')
        ->and(assRicezione('2026-11-15')['livello'])->toBe('warning')
        ->and(assRicezione('2026-11-14')['codice'])->toBe('termine_rispettato')
        ->and(assRicezione('2026-11-01')['livello'])->toBe('ok');
});

it('senza data di ricezione il termine non è verificabile', function () {
    expect(assRicezione(null)['codice'])->toBe('ricezione_mancante');
});

it('l\'e-mail ordinaria è segnalata come mezzo non valido anche se ricevuta in tempo', function () {
    $stato = assRicezione('2026-11-01', MezzoConvocazione::EMAIL);

    expect($stato['codice'])->toBe('mezzo_non_valido')
        ->and($stato['livello'])->toBe('error')
        ->and($stato['messaggio'])->toContain('16399/2025')
        ->and(MezzoConvocazione::PEC->valido())->toBeTrue()
        ->and(MezzoConvocazione::MANO->valido())->toBeTrue()
        ->and(MezzoConvocazione::EMAIL->valido())->toBeFalse();
});

it('la seconda convocazione non può essere lo stesso giorno della prima, né oltre dieci giorni', function () {
    $c = new ControlliConvocazioneService;
    $prima = Carbon::parse('2026-11-20 18:00');

    expect($c->erroriDate($prima, Carbon::parse('2026-11-20 21:00')))->toHaveCount(1)
        ->and($c->erroriDate($prima, Carbon::parse('2026-11-20 21:00'))[0])->toContain('stesso giorno')
        ->and($c->erroriDate($prima, Carbon::parse('2026-12-01 09:00'))[0])->toContain('dieci giorni')
        ->and($c->erroriDate($prima, Carbon::parse('2026-11-19 21:00'))[0])->toContain('prima della prima')
        ->and($c->erroriDate($prima, Carbon::parse('2026-11-21 09:00')))->toBe([])
        ->and($c->erroriDate($prima, Carbon::parse('2026-11-30 23:00')))->toBe([])
        ->and($c->erroriDate($prima, null))->toBe([]);
});

it('il termine di impugnazione è di trenta giorni con la sospensione feriale di agosto', function () {
    $i = new ImpugnazioneService;

    // 20/11/2026 + 30 giorni = 20/12/2026, domenica → lunedì 21/12.
    expect($i->scadenza(Carbon::parse('2026-11-20'))->toDateString())->toBe('2026-12-21')
        // 20/07/2027: 11 giorni a luglio, agosto sospeso, 19 a settembre → 19/09/2027 (domenica) → 20/09.
        ->and($i->scadenza(Carbon::parse('2027-07-20'))->toDateString())->toBe('2027-09-20')
        // Ricevuto in agosto: si comincia a contare dal 1° settembre.
        ->and($i->scadenza(Carbon::parse('2026-08-10'))->toDateString())->toBe('2026-09-30');
});
