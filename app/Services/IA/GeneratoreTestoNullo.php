<?php

namespace App\Services\IA;

use App\Contracts\IA\GeneratoreTesto;
use App\Contracts\IA\RisultatoGenerazione;

/** Nessuna IA configurata: i chiamanti usano i modelli fissi. */
class GeneratoreTestoNullo implements GeneratoreTesto
{
    public function disponibile(): bool
    {
        return false;
    }

    public function genera(string $tipoDocumento, array $contesto, array $schema, ?string $istruzioni = null): RisultatoGenerazione
    {
        return RisultatoGenerazione::nonDisponibile();
    }
}
