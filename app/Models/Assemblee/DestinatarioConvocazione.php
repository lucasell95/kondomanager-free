<?php

namespace App\Models\Assemblee;

use App\Enums\Assemblee\MezzoConvocazione;
use App\Models\Anagrafica;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A chi è stato mandato l'avviso (e poi il verbale), con quale mezzo e quando l'ha ricevuto. */
class DestinatarioConvocazione extends Model
{
    protected $table = 'assemblea_destinatari';

    protected $fillable = [
        'assemblea_id',
        'anagrafica_id',
        'nominativo',
        'ruolo',
        'mezzo',
        'recapito',
        'data_invio',
        'data_ricezione',
        'riferimento',
        'verbale_mezzo',
        'verbale_data_invio',
        'verbale_data_ricezione',
        'verbale_riferimento',
        'note',
    ];

    protected $casts = [
        'mezzo' => MezzoConvocazione::class,
        'verbale_mezzo' => MezzoConvocazione::class,
        'data_invio' => 'date',
        'data_ricezione' => 'date',
        'verbale_data_invio' => 'date',
        'verbale_data_ricezione' => 'date',
    ];

    public function assemblea(): BelongsTo
    {
        return $this->belongsTo(Assemblea::class);
    }

    public function anagrafica(): BelongsTo
    {
        return $this->belongsTo(Anagrafica::class);
    }
}
