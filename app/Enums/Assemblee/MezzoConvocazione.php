<?php

namespace App\Enums\Assemblee;

/**
 * Con quale mezzo è stato recapitato l'avviso di convocazione (o il verbale).
 *
 * Art. 66 c. 3 disp. att. c.c.: posta raccomandata, PEC, fax o consegna a mano. L'e-mail ordinaria
 * **non** è un mezzo valido (Cass. 16399/2025) e il regolamento non può derogare (art. 72 disp. att.):
 * si può registrare, perché capita di mandarla come copia informativa, ma il programma la segnala.
 */
enum MezzoConvocazione: string
{
    case PEC = 'pec';
    case RACCOMANDATA = 'raccomandata';
    case MANO = 'mano';
    case FAX = 'fax';
    case EMAIL = 'email';

    public function label(): string
    {
        return match ($this) {
            self::PEC => 'PEC',
            self::RACCOMANDATA => 'Raccomandata A/R',
            self::MANO => 'Consegna a mano',
            self::FAX => 'Fax',
            self::EMAIL => 'E-mail ordinaria (non valida)',
        };
    }

    /** Il mezzo è ammesso dall'art. 66 c. 3 disp. att. c.c.? */
    public function valido(): bool
    {
        return $this !== self::EMAIL;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<array{value:string,label:string,valido:bool}> */
    public static function opzioni(): array
    {
        return array_map(fn (self $m) => [
            'value' => $m->value,
            'label' => $m->label(),
            'valido' => $m->valido(),
        ], self::cases());
    }
}
