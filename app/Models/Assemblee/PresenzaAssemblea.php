<?php

namespace App\Models\Assemblee;

use App\Enums\Assemblee\ModoPresenza;
use App\Models\Anagrafica;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Presenza di un condòmino all'apertura: in proprio, per delega o assente. */
class PresenzaAssemblea extends Model
{
    protected $table = 'assemblea_presenze';

    protected $fillable = [
        'assemblea_id',
        'anagrafica_id',
        'modo',
        'delegato_anagrafica_id',
        'delegato_nome',
        'delega_scritta',
        'ora_ingresso',
        'ora_uscita',
        'note',
    ];

    protected $casts = [
        'modo' => ModoPresenza::class,
        'delega_scritta' => 'boolean',
    ];

    public function assemblea(): BelongsTo
    {
        return $this->belongsTo(Assemblea::class);
    }

    public function anagrafica(): BelongsTo
    {
        return $this->belongsTo(Anagrafica::class);
    }

    public function delegatoAnagrafica(): BelongsTo
    {
        return $this->belongsTo(Anagrafica::class, 'delegato_anagrafica_id');
    }

    /** Nome del delegato: l'anagrafica se è un condòmino, altrimenti il nome scritto. */
    public function nomeDelegato(): ?string
    {
        if ($this->modo !== ModoPresenza::DELEGATO) {
            return null;
        }

        return $this->delegatoAnagrafica?->nome ?? $this->delegato_nome;
    }
}
