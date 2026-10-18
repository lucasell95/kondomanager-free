<?php

namespace App\Models\Assemblee;

use App\Models\Anagrafica;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dichiarazione resa a verbale su richiesta del condòmino (art. 1130 n. 7 c.c.).
 *
 * Il testo è **letterale**: non lo riformula nessuno, nemmeno l'IA.
 */
class DichiarazioneVerbale extends Model
{
    protected $table = 'assemblea_dichiarazioni';

    protected $fillable = [
        'assemblea_id',
        'assemblea_punto_id',
        'anagrafica_id',
        'dichiarante',
        'testo',
    ];

    public function assemblea(): BelongsTo
    {
        return $this->belongsTo(Assemblea::class);
    }

    public function punto(): BelongsTo
    {
        return $this->belongsTo(PuntoOdg::class, 'assemblea_punto_id');
    }
}
