<?php

namespace App\Contracts\IA;

/**
 * Esito di una generazione: l'output strutturato (già de-pseudonimizzato) e i metadati.
 *
 * - `ok = true`: la bozza ha superato i controlli automatici; gli `avvisi` indicano i passaggi da
 *   rileggere con attenzione (per esempio cifre scritte fuori dai segnaposto).
 * - `ok = false` con `dati` vuoti: la chiamata non è riuscita (`errore` spiega perché, in italiano).
 * - `ok = false` con `dati` pieni: la bozza è arrivata ma non ha superato i controlli gravi
 *   (punti mancanti, segnaposto inesistenti…). Non va usata così com'è: si può mostrare all'utente
 *   insieme agli `avvisi`, oppure ripiegare sul testo fisso.
 *
 * In ogni caso la bozza va approvata da una persona prima di diventare un documento.
 */
final class RisultatoGenerazione
{
    /**
     * @param  array<string, mixed>  $dati  Output conforme allo schema richiesto.
     * @param  list<string>  $avvisi  Problemi trovati dalla validazione (bozza da rivedere con attenzione).
     * @param  float  $costoStimatoUsd  Costo stimato della chiamata in dollari (listino di config/ia.php).
     * @param  string|null  $versionePrompt  Versione dei prompt usati (es. «sistema.v1+verbale.v1»), da salvare con la bozza.
     */
    public function __construct(
        public readonly bool $ok,
        public readonly array $dati = [],
        public readonly array $avvisi = [],
        public readonly ?string $errore = null,
        public readonly ?string $modello = null,
        public readonly int $tokenIngresso = 0,
        public readonly int $tokenUscita = 0,
        public readonly float $costoStimatoUsd = 0.0,
        public readonly ?string $versionePrompt = null,
    ) {}

    public static function nonDisponibile(string $motivo = 'IA non configurata'): self
    {
        return new self(ok: false, errore: $motivo);
    }
}
