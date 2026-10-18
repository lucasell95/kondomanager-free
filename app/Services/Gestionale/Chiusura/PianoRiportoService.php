<?php

namespace App\Services\Gestionale\Chiusura;

/**
 * Che cosa diventa il residuo di un condòmino, data la scelta fatta per la sua gestione.
 * **Funzione pura**: la usano l'anteprima e l'esecuzione, così il numero mostrato è quello
 * che verrà scritto.
 *
 * ## Le due modalità
 *
 *  - **riporta** (consigliata per l'ordinaria): le quote emesse ancora da pagare si chiudono
 *    con una scrittura di riporto nel nuovo esercizio e il loro importo diventa saldo iniziale
 *    LIBERO della gestione di destinazione. Il nuovo piano rate lo assorbe (Rata 0 o spalmato
 *    sulle rate), come qualunque saldo iniziale. Le vecchie quote risultano chiuse «per riporto»
 *    e non compaiono più fra le rate da incassare: il debito si paga una volta sola, sulla
 *    nuova rata.
 *  - **mantieni**: le quote restano aperte e si incassano dove sono; nel nuovo esercizio il
 *    residuo compare come saldo iniziale BLOCCATO (nessun piano lo assorbe), perché l'estratto
 *    conto del nuovo esercizio parte da lì. È la scelta naturale per una straordinaria che
 *    prosegue con le sue rate.
 *
 * ## Le parti che non dipendono dalla modalità
 *
 *  - Il **credito sulle quote** resta sempre sulle quote: è lì che `CreditoService` lo propone
 *    in compensazione, anche fra gestioni. Portarlo in una Rata 0 a credito lo farebbe uscire
 *    dal giornale (le quote ≤ 0 non si emettono, codice.md B15).
 *  - I **movimenti senza rata** (anticipi, conguagli senza quota) non hanno una quota su cui
 *    restare: si riportano se la gestione prosegue.
 *  - I **saldi iniziali mai assorbiti** passano alla nuova ordinaria (il wallet è per gestione,
 *    e la vecchia ordinaria non avrà più piani). Per una straordinaria che prosegue restano nel
 *    suo wallet, dove un prossimo piano li può ancora assorbire.
 *
 * `destinazione`: `nuova` (la nuova ordinaria), `stessa` (straordinaria riagganciata),
 * `null` (gestione conclusa: niente prosegue, tutto resta dov'è).
 */
class PianoRiportoService
{
    public const RIPORTA  = 'riporta';
    public const MANTIENI = 'mantieni';

    /**
     * @param  array  $residuo  una riga di `ResiduiEsercizioService::calcola()`
     * @return array{riporta_quote: bool, riporta_extra: bool, riporta_saldi_liberi: bool, riportato: int, mantenuto: int, differenza: int}
     */
    public function ripartisci(array $residuo, string $modalita, ?string $destinazione): array
    {
        $riportaQuote = $modalita === self::RIPORTA && $destinazione !== null;
        $riportaExtra = $destinazione !== null;
        $riportaSaldiLiberi = $destinazione === 'nuova';

        $riportato = ($riportaQuote ? $residuo['debito_quote'] : 0)
            + ($riportaExtra ? $residuo['extra_totale'] : 0)
            + ($riportaSaldiLiberi ? $residuo['saldi_liberi_totale'] : 0);

        // Un saldo senza intestatario (solidale) non entra nell'estratto conto di nessuno: non
        // c'è niente da far tornare, quindi nessuna parte «mantenuta».
        $mantenuto = $residuo['anagrafica_id'] ? $residuo['residuo'] - $riportato : 0;

        // Quello che dovrebbe restare: il credito sulle quote, più le quote, i movimenti e i saldi
        // che questa modalità lascia dove sono. Se il mantenuto è diverso, quote e giornale non
        // raccontano la stessa cosa e l'anteprima lo dice.
        $atteso = -$residuo['credito_quote'] + $residuo['non_emesse']
            + ($riportaQuote ? 0 : $residuo['debito_quote'])
            + ($riportaExtra ? 0 : $residuo['extra_totale'])
            + ($riportaSaldiLiberi ? 0 : $residuo['saldi_liberi_totale']);

        return [
            'riporta_quote'        => $riportaQuote,
            'riporta_extra'        => $riportaExtra,
            'riporta_saldi_liberi' => $riportaSaldiLiberi,
            'riportato'            => $riportato,
            'mantenuto'            => $mantenuto,
            'differenza'           => $residuo['anagrafica_id'] ? $mantenuto - $atteso : 0,
        ];
    }

    /** La destinazione di una gestione, dato il suo tipo e se l'amministratore la riapre. */
    public function destinazione(string $tipo, bool $riapri): ?string
    {
        if ($tipo === 'ordinaria') {
            return 'nuova';
        }

        return $riapri ? 'stessa' : null;
    }
}
