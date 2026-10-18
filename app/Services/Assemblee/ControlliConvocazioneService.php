<?php

namespace App\Services\Assemblee;

use App\Enums\Assemblee\MezzoConvocazione;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\DestinatarioConvocazione;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Controlli dei termini e della convocazione.
 *
 * - **Seconda convocazione** (art. 1136 c. 3 c.c., art. 66 c. 4 disp. att.): in un giorno successivo
 *   a quello della prima, e non oltre dieci giorni dalla prima. Sono errori bloccanti: si correggono
 *   cambiando una data.
 * - **Preavviso** (art. 66 c. 3 disp. att.): l'avviso va **ricevuto** almeno cinque giorni prima della
 *   prima convocazione. Si conta a ritroso escludendo il giorno di ricezione: ricevuto il 15 per
 *   un'assemblea il 20 è nel termine. Con il computo «a giorni liberi», più prudente, servirebbe un
 *   giorno in più: per questo i cinque giorni esatti sono segnalati come «al limite». Sono avvisi,
 *   non blocchi: il difetto rende la delibera annullabile, e la decisione resta all'utente.
 * - **Mezzo**: l'e-mail ordinaria non è valida (Cass. 16399/2025).
 */
class ControlliConvocazioneService
{
    public const GIORNI_PREAVVISO = 5;
    public const GIORNI_MAX_SECONDA = 10;

    /** @return list<string> errori sulle date delle convocazioni */
    public function erroriDate(?CarbonInterface $prima, ?CarbonInterface $seconda): array
    {
        if (! $prima || ! $seconda) {
            return [];
        }

        $giorni = (int) Carbon::parse($prima)->startOfDay()->diffInDays(Carbon::parse($seconda)->startOfDay(), false);

        if ($giorni < 0) {
            return ['La seconda convocazione non può essere fissata prima della prima.'];
        }

        if ($giorni === 0) {
            return ['La seconda convocazione non può tenersi nello stesso giorno della prima (art. 66 c. 4 disp. att. c.c.).'];
        }

        if ($giorni > self::GIORNI_MAX_SECONDA) {
            return ["La seconda convocazione deve tenersi entro dieci giorni dalla prima (art. 1136 c. 3 c.c.): ora sono {$giorni} giorni."];
        }

        return [];
    }

    /**
     * Esito della ricezione dell'avviso per un destinatario.
     *
     * @return array{codice:string, livello:string, messaggio:string, giorni:?int}
     */
    public function statoRicezione(DestinatarioConvocazione $d, ?CarbonInterface $prima): array
    {
        $mezzo = $d->mezzo instanceof MezzoConvocazione ? $d->mezzo : MezzoConvocazione::tryFrom((string) $d->mezzo);

        if ($mezzo && ! $mezzo->valido()) {
            return [
                'codice' => 'mezzo_non_valido',
                'livello' => 'error',
                'messaggio' => 'L\'e-mail ordinaria non è un mezzo valido di convocazione (art. 66 c. 3 disp. att.; Cass. 16399/2025): usare PEC, raccomandata o consegna a mano.',
                'giorni' => null,
            ];
        }

        if (! $d->data_ricezione || ! $prima) {
            return [
                'codice' => 'ricezione_mancante',
                'livello' => 'warning',
                'messaggio' => 'Data di ricezione non registrata: senza prova della ricezione il termine non è verificabile.',
                'giorni' => null,
            ];
        }

        $giorni = (int) Carbon::parse($d->data_ricezione)->startOfDay()->diffInDays(Carbon::parse($prima)->startOfDay(), false);

        if ($giorni < self::GIORNI_PREAVVISO) {
            return [
                'codice' => 'termine_non_rispettato',
                'livello' => 'error',
                'messaggio' => "Ricevuto {$giorni} giorni prima della prima convocazione: servono almeno cinque giorni (art. 66 c. 3 disp. att.). La delibera sarebbe annullabile.",
                'giorni' => $giorni,
            ];
        }

        if ($giorni === self::GIORNI_PREAVVISO) {
            return [
                'codice' => 'termine_al_limite',
                'livello' => 'warning',
                'messaggio' => 'Ricevuto esattamente cinque giorni prima: termine rispettato contando a ritroso escluso il giorno di ricezione, ma al limite (con il computo a giorni liberi ne servirebbe uno in più).',
                'giorni' => $giorni,
            ];
        }

        return [
            'codice' => 'termine_rispettato',
            'livello' => 'ok',
            'messaggio' => "Ricevuto {$giorni} giorni prima della prima convocazione: termine rispettato.",
            'giorni' => $giorni,
        ];
    }

    /**
     * Tutti i controlli dell'assemblea, per la scheda e per il verbale.
     *
     * @return list<array{livello:string, messaggio:string}>
     */
    public function controlli(Assemblea $assemblea, ?int $totaleValore = null): array
    {
        $avvisi = [];
        $aggiungi = function (string $livello, string $messaggio) use (&$avvisi) {
            $avvisi[] = ['livello' => $livello, 'messaggio' => $messaggio];
        };

        foreach ($this->erroriDate($assemblea->prima_convocazione_at, $assemblea->seconda_convocazione_at) as $e) {
            $aggiungi('error', $e);
        }

        if (! $assemblea->seconda_convocazione_at) {
            $aggiungi('info', 'Nessuna seconda convocazione: se la prima va deserta servirà un nuovo avviso.');
        }

        if (! $assemblea->tabella_id) {
            $aggiungi('error', 'Nessuna tabella millesimale di proprietà generale: i quorum sul valore non si possono calcolare.');
        } elseif ($totaleValore !== null && $totaleValore !== 1000 * \App\Support\Assemblee\Millesimi::SCALA) {
            $aggiungi('info', 'Il totale della tabella non è 1000: i quorum si calcolano sul totale effettivo di '
                .\App\Support\Assemblee\Millesimi::formatta($totaleValore).'.');
        }

        if ($assemblea->videoconferenza && ! $assemblea->consenso_video_acquisito) {
            $aggiungi('warning', 'Videoconferenza: serve il consenso preventivo della maggioranza dei condòmini (art. 66 c. 6 disp. att.). Registrarlo prima di convocare.');
        }

        if ($assemblea->videoconferenza && (blank($assemblea->piattaforma_video) || blank($assemblea->link_video))) {
            $aggiungi('warning', 'Videoconferenza: l\'avviso deve indicare la piattaforma e il collegamento.');
        }

        if (blank($assemblea->prima_luogo) && ! $assemblea->videoconferenza) {
            $aggiungi('warning', 'Manca il luogo della prima convocazione.');
        }

        $punti = $assemblea->relationLoaded('punti') ? $assemblea->punti : $assemblea->punti()->get();

        if ($punti->isEmpty()) {
            $aggiungi('warning', 'L\'ordine del giorno è vuoto: l\'avviso deve indicare in modo specifico gli argomenti (art. 66 c. 3 disp. att.).');
        }

        foreach ($punti as $p) {
            if (preg_match('/\bvarie\b.*\beventuali\b/iu', $p->titolo.' '.$p->testo)) {
                $aggiungi('warning', "Punto «{$p->titolo}»: con «varie ed eventuali» non si possono deliberare argomenti non indicati, specie se comportano spese (Trib. Roma 14537/2020).");
            }
            if ($p->materia?->avvertenza()) {
                $aggiungi('info', "Punto «{$p->titolo}»: ".$p->materia->avvertenza());
            }
        }

        $destinatari = $assemblea->relationLoaded('destinatari') ? $assemblea->destinatari : $assemblea->destinatari()->get();

        if ($destinatari->isEmpty()) {
            $aggiungi('warning', 'Nessun destinatario della convocazione registrato.');
        }

        foreach ($destinatari as $d) {
            $stato = $this->statoRicezione($d, $assemblea->prima_convocazione_at);
            if ($stato['livello'] === 'error' || $stato['livello'] === 'warning') {
                $aggiungi($stato['livello'], $d->nominativo.': '.$stato['messaggio']);
            }
        }

        if (! $assemblea->amministratore_anagrafica_id && blank($assemblea->amministratore_nome)) {
            $aggiungi('info', 'Amministratore non indicato: il programma non può bloccare le deleghe a lui conferite (art. 67 c. 5 disp. att.).');
        }

        return $avvisi;
    }
}
