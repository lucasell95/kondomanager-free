<?php

namespace App\Enums\Assemblee;

/**
 * Il ciclo di vita di un'assemblea.
 *
 * - `bozza`: la prepara l'amministratore, i condòmini non la vedono;
 * - `convocata`: l'avviso è partito, i condòmini la vedono in portale e in agenda;
 * - `svolta`: presenze e votazioni registrate, verbale in preparazione;
 * - `verbalizzata`: il verbale ha un numero di registro ed è archiviato. Non si modifica più,
 *   tranne la comunicazione del verbale agli assenti (art. 1137 c.c.).
 */
enum StatoAssemblea: string
{
    case BOZZA = 'bozza';
    case CONVOCATA = 'convocata';
    case SVOLTA = 'svolta';
    case VERBALIZZATA = 'verbalizzata';

    public function label(): string
    {
        return match ($this) {
            self::BOZZA => 'Bozza',
            self::CONVOCATA => 'Convocata',
            self::SVOLTA => 'Svolta',
            self::VERBALIZZATA => 'Verbalizzata',
        };
    }

    /** Gli stati verso cui si può passare da questo. */
    public function transizioniAmmesse(): array
    {
        return match ($this) {
            self::BOZZA => [self::CONVOCATA],
            self::CONVOCATA => [self::BOZZA, self::SVOLTA],
            self::SVOLTA => [self::CONVOCATA, self::VERBALIZZATA],
            self::VERBALIZZATA => [],
        };
    }

    public function puoPassareA(self $verso): bool
    {
        return in_array($verso, $this->transizioniAmmesse(), true);
    }

    /** Dati, ordine del giorno e destinatari si modificano fino allo svolgimento. */
    public function datiModificabili(): bool
    {
        return in_array($this, [self::BOZZA, self::CONVOCATA], true);
    }

    /** Presenze, deleghe e voti si registrano il giorno dell'assemblea e finché il verbale non è chiuso. */
    public function presenzeModificabili(): bool
    {
        return in_array($this, [self::CONVOCATA, self::SVOLTA], true);
    }

    /** Il condòmino vede l'assemblea solo dopo la convocazione. */
    public function visibileAiCondomini(): bool
    {
        return $this !== self::BOZZA;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
