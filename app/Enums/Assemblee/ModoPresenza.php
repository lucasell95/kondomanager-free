<?php

namespace App\Enums\Assemblee;

enum ModoPresenza: string
{
    case PRESENTE = 'presente';
    case DELEGATO = 'delegato';
    case ASSENTE = 'assente';

    public function label(): string
    {
        return match ($this) {
            self::PRESENTE => 'Presente',
            self::DELEGATO => 'Per delega',
            self::ASSENTE => 'Assente',
        };
    }

    /** Presente in proprio o per delega: conta come intervenuto. */
    public function intervenuto(): bool
    {
        return $this !== self::ASSENTE;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
