<?php

namespace App\Services\IA;

use App\Models\UtilizzoIA;
use App\Settings\IASettings;
use Illuminate\Support\Carbon;

/**
 * Spesa dell'IA: stima del costo di una chiamata, totale del mese, rispetto del tetto e registro.
 *
 * I costi sono in milionesimi di dollaro (interi). Il tetto è in centesimi di dollaro.
 * La stima usa il listino di config/ia.php: la cifra vera è quella della Console Anthropic.
 */
class RegistroUtilizzoIA
{
    public function __construct(private readonly IASettings $settings) {}

    /** Costo stimato in milionesimi di dollaro. */
    public function costo(string $modello, int $tokenIngresso, int $tokenUscita): int
    {
        $prezzi = config("ia.prezzi.$modello") ?? config('ia.prezzo_sconosciuto');

        $prezzoIngresso = (float) $prezzi['ingresso'];
        $prezzoUscita = (float) $prezzi['uscita'];

        if (isset($prezzi['soglia_prompt_lungo']) && $tokenIngresso > (int) $prezzi['soglia_prompt_lungo']) {
            $prezzoIngresso = (float) $prezzi['ingresso_lungo'];
            $prezzoUscita = (float) $prezzi['uscita_lunga'];
        }

        // prezzo ($/milione di token) × token = milionesimi di dollaro
        return (int) ceil($prezzoIngresso * $tokenIngresso + $prezzoUscita * $tokenUscita);
    }

    /** Spesa stimata del mese solare in corso, in milionesimi di dollaro. */
    public function spesaMese(?Carbon $quando = null): int
    {
        $quando ??= now();

        return (int) UtilizzoIA::query()
            ->where('created_at', '>=', $quando->copy()->startOfMonth())
            ->where('created_at', '<=', $quando->copy()->endOfMonth())
            ->sum('costo_microdollari');
    }

    public function tettoMicrodollari(): ?int
    {
        $centesimi = $this->settings->tetto_spesa_mensile_centesimi;

        return $centesimi === null ? null : $centesimi * 10_000;
    }

    /** Il tetto è già stato raggiunto: nessuna nuova chiamata fino al mese prossimo. */
    public function tettoRaggiunto(): bool
    {
        $tetto = $this->tettoMicrodollari();

        return $tetto !== null && $this->spesaMese() >= $tetto;
    }

    /**
     * La chiamata, nel caso peggiore (tutti i token d'uscita consumati), farebbe superare il tetto?
     * Si confronta con il massimo e non con la media: meglio fermarsi un centesimo prima.
     */
    public function superaTetto(string $modello, int $tokenIngressoStimati, int $maxTokenUscita): bool
    {
        $tetto = $this->tettoMicrodollari();

        if ($tetto === null) {
            return false;
        }

        return $this->spesaMese() + $this->costo($modello, $tokenIngressoStimati, $maxTokenUscita) > $tetto;
    }

    /** @param  array<string, mixed>  $dati  Solo metadati: mai testi. */
    public function registra(array $dati): UtilizzoIA
    {
        $dati['costo_microdollari'] ??= $this->costo(
            (string) $dati['modello'],
            (int) ($dati['token_ingresso'] ?? 0),
            (int) ($dati['token_uscita'] ?? 0),
        );
        $dati['user_id'] ??= auth()->id();

        return UtilizzoIA::create($dati);
    }

    /** Stima grossolana dei token di un testo (circa 3,5 caratteri per token in italiano). */
    public static function stimaToken(string $testo): int
    {
        return (int) ceil(mb_strlen($testo) / 3.5);
    }
}
