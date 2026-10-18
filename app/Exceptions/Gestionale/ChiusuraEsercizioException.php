<?php

namespace App\Exceptions\Gestionale;

/**
 * La chiusura (o il suo annullamento) non si può eseguire: il messaggio dice perché, in una
 * forma da mostrare all'amministratore. Il controller la cattura e torna alla procedura.
 */
class ChiusuraEsercizioException extends \DomainException
{
    /** @param list<string> $motivi */
    public function __construct(string $messaggio, public readonly array $motivi = [])
    {
        parent::__construct($messaggio);
    }
}
