<?php

namespace App\Services\Gestionale\Banca\Lettori;

use Carbon\CarbonImmutable;

/**
 * Quello che un lettore ricava da un file di estratto conto, prima di qualunque confronto con
 * l'archivio. Gli importi sono in centesimi con segno (positivo = accredito).
 *
 * Ogni movimento è un array:
 *   riga             int|null          riga del file (1 = prima), per dire all'utente da dove viene
 *   data_contabile   CarbonImmutable
 *   data_valuta      CarbonImmutable|null
 *   importo          int
 *   divisa           string
 *   descrizione      string
 *   controparte      string|null
 *   iban_controparte string|null
 *   riferimento      string|null
 */
final class EstrattoLetto
{
    /**
     * @param  list<array<string, mixed>>  $movimenti
     * @param  list<array{riga: int|null, motivo: string}>  $scarti
     * @param  array<string, mixed>  $tabella  solo per i formati tabellari: righe grezze, intestazione, configurazione
     */
    public function __construct(
        public readonly string $formato,
        public array $movimenti = [],
        public array $scarti = [],
        public ?int $saldoIniziale = null,
        public ?CarbonImmutable $dataSaldoIniziale = null,
        public ?int $saldoFinale = null,
        public ?CarbonImmutable $dataSaldoFinale = null,
        public ?string $ibanConto = null,
        public array $tabella = [],
    ) {}

    public function dataDa(): ?CarbonImmutable
    {
        $date = array_map(fn ($m) => $m['data_contabile'], $this->movimenti);

        return $date === [] ? null : min($date);
    }

    public function dataA(): ?CarbonImmutable
    {
        $date = array_map(fn ($m) => $m['data_contabile'], $this->movimenti);

        return $date === [] ? null : max($date);
    }
}
