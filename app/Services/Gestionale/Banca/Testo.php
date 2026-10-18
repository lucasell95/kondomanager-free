<?php

namespace App\Services\Gestionale\Banca;

use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Conversioni di base per gli estratti conto: testo, importi, date, IBAN, controparti.
 *
 * Tutte statiche e senza stato: le usano lettori, anti-duplicati e proposte di abbinamento, e
 * devono dare lo stesso risultato a tutti — un hash calcolato con una normalizzazione diversa
 * da quella del giro prima non riconosce più il proprio doppione.
 */
final class Testo
{
    /**
     * Minuscolo, senza accenti né punteggiatura, spazi compressi.
     *
     * «Bonifico  a Vostro favore - ROSSI M.» → «bonifico a vostro favore rossi m»
     */
    public static function normalizza(?string $testo): string
    {
        if ($testo === null || $testo === '') {
            return '';
        }

        $t = mb_strtolower($testo, 'UTF-8');
        $t = strtr($t, [
            'à' => 'a', 'á' => 'a', 'è' => 'e', 'é' => 'e', 'ì' => 'i', 'í' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ù' => 'u', 'ú' => 'u', 'ç' => 'c', 'ñ' => 'n',
        ]);
        $t = preg_replace('/[^a-z0-9]+/u', ' ', $t) ?? $t;

        return trim(preg_replace('/\s+/', ' ', $t) ?? $t);
    }

    /** IBAN senza spazi, in maiuscolo; `null` se non ha la forma di un IBAN o il controllo non torna. */
    public static function normalizzaIban(?string $iban): ?string
    {
        if ($iban === null) {
            return null;
        }

        $pulito = strtoupper(preg_replace('/\s+/', '', $iban) ?? '');

        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $pulito) !== 1) {
            return null;
        }

        return self::controlloIban($pulito) ? $pulito : null;
    }

    /** Il controllo ISO 13616 (mod 97): scarta i numeri che hanno solo la forma di un IBAN. */
    public static function controlloIban(string $iban): bool
    {
        $riordinato = substr($iban, 4).substr($iban, 0, 4);
        $resto = 0;

        foreach (str_split($riordinato) as $c) {
            $cifre = ctype_alpha($c) ? (string) (ord($c) - 55) : $c;

            foreach (str_split($cifre) as $d) {
                $resto = ($resto * 10 + (int) $d) % 97;
            }
        }

        return $resto === 1;
    }

    /**
     * Il primo IBAN valido che compare in un testo, escluso quello del conto stesso.
     *
     * Le descrizioni delle banche italiane lo riportano spesso per esteso («IBAN ORD.: …»), a
     * volte a gruppi di quattro. Si accorcia il candidato finché il controllo mod 97 torna: così
     * la parola attaccata dopo l'IBAN non ne entra a far parte.
     */
    public static function estraiIban(?string $testo, ?string $daEscludere = null): ?string
    {
        if (! $testo) {
            return null;
        }

        $escluso = self::normalizzaIban($daEscludere);

        if (! preg_match_all('/\b[A-Z]{2}\d{2}(?: ?[A-Z0-9]{4}){2,8}(?: ?[A-Z0-9]{1,4})?\b/', strtoupper($testo), $trovati)) {
            return null;
        }

        foreach ($trovati[0] as $candidato) {
            $pulito = preg_replace('/\s+/', '', $candidato) ?? '';

            for ($lunghezza = min(strlen($pulito), 34); $lunghezza >= 15; $lunghezza--) {
                $iban = self::normalizzaIban(substr($pulito, 0, $lunghezza));

                if ($iban && $iban !== $escluso) {
                    return $iban;
                }
            }
        }

        return null;
    }

    /**
     * Il nome della controparte, cercato dopo le etichette con cui le banche italiane lo
     * scrivono nella descrizione («ORD.:», «MITT.:», «BENEF.:», «A FAVORE DI», «DA:»…).
     *
     * È un'euristica e lo dichiara: serve solo a proporre, mai a decidere. Se non trova
     * niente restituisce `null` e la proposta lavora sul testo intero.
     */
    public static function estraiControparte(?string $testo, bool $entrata): ?string
    {
        if (! $testo) {
            return null;
        }

        $etichette = $entrata
            ? ['ordinante', 'ord\.?', 'mitt\.?', 'mittente', 'disposto da', 'da']
            : ['beneficiario', 'benef\.?', 'a favore di', 'favore', 'a'];

        foreach ($etichette as $etichetta) {
            if (preg_match('/(?:^|[\s,;])'.$etichetta.'\s*:\s*([^:;]{3,80}?)(?=\s+[A-Za-z][A-Za-z.]{1,15}\.?\s*:|\s*;|$)/iu', $testo, $m)) {
                $nome = trim($m[1], " \t,.-");

                if ($nome !== '' && preg_match('/[a-z]/i', $nome)) {
                    return mb_substr($nome, 0, 120);
                }
            }
        }

        return null;
    }

    /**
     * Da «1.234,56», «-1234,56», «1,234.56», «€ 12,00», 1234.56 (numero di Excel) a centesimi.
     *
     * `$decimale` è il separatore decimale atteso (',' per le banche italiane). Con entrambi i
     * separatori nel testo vince l'ultimo, che è sempre il decimale. Restituisce `null` per una
     * cella vuota o che non è un numero.
     */
    public static function importoInCentesimi(mixed $valore, string $decimale = ','): ?int
    {
        if ($valore === null || $valore === '') {
            return null;
        }

        if (is_int($valore) || is_float($valore)) {
            return (int) round($valore * 100);
        }

        $s = trim((string) $valore);
        $negativo = false;

        // (12,50) e 12,50- sono forme contabili del negativo.
        if (preg_match('/^\((.*)\)$/', $s, $m)) {
            $s = $m[1];
            $negativo = true;
        }

        if (str_ends_with($s, '-')) {
            $s = substr($s, 0, -1);
            $negativo = true;
        }

        $s = preg_replace('/[^\d,.\-+]/', '', $s) ?? '';

        if (str_starts_with($s, '-')) {
            $negativo = ! $negativo;
            $s = substr($s, 1);
        } elseif (str_starts_with($s, '+')) {
            $s = substr($s, 1);
        }

        if ($s === '' || preg_match('/\d/', $s) !== 1) {
            return null;
        }

        $ultimaVirgola = strrpos($s, ',');
        $ultimoPunto = strrpos($s, '.');

        if ($ultimaVirgola !== false && $ultimoPunto !== false) {
            $sep = $ultimaVirgola > $ultimoPunto ? ',' : '.';
        } elseif ($ultimaVirgola !== false) {
            // Solo virgole: decimale, a meno che non siano migliaia all'inglese (1,234,567).
            $sep = ($decimale === '.' && preg_match('/^\d{1,3}(,\d{3})+$/', $s)) ? null : ',';
        } elseif ($ultimoPunto !== false) {
            // Solo punti: migliaia all'italiana (1.234 o 1.234.567) se il decimale atteso è la
            // virgola e la forma lo dice, altrimenti decimale.
            $sep = ($decimale === ',' && preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) ? null : '.';
        } else {
            $sep = null;
        }

        if ($sep === null) {
            $intero = str_replace([',', '.'], '', $s);
            $frazione = '';
        } else {
            $pos = strrpos($s, $sep);
            $intero = str_replace([',', '.'], '', substr($s, 0, $pos));
            $frazione = substr($s, $pos + 1);
        }

        $frazione = preg_replace('/\D/', '', $frazione) ?? '';
        $parteDecimale = strlen($frazione) <= 2
            ? (int) str_pad($frazione, 2, '0')
            : (int) round((float) ('0.'.$frazione) * 100);
        $centesimi = (int) $intero * 100 + $parteDecimale;

        return $negativo ? -$centesimi : $centesimi;
    }

    /**
     * Da «31/01/2026», «31-01-26», «31.01.2026», «2026-01-31», «20260131» o dal seriale di Excel
     * a una data. `null` se non è una data.
     *
     * `$formato` forza la lettura ('d/m/Y', 'Y-m-d', …); 'auto' prova le forme italiane prima.
     */
    public static function data(mixed $valore, string $formato = 'auto'): ?CarbonImmutable
    {
        if ($valore === null || $valore === '') {
            return null;
        }

        if ($valore instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($valore)->startOfDay();
        }

        if (is_int($valore) || is_float($valore) || (is_string($valore) && preg_match('/^\d{5}(\.\d+)?$/', trim($valore)))) {
            $seriale = (float) $valore;

            // Un seriale di Excel fra il 1990 e il 2100: fuori da lì non è una data.
            if ($seriale > 32874 && $seriale < 73051) {
                return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject($seriale))->startOfDay();
            }

            return null;
        }

        $s = trim((string) $valore);

        if ($formato !== 'auto') {
            // Solo la parte della data: «31/01/2026 10:15» resta «31/01/2026».
            $soloData = explode(' ', $s)[0];

            try {
                $d = CarbonImmutable::createFromFormat('!'.$formato, $soloData);
            } catch (\Throwable) {
                $d = false;
            }

            if ($d) {
                return $d->startOfDay();
            }
        }

        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})/', $s, $m)) {
            $anno = (int) $m[3];
            $anno = $anno < 100 ? 2000 + $anno : $anno;

            return checkdate((int) $m[2], (int) $m[1], $anno)
                ? CarbonImmutable::create($anno, (int) $m[2], (int) $m[1])->startOfDay()
                : null;
        }

        if (preg_match('/^(\d{4})-?(\d{2})-?(\d{2})/', $s, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
                ? CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3])->startOfDay()
                : null;
        }

        return null;
    }

    /**
     * Quanto un nome di anagrafica (o di fornitore) compare in un testo, da 0 a 100.
     *
     * Conta le parole del nome lunghe almeno 3 lettere («Rossi Mario» → rossi, mario) presenti
     * come parole intere nel testo. Tutte presenti = 100. Un nome di una sola parola trovata vale
     * meno, perché «Rossi» da solo può essere chiunque.
     */
    public static function somiglianzaNome(string $nome, string $testo): int
    {
        $parole = array_values(array_filter(
            explode(' ', self::normalizza($nome)),
            fn ($p) => strlen($p) >= 3 && ! in_array($p, self::PAROLE_VUOTE, true)
        ));

        if ($parole === []) {
            return 0;
        }

        $nelTesto = array_flip(explode(' ', self::normalizza($testo)));
        $trovate = count(array_filter($parole, fn ($p) => isset($nelTesto[$p])));

        if ($trovate === 0) {
            return 0;
        }

        $quota = (int) round($trovate / count($parole) * 100);

        return count($parole) === 1 ? min($quota, 60) : $quota;
    }

    /** Parole che non identificano nessuno: forme societarie, titoli, preposizioni. */
    private const PAROLE_VUOTE = [
        'srl', 'spa', 'snc', 'sas', 'srls', 'coop', 'soc', 'societa', 'ditta', 'studio',
        'del', 'della', 'dei', 'degli', 'delle', 'per', 'con', 'and', 'sig', 'sigra', 'dott',
        'condominio', 'via', 'piazza',
    ];
}
