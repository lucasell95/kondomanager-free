<?php

namespace App\Services\Assemblee;

/**
 * Una maggioranza espressa come due frazioni: una sulle **teste** e una sul **valore** (millesimi).
 *
 * I confronti si fanno sempre su interi moltiplicando in croce, mai dividendo: «almeno un terzo del
 * valore» è `3 × m ≥ T`, non `m ≥ 333,33`. Così 1/3 di 1000 non diventa 333 né 334 per un
 * arrotondamento, e la regola vale per qualunque totale della tabella.
 *
 * - `stretta` = «più di» (la maggioranza: più della metà);
 * - non stretta = «almeno» (almeno metà del valore, almeno un terzo dei partecipanti).
 */
final class RegolaMaggioranza
{
    public const BASE_INTERVENUTI = 'intervenuti';
    public const BASE_PARTECIPANTI = 'partecipanti';

    public function __construct(
        public readonly string $baseTeste,
        public readonly int $testeNum,
        public readonly int $testeDen,
        public readonly bool $testeStretta,
        public readonly int $valoreNum,
        public readonly int $valoreDen,
        public readonly bool $valoreStretta = false,
        public readonly bool $unanimita = false,
        public readonly string $descrizione = '',
        public readonly string $norma = '',
    ) {}

    /** Maggioranza degli intervenuti e almeno `num/den` del valore dell'edificio. */
    public static function intervenutiEValore(int $num, int $den, string $descrizione, string $norma): self
    {
        return new self(self::BASE_INTERVENUTI, 1, 2, true, $num, $den, false, false, $descrizione, $norma);
    }

    /** Il consenso di tutti i partecipanti al condominio. */
    public static function unanimita(string $norma): self
    {
        return new self(self::BASE_PARTECIPANTI, 1, 1, false, 1, 1, false, true, 'Unanimità dei partecipanti al condominio', $norma);
    }

    /**
     * La soglia sulle teste è raggiunta da `$teste` su una base di `$base`?
     *
     * Con base zero nessuna soglia è raggiunta: un'assemblea senza intervenuti non delibera.
     */
    public function testeRaggiunte(int $teste, int $base): bool
    {
        if ($base <= 0) {
            return false;
        }

        $sinistra = $teste * $this->testeDen;
        $destra = $this->testeNum * $base;

        return $this->testeStretta ? $sinistra > $destra : $sinistra >= $destra;
    }

    public function valoreRaggiunto(int $valore, int $totale): bool
    {
        if ($totale <= 0) {
            return false;
        }

        $sinistra = $valore * $this->valoreDen;
        $destra = $this->valoreNum * $totale;

        return $this->valoreStretta ? $sinistra > $destra : $sinistra >= $destra;
    }

    /** «più della metà», «almeno 1/3», «almeno 2/3»... */
    public static function frazioneLeggibile(int $num, int $den, bool $stretta): string
    {
        if ($num === 1 && $den === 2) {
            return $stretta ? 'più della metà' : 'almeno la metà';
        }

        if ($num === $den) {
            return 'la totalità';
        }

        return ($stretta ? 'più di ' : 'almeno ').$num.'/'.$den;
    }

    public function testoTeste(): string
    {
        $base = $this->baseTeste === self::BASE_INTERVENUTI ? 'degli intervenuti' : 'dei partecipanti al condominio';

        return self::frazioneLeggibile($this->testeNum, $this->testeDen, $this->testeStretta).' '.$base;
    }

    public function testoValore(): string
    {
        return self::frazioneLeggibile($this->valoreNum, $this->valoreDen, $this->valoreStretta).' del valore dell\'edificio';
    }
}
