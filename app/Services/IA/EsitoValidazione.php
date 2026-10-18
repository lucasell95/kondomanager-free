<?php

namespace App\Services\IA;

/** Esito dei controlli di `ValidatoreBozza`: errori gravi (bozza da scartare) e avvisi (da rivedere). */
final class EsitoValidazione
{
    /**
     * @param  list<string>  $errori
     * @param  list<string>  $avvisi
     */
    public function __construct(
        public readonly array $errori = [],
        public readonly array $avvisi = [],
    ) {}

    public function superata(): bool
    {
        return $this->errori === [];
    }

    /** @return list<string> Errori e avvisi in un'unica lista leggibile, errori per primi. */
    public function messaggi(): array
    {
        return array_merge(
            array_map(fn (string $e) => 'Errore: '.$e, $this->errori),
            $this->avvisi,
        );
    }
}
