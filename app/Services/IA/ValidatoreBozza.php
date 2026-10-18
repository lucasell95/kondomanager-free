<?php

namespace App\Services\IA;

/**
 * Controlli automatici sulla bozza restituita dall'IA, prima che una persona la riveda.
 *
 * Lavora sul testo **ancora pseudonimizzato** (con i codici `[PERSONA_1]`…), perché un indirizzo
 * ricomposto contiene cifre legittime (il numero civico) che qui darebbero falsi allarmi.
 *
 * **Errori gravi** (la bozza non è utilizzabile, `ok = false`):
 * - mancano campi obbligatori dello schema;
 * - il numero (o la numerazione) dei punti è diverso da quello richiesto;
 * - compare un segnaposto `{{F:chiave}}` la cui chiave non esiste in `contesto.fatti`, o un
 *   segnaposto scritto male (`{{chiave}}`) che il programma non saprebbe sostituire;
 * - compare un codice di persona, azienda o indirizzo che non è stato inviato (persona inventata).
 *
 * **Avvisi** (la bozza va riletta con attenzione in quei punti):
 * - cifre fuori dai segnaposto: date e importi devono arrivare solo da `{{F:…}}`. Sono ammessi
 *   i riferimenti normativi presenti in `contesto.norme_ammesse` e i rimandi «punto N»;
 * - riferimenti a articoli di legge non forniti nel contesto;
 * - parti da completare (`[[DA COMPLETARE: …]]`) o dati rimossi dalla pseudonimizzazione;
 * - nel verbale, parole che anticipano l'esito delle votazioni (lo scrive il programma).
 */
class ValidatoreBozza
{
    private const SEGNAPOSTO = '/\{\{F:([A-Za-z0-9_.\-]+)\}\}/u';

    private const SEGNAPOSTO_MALFORMATO = '/\{\{(?!F:[A-Za-z0-9_.\-]+\}\})[^{}]*\}\}/u';

    private const CODICE = '/\[(?:PERSONA|AZIENDA|INDIRIZZO|CONDOMINIO)_\d+\]/u';

    private const DA_COMPLETARE = '/\[\[DA COMPLETARE:?([^\]]*)\]\]/u';

    private const ESITI_VIETATI = "/(?<![\p{L}])(approvat[aoie]|respint[aoie]|all'unanimità|a maggioranza|non approvat[aoie])(?![\p{L}])/iu";

    /**
     * @param  array<string, mixed>  $dati  Output dell'IA (pseudonimizzato).
     * @param  array<string, mixed>  $contesto  Contesto così come è stato inviato.
     * @param  array<string, mixed>  $schema  Schema richiesto.
     * @param  list<string>  $codiciAmmessi  Codici di persona/azienda/indirizzo presenti nel contesto.
     */
    public function valida(string $tipoDocumento, array $dati, array $contesto, array $schema = [], array $codiciAmmessi = []): EsitoValidazione
    {
        $errori = [];
        $avvisi = [];

        foreach ((array) ($schema['required'] ?? []) as $campo) {
            if (! array_key_exists($campo, $dati)) {
                $errori[] = "manca il campo obbligatorio «{$campo}».";
            }
        }

        [$attesi, $numeriAttesi] = $this->puntiDelContesto($contesto);
        $ottenuti = $dati['punti'] ?? $dati['ordine_del_giorno'] ?? null;

        if ($attesi !== null && is_array($ottenuti)) {
            if (count($ottenuti) !== $attesi) {
                $errori[] = sprintf('la bozza ha %d punti invece dei %d richiesti.', count($ottenuti), $attesi);
            } elseif ($numeriAttesi !== null) {
                $numeriOttenuti = array_map(fn ($p) => is_array($p) ? ($p['n'] ?? $p['numero'] ?? null) : null, array_values($ottenuti));
                if (! in_array(null, $numeriOttenuti, true) && array_map('intval', $numeriOttenuti) !== $numeriAttesi) {
                    $errori[] = 'la numerazione dei punti non corrisponde a quella richiesta.';
                }
            }
        }

        $fatti = is_array($contesto['fatti'] ?? null) ? $contesto['fatti'] : [];
        $norme = array_values(array_filter((array) ($contesto['norme_ammesse'] ?? []), 'is_string'));
        usort($norme, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $numeroPunti = $attesi ?? (is_array($ottenuti) ? count($ottenuti) : 0);

        foreach ($this->stringhe($dati) as $percorso => $testo) {
            $this->controllaTesto($tipoDocumento, $percorso, $testo, $fatti, $norme, $numeroPunti, $codiciAmmessi, $errori, $avvisi);
        }

        return new EsitoValidazione(array_values(array_unique($errori)), array_values(array_unique($avvisi)));
    }

    private function controllaTesto(
        string $tipo, string $percorso, string $testo, array $fatti, array $norme, int $numeroPunti,
        array $codiciAmmessi, array &$errori, array &$avvisi,
    ): void {
        preg_match_all(self::SEGNAPOSTO, $testo, $m);
        foreach (array_unique($m[1]) as $chiave) {
            if (! array_key_exists($chiave, $fatti)) {
                $errori[] = "in «{$percorso}» il segnaposto {{F:{$chiave}}} non corrisponde a nessun dato fornito.";
            }
        }

        if (preg_match_all(self::SEGNAPOSTO_MALFORMATO, $testo, $m)) {
            $errori[] = "in «{$percorso}» c'è un segnaposto scritto male: ".implode(', ', array_unique($m[0])).'.';
        }

        preg_match_all(self::CODICE, $testo, $m);
        foreach (array_unique($m[0]) as $codice) {
            if (! in_array($codice, $codiciAmmessi, true)) {
                $errori[] = "in «{$percorso}» compare {$codice}, che non corrisponde a nessun soggetto fornito.";
            }
        }

        foreach (Pseudonimizzatore::MARCATORI_RIMOSSI as $marcatore) {
            if (str_contains($testo, '['.$marcatore.']')) {
                $avvisi[] = "in «{$percorso}» c'è un dato personale rimosso prima dell'invio ([{$marcatore}]): completalo a mano se serve.";
            }
        }

        if (preg_match(self::DA_COMPLETARE, $testo)) {
            $avvisi[] = "in «{$percorso}» ci sono parti da completare ([[DA COMPLETARE]]).";
        }

        if ($tipo === 'verbale' && preg_match(self::ESITI_VIETATI, $testo, $m)) {
            $avvisi[] = "in «{$percorso}» compare «{$m[1]}»: l'esito delle votazioni lo inserisce il programma, non la bozza.";
        }

        // Cifre: si tolgono prima segnaposto, codici, marcatori, norme ammesse e rimandi ai punti.
        $pulito = preg_replace([self::SEGNAPOSTO, self::CODICE, self::DA_COMPLETARE], ' ', $testo);
        foreach ($norme as $norma) {
            $pulito = preg_replace('/'.preg_quote($norma, '/').'/iu', ' ', $pulito);
        }
        $pulito = preg_replace_callback(
            '/\bpunt[oi]\s+(?:n\.?\s*)?(\d+)((?:\s*(?:,|e)\s*\d+)*)/iu',
            function (array $p) use ($numeroPunti) {
                preg_match_all('/\d+/', $p[0], $numeri);
                foreach ($numeri[0] as $n) {
                    if ((int) $n < 1 || (int) $n > $numeroPunti) {
                        return $p[0];
                    }
                }

                return ' ';
            },
            $pulito,
        );

        if (preg_match('/\bart(?:icol[oi])?\.?\s*\d+/iu', $pulito, $m)) {
            $avvisi[] = "in «{$percorso}» c'è un riferimento normativo («{$m[0]}») che non era tra quelli forniti.";
        }

        if (preg_match('/\d/u', $pulito)) {
            $avvisi[] = "in «{$percorso}» ci sono cifre fuori dai segnaposto («".$this->estratto($pulito)."»): date e importi devono venire dai dati del programma.";
        }
    }

    /**
     * Numero e numerazione dei punti richiesti, se il contesto ne contiene un elenco.
     *
     * @return array{0: ?int, 1: ?list<int>}
     */
    private function puntiDelContesto(array $contesto): array
    {
        $punti = $contesto['punti'] ?? $contesto['assemblea']['punti'] ?? $contesto['ordine_del_giorno'] ?? null;

        if (! is_array($punti)) {
            return [null, null];
        }

        $numeri = array_map(fn ($p) => is_array($p) ? ($p['n'] ?? $p['numero'] ?? null) : null, array_values($punti));

        return [count($punti), in_array(null, $numeri, true) ? null : array_map('intval', $numeri)];
    }

    /** @return array<string, string> percorso puntato → stringa */
    private function stringhe(array $dati, string $prefisso = ''): array
    {
        $risultato = [];

        foreach ($dati as $chiave => $valore) {
            $percorso = $prefisso === '' ? (string) $chiave : $prefisso.'.'.$chiave;

            if (is_string($valore)) {
                $risultato[$percorso] = $valore;
            } elseif (is_array($valore)) {
                $risultato += $this->stringhe($valore, $percorso);
            }
        }

        return $risultato;
    }

    private function estratto(string $testo): string
    {
        $testo = trim(preg_replace('/\s+/u', ' ', $testo));
        preg_match('/\d/u', $testo, $m, PREG_OFFSET_CAPTURE);
        // L'offset di preg è in byte: lo si converte in caratteri per non spezzare le lettere accentate.
        $posizione = mb_strlen(substr($testo, 0, $m[0][1] ?? 0));
        $inizio = max(0, $posizione - 25);
        $parte = trim(mb_substr($testo, $inizio, 60));

        return ($inizio > 0 ? '…' : '').$parte.($inizio + 60 < mb_strlen($testo) ? '…' : '');
    }
}
