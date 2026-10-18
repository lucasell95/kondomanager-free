<?php

namespace App\Enums\Assemblee;

enum TipoAssemblea: string
{
    case ORDINARIA = 'ordinaria';
    case STRAORDINARIA = 'straordinaria';

    public function label(): string
    {
        return match ($this) {
            self::ORDINARIA => 'Ordinaria',
            self::STRAORDINARIA => 'Straordinaria',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
