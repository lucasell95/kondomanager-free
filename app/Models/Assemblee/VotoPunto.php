<?php

namespace App\Models\Assemblee;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Presenza e voto di una testa su un punto: la presenza si registra punto per punto (Cass. 4191/2024). */
class VotoPunto extends Model
{
    protected $table = 'assemblea_voti';

    protected $fillable = [
        'assemblea_punto_id',
        'chiave_testa',
        'presente',
        'voto',
        'conflitto',
    ];

    protected $casts = [
        'presente' => 'boolean',
        'conflitto' => 'boolean',
    ];

    public function punto(): BelongsTo
    {
        return $this->belongsTo(PuntoOdg::class, 'assemblea_punto_id');
    }
}
