<?php

namespace App\Support\Assemblee;

/**
 * Millesimi come interi in **centomillesimi** (valore × 100000).
 *
 * `quote_tabella.valore` è `decimal(12,5)`: con cinque decimali la conversione è esatta, e da lì in
 * poi tutte le somme e i confronti dei quorum sono su interi. Nessun `float` attraversa il calcolo.
 */
final class Millesimi
{
    public const SCALA = 100000;

    /** Da un valore della colonna decimale (stringa, intero o float letto dal driver) all'intero. */
    public static function daDecimale(string|int|float|null $valore): int
    {
        if ($valore === null || $valore === '') {
            return 0;
        }

        if (is_int($valore)) {
            return $valore * self::SCALA;
        }

        $testo = is_float($valore) ? sprintf('%.5F', $valore) : trim(str_replace(',', '.', $valore));

        $negativo = str_starts_with($testo, '-');
        $testo = ltrim($testo, '+-');

        [$intera, $decimali] = array_pad(explode('.', $testo, 2), 2, '');
        $intera = preg_replace('/\D/', '', $intera) ?: '0';
        $decimali = substr(str_pad(preg_replace('/\D/', '', $decimali), 5, '0'), 0, 5);

        $risultato = ((int) $intera) * self::SCALA + (int) $decimali;

        return $negativo ? -$risultato : $risultato;
    }

    /**
     * «166,500» da 16650000. Mostra almeno `$decimali` cifre e, se ce ne sono di significative oltre,
     * le mostra tutte: la stampa non deve nascondere un millesimo che il calcolo ha contato.
     */
    public static function formatta(int $valore, int $decimali = 3): string
    {
        $segno = $valore < 0 ? '-' : '';
        $valore = abs($valore);

        $intera = intdiv($valore, self::SCALA);
        $frazione = str_pad((string) ($valore % self::SCALA), 5, '0', STR_PAD_LEFT);
        $frazione = rtrim($frazione, '0');
        $frazione = str_pad($frazione, max(0, min(5, $decimali)), '0');

        $interaFormattata = number_format($intera, 0, ',', '.');

        return $segno.$interaFormattata.($frazione !== '' ? ','.$frazione : '');
    }
}
