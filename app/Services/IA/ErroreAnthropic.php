<?php

namespace App\Services\IA;

use RuntimeException;

/** Errore nella chiamata all'API Anthropic, con un messaggio in italiano adatto all'utente. */
class ErroreAnthropic extends RuntimeException
{
    public function __construct(
        string $messaggio,
        public readonly string $codice,
        public readonly ?int $stato = null,
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($messaggio);
    }
}
