<?php

namespace App\Helpers;

use Illuminate\Support\Carbon;

class DateHelper
{
    /**
     * La data di "oggi" nel fuso in cui vive l'amministratore.
     *
     * I timestamp dell'applicazione restano in UTC (config/app.php `timezone`), ma le
     * validazioni sulle date digitate a mano — "non può essere futura" — devono usare
     * il calendario dell'utente, non quello del server.
     *
     * Senza questa distinzione, un amministratore italiano che registra un pagamento
     * alle 00:30 sceglie dal date-picker la data di oggi (ora locale) e se la vede
     * respingere: in UTC sono ancora le 22:30 del giorno prima. La finestra è di 1-2
     * ore per notte, ma l'errore è incomprensibile per chi lo riceve.
     *
     * Restituisce una stringa Y-m-d, pronta per `before_or_equal:`.
     */
    public static function oggiUtente(): string
    {
        return self::adessoUtente()->toDateString();
    }

    /**
     * L'istante attuale espresso nel fuso dell'utente, per ciò che si **mostra** o si
     * **stampa** ("Data di stampa: 18/10/2026 22:50") e per ricavarne la data contabile di
     * oggi.
     *
     * Non va usato per scrivere timestamp (`created_at`, orari degli eventi): quelli restano
     * in UTC come il resto dell'applicazione. È un `Carbon` con il fuso impostato, quindi
     * `format()` restituisce l'ora italiana; lo stesso istante in UTC è `->utc()`.
     */
    public static function adessoUtente(): Carbon
    {
        return Carbon::now(self::fusoUtente());
    }

    /** Il fuso orario dell'utente (`APP_USER_TIMEZONE`, default Europe/Rome). */
    public static function fusoUtente(): string
    {
        return (string) (config('app.user_timezone') ?: 'Europe/Rome');
    }

    /**
     * Un timestamp salvato (in UTC) convertito nel fuso dell'utente, per mostrarlo.
     * `null` resta `null`.
     */
    public static function inFusoUtente(?\DateTimeInterface $istante): ?Carbon
    {
        return $istante === null ? null : Carbon::instance($istante)->setTimezone(self::fusoUtente());
    }
}
