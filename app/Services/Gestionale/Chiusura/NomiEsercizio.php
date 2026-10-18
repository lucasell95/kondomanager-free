<?php

namespace App\Services\Gestionale\Chiusura;

use Carbon\CarbonInterface;

/**
 * Nomi e serie proposti per il nuovo esercizio e le sue gestioni.
 */
class NomiEsercizio
{
    /** «2027/28» per un esercizio a cavallo d'anno, «2027» per uno sull'anno solare. */
    public static function serie(CarbonInterface $inizio, CarbonInterface $fine): string
    {
        return $inizio->year === $fine->year
            ? (string) $inizio->year
            : $inizio->year.'/'.substr((string) $fine->year, -2);
    }

    /** «Esercizio 2027/2028» o «Esercizio 2027». */
    public static function nomeEsercizio(CarbonInterface $inizio, CarbonInterface $fine): string
    {
        return $inizio->year === $fine->year
            ? "Esercizio {$inizio->year}"
            : "Esercizio {$inizio->year}/{$fine->year}";
    }

    public static function descrizione(CarbonInterface $inizio, CarbonInterface $fine): string
    {
        return 'Dal '.$inizio->format('d/m/Y').' al '.$fine->format('d/m/Y');
    }

    /**
     * Il nome di una gestione, spostato sul nuovo esercizio: «Gestione ordinaria 2026/27» →
     * «Gestione ordinaria 2027/28». Se il nome non porta anni, si aggiunge la serie nuova:
     * due gestioni con lo stesso nome in esercizi diversi si confonderebbero negli elenchi.
     */
    public static function rinomina(string $nome, CarbonInterface $vecchioInizio, CarbonInterface $vecchiaFine,
        CarbonInterface $nuovoInizio, CarbonInterface $nuovaFine): string
    {
        [$v1, $v2, $n1, $n2] = [(string) $vecchioInizio->year, (string) $vecchiaFine->year, (string) $nuovoInizio->year, (string) $nuovaFine->year];

        $sostituzioni = [];
        foreach (['/', '-'] as $sep) {
            $sostituzioni["{$v1}{$sep}{$v2}"] = "{$n1}{$sep}{$n2}";
            $sostituzioni["{$v1}{$sep}".substr($v2, -2)] = "{$n1}{$sep}".substr($n2, -2);
        }

        foreach ($sostituzioni as $da => $a) {
            if (str_contains($nome, $da)) {
                return str_replace($da, $a, $nome);
            }
        }

        if ($v1 === $v2 && preg_match('/\b'.$v1.'\b/', $nome)) {
            return preg_replace('/\b'.$v1.'\b/', $n1, $nome);
        }

        return trim($nome).' '.self::serie($nuovoInizio, $nuovaFine);
    }
}
