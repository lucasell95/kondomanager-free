<?php

namespace App\Settings;

use Illuminate\Support\Facades\Crypt;
use Spatie\LaravelSettings\Settings;

/**
 * Impostazioni dell'assistenza IA per le bozze dei documenti.
 *
 * La chiave API è salvata cifrata con `Crypt::encryptString()` (come la password SMTP in
 * MailSettings) e non va mai inviata al browser, nemmeno cifrata: la pagina riceve solo
 * `chiave_impostata` e le ultime quattro cifre.
 *
 * Il tetto di spesa è in centesimi di dollaro, perché Anthropic fattura in dollari.
 */
class IASettings extends Settings
{
    public bool $ia_abilitata = false;

    public ?string $api_key = null;

    public string $modello = 'claude-sonnet-5-5';

    public ?int $tetto_spesa_mensile_centesimi = null;

    /** Data e ora in cui l'amministratore ha confermato informativa e autorizzazione dell'assemblea. */
    public ?string $informativa_confermata_il = null;

    public static function group(): string
    {
        return 'ia';
    }

    /**
     * La chiave in chiaro, o null se manca o non si riesce a decifrare (per esempio dopo un
     * ripristino su un'installazione con APP_KEY diversa: va reinserita).
     */
    public function chiaveInChiaro(): ?string
    {
        if (empty($this->api_key)) {
            return null;
        }

        try {
            $chiave = Crypt::decryptString($this->api_key);
        } catch (\Throwable) {
            return null;
        }

        return $chiave !== '' ? $chiave : null;
    }

    /** L'IA si può usare: attivata e con una chiave leggibile. */
    public function operativa(): bool
    {
        return $this->ia_abilitata && $this->chiaveInChiaro() !== null;
    }
}
