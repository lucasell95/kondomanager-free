<?php

namespace App\Models\Assemblee;

use App\Models\Immobile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fotografia di un'unità al momento dell'assemblea: millesimi (centomillesimi interi) e titolari.
 *
 * Si fotografa perché il verbale deve riportare i millesimi e i titolari di quel giorno, anche se
 * poi la tabella o le anagrafiche cambiano.
 */
class UnitaAssemblea extends Model
{
    protected $table = 'assemblea_unita';

    protected $fillable = [
        'assemblea_id',
        'immobile_id',
        'etichetta',
        'valore',
        'proprietari',
        'usufruttuari',
    ];

    protected $casts = [
        'valore' => 'integer',
        'proprietari' => 'array',
        'usufruttuari' => 'array',
    ];

    public function assemblea(): BelongsTo
    {
        return $this->belongsTo(Assemblea::class);
    }

    public function immobile(): BelongsTo
    {
        return $this->belongsTo(Immobile::class);
    }
}
