<?php

namespace App\Exceptions\Gestionale;

/**
 * Un movimento bancario non si può abbinare così com'è: niente è stato scritto.
 *
 * `codice` dice all'interfaccia se il blocco si supera con una conferma esplicita (IBAN diverso
 * dall'anagrafica, saldo insufficiente, possibile doppione: gli stessi blocchi aggirabili della
 * maschera pagamenti) o se va corretto il dato (`null`).
 */
class AbbinamentoBancarioException extends \DomainException
{
    public function __construct(string $messaggio, public readonly ?string $codice = null, ?\Throwable $precedente = null)
    {
        parent::__construct($messaggio, 0, $precedente);
    }
}
