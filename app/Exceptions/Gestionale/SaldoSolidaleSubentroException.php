<?php

namespace App\Exceptions\Gestionale;

/**
 * Il pregresso di un'unità passata di mano non si ripartisce in automatico.
 *
 * Art. 63 c. 4 disp. att. c.c.: chi subentra nei diritti di un condòmino è obbligato in solido con
 * lui **solo** per i contributi dell'anno in corso e di quello precedente. I debiti più vecchi
 * restano del venditore. Il saldo solidale dell'unità non dice a quali anni risale, quindi il
 * programma non può separare le due parti: ripartirlo da solo sui titolari attuali metteva
 * l'intero pregresso a carico dell'acquirente (e, con il venditore ancora censito, lo divideva
 * per quote fra i due senza nessun criterio di legge).
 *
 * Estende `SaldoSolidaleSenzaTitolareException` perché il rimedio è lo stesso — il riparto
 * manuale, o un saldo intestato — e chi già intercetta quella la gestisce senza modifiche.
 */
class SaldoSolidaleSubentroException extends SaldoSolidaleSenzaTitolareException
{
    public function __construct(
        int $saldoId,
        ?int $immobileId,
        string $nomeImmobile,
        int $importoCents,
        string $passaggio,
    ) {
        parent::__construct($saldoId, $immobileId, $nomeImmobile, $importoCents);

        $this->message = "Il pregresso dell'unità «{$nomeImmobile}» non viene ripartito in automatico: "
            ."sull'unità risulta un passaggio di titolarità ({$passaggio}). "
            .'Per l\'art. 63 c. 4 disp. att. c.c. chi subentra risponde in solido solo dei contributi '
            .'dell\'anno in corso e di quello precedente: i debiti più vecchi restano del venditore. '
            .'Usa il riparto manuale per dividere il pregresso fra venditore e acquirente, oppure '
            .'intestalo a chi lo deve con un saldo nominale.';
    }
}
