<?php

namespace App\Services\Assemblee;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Termine **indicativo** di impugnazione delle delibere (art. 1137 c.c.).
 *
 * Trenta giorni, perentori: per i dissenzienti e gli astenuti dalla data della delibera, per gli
 * assenti dalla **comunicazione** del verbale. Si applica la sospensione feriale dal 1° al 31 agosto
 * (L. 742/1969; Corte cost. 49/1990) e, se l'ultimo giorno cade di sabato o di domenica, la scadenza
 * slitta al lunedì (art. 155 c.p.c.). Le altre festività non sono considerate, e prima della causa
 * c'è la mediazione obbligatoria: per questo il programma parla di termine indicativo.
 */
class ImpugnazioneService
{
    public const GIORNI = 30;

    public function scadenza(CarbonInterface $decorrenza): Carbon
    {
        $giorno = Carbon::parse($decorrenza)->startOfDay();
        $contati = 0;

        while ($contati < self::GIORNI) {
            $giorno = $giorno->addDay();

            if ((int) $giorno->month === 8) {
                continue;
            }

            $contati++;
        }

        while ($giorno->isWeekend()) {
            $giorno = $giorno->addDay();
        }

        return $giorno;
    }
}
