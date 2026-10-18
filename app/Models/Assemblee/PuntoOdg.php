<?php

namespace App\Models\Assemblee;

use App\Enums\Assemblee\MateriaDelibera;
use App\Models\Tabella;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Un punto all'ordine del giorno, con la materia che decide la maggioranza richiesta. */
class PuntoOdg extends Model
{
    protected $table = 'assemblea_punti';

    protected $fillable = [
        'assemblea_id',
        'ordine',
        'titolo',
        'testo',
        'materia',
        'tabella_id',
        'discussione',
        'testo_delibera',
    ];

    protected $casts = [
        'materia' => MateriaDelibera::class,
        'ordine' => 'integer',
    ];

    public function assemblea(): BelongsTo
    {
        return $this->belongsTo(Assemblea::class);
    }

    public function tabella(): BelongsTo
    {
        return $this->belongsTo(Tabella::class);
    }

    public function voti(): HasMany
    {
        return $this->hasMany(VotoPunto::class, 'assemblea_punto_id');
    }

    public function dichiarazioni(): HasMany
    {
        return $this->hasMany(DichiarazioneVerbale::class, 'assemblea_punto_id');
    }
}
