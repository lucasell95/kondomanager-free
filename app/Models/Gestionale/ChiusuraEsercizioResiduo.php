<?php

namespace App\Models\Gestionale;

use App\Models\Anagrafica;
use App\Models\Gestione;
use App\Models\Immobile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Il residuo di un condòmino, per unità e gestione, fotografato alla chiusura.
 * Importi in centesimi; positivo = debito del condòmino, negativo = credito.
 */
class ChiusuraEsercizioResiduo extends Model
{
    protected $table = 'chiusura_esercizio_residui';

    protected $fillable = [
        'chiusura_esercizio_id',
        'anagrafica_id',
        'immobile_id',
        'gestione_id',
        'gestione_destinazione_id',
        'modalita',
        'saldo_iniziale',
        'residuo',
        'riportato',
        'mantenuto',
        'conguaglio',
    ];

    protected $casts = [
        'saldo_iniziale' => 'integer',
        'residuo'        => 'integer',
        'riportato'      => 'integer',
        'mantenuto'      => 'integer',
        'conguaglio'     => 'integer',
    ];

    public function chiusura(): BelongsTo
    {
        return $this->belongsTo(ChiusuraEsercizio::class, 'chiusura_esercizio_id');
    }

    public function anagrafica(): BelongsTo
    {
        return $this->belongsTo(Anagrafica::class);
    }

    public function immobile(): BelongsTo
    {
        return $this->belongsTo(Immobile::class);
    }

    public function gestione(): BelongsTo
    {
        return $this->belongsTo(Gestione::class);
    }
}
