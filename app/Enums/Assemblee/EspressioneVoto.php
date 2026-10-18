<?php

namespace App\Enums\Assemblee;

enum EspressioneVoto: string
{
    case FAVOREVOLE = 'favorevole';
    case CONTRARIO = 'contrario';
    case ASTENUTO = 'astenuto';

    public function label(): string
    {
        return match ($this) {
            self::FAVOREVOLE => 'Favorevole',
            self::CONTRARIO => 'Contrario',
            self::ASTENUTO => 'Astenuto',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
