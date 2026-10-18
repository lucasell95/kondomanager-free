<?php

namespace App\Enums\Assemblee;

enum Convocazione: string
{
    case PRIMA = 'prima';
    case SECONDA = 'seconda';

    public function label(): string
    {
        return match ($this) {
            self::PRIMA => 'Prima convocazione',
            self::SECONDA => 'Seconda convocazione',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
