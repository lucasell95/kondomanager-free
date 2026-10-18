<?php

namespace App\Contracts\IA;

/** Esito di una generazione: l'output strutturato (già de-pseudonimizzato) e i metadati. */
final class RisultatoGenerazione
{
    /**
     * @param  array<string, mixed>  $dati  Output conforme allo schema richiesto.
     * @param  list<string>  $avvisi  Problemi trovati dalla validazione (bozza da rivedere con attenzione).
     */
    public function __construct(
        public readonly bool $ok,
        public readonly array $dati = [],
        public readonly array $avvisi = [],
        public readonly ?string $errore = null,
        public readonly ?string $modello = null,
        public readonly int $tokenIngresso = 0,
        public readonly int $tokenUscita = 0,
    ) {}

    public static function nonDisponibile(string $motivo = 'IA non configurata'): self
    {
        return new self(ok: false, errore: $motivo);
    }
}
