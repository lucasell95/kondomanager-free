<?php

namespace App\Services\Gestionale\Fiscale;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Stima di interessi e sanzione per ravvedere una ritenuta versata (o da versare) in ritardo.
 *
 * È una **stima**, e lo dice sempre (`da_verificare = true` nel risultato): le percentuali delle
 * fasce stanno in `config/fiscale.php` e vengono da tabelle non ufficiali (art. 13 D.Lgs.
 * 471/1997 e art. 13 D.Lgs. 472/1997 dopo il D.Lgs. 87/2024). Serve a dire all'amministratore
 * «c'è da ravvedere, più o meno questo», non a compilare l'F24 del ravvedimento.
 *
 * Gli interessi invece sono aritmetica: tasso legale annuo, pro rata giorno per giorno, con i
 * tassi storicizzati della stessa configurazione. Si contano dal giorno dopo la scadenza fino
 * al giorno del versamento compreso.
 *
 * Funzione pura: nessuna query, nessuno stato.
 */
class RavvedimentoService
{
    /**
     * @return array{giorni:int, interessi:int, percentuale_sanzione:float, sanzione:int, totale:int, codice_sanzione:?string, da_verificare:bool}
     */
    public function stima(int $importo, CarbonInterface $scadenza, CarbonInterface $dataVersamento): array
    {
        $scadenza = CarbonImmutable::parse($scadenza->toDateString());
        $versamento = CarbonImmutable::parse($dataVersamento->toDateString());

        $giorni = $versamento->greaterThan($scadenza) ? (int) $scadenza->diffInDays($versamento) : 0;

        if ($giorni === 0 || $importo <= 0) {
            return [
                'giorni' => 0,
                'interessi' => 0,
                'percentuale_sanzione' => 0.0,
                'sanzione' => 0,
                'totale' => 0,
                'codice_sanzione' => null,
                'da_verificare' => true,
            ];
        }

        $interessi = $this->interessi($importo, $scadenza, $versamento);
        $percentuale = $this->percentualeSanzione($giorni);
        $sanzione = (int) round($importo * $percentuale / 100);

        return [
            'giorni' => $giorni,
            'interessi' => $interessi,
            'percentuale_sanzione' => $percentuale,
            'sanzione' => $sanzione,
            'totale' => $importo + $interessi + $sanzione,
            'codice_sanzione' => config('fiscale.ravvedimento.codice_sanzione_suggerito'),
            'da_verificare' => true,
        ];
    }

    /**
     * Interessi legali dal giorno dopo la scadenza al giorno del versamento compreso.
     *
     * Ogni giorno prende il tasso vigente in quel giorno: un ritardo a cavallo d'anno usa due
     * tassi diversi.
     */
    public function interessi(int $importo, CarbonImmutable $scadenza, CarbonImmutable $versamento): int
    {
        $tassi = collect(config('fiscale.interessi_legali', []))
            ->map(fn ($r) => ['dal' => CarbonImmutable::parse($r['dal']), 'tasso' => (float) $r['tasso']])
            ->sortBy('dal')
            ->values();

        if ($tassi->isEmpty()) {
            return 0;
        }

        $totale = 0.0;
        $giorno = $scadenza->addDay();

        while ($giorno->lessThanOrEqualTo($versamento)) {
            $vigente = $tassi->filter(fn ($t) => $t['dal']->lessThanOrEqualTo($giorno))->last()
                ?? $tassi->first();
            $giorniAnno = $giorno->isLeapYear() ? 366 : 365;
            $totale += $importo * $vigente['tasso'] / 100 / $giorniAnno;
            $giorno = $giorno->addDay();
        }

        return (int) round($totale);
    }

    /** Percentuale di sanzione ridotta per il ravvedimento, secondo le fasce configurate. */
    public function percentualeSanzione(int $giorni): float
    {
        foreach (config('fiscale.ravvedimento.fasce', []) as $fascia) {
            $limite = $fascia['fino_a_giorni'];

            if ($limite === null || $giorni <= $limite) {
                if (isset($fascia['per_giorno'])) {
                    return round($fascia['per_giorno'] * $giorni, 4);
                }

                return (float) $fascia['percentuale'];
            }
        }

        return 0.0;
    }
}
