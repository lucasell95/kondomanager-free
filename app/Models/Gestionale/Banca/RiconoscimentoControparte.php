<?php

namespace App\Models\Gestionale\Banca;

use App\Models\Anagrafica;
use App\Models\Fornitore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Memoria della controparte: un IBAN o un nome già abbinato in passato a un condòmino
 * (entrate) o a un fornitore (uscite). Idea dalle «recognitions» di LiDeCo.
 */
class RiconoscimentoControparte extends Model
{
    public const TIPO_IBAN = 'iban';
    public const TIPO_NOME = 'nome';

    protected $table = 'banca_riconoscimenti';

    protected $fillable = [
        'condominio_id',
        'tipo',
        'valore',
        'anagrafica_id',
        'fornitore_id',
        'conteggio',
        'ultimo_uso_at',
    ];

    protected $casts = [
        'condominio_id' => 'integer',
        'anagrafica_id' => 'integer',
        'fornitore_id' => 'integer',
        'conteggio' => 'integer',
        'ultimo_uso_at' => 'datetime',
    ];

    public function anagrafica(): BelongsTo
    {
        return $this->belongsTo(Anagrafica::class);
    }

    public function fornitore(): BelongsTo
    {
        return $this->belongsTo(Fornitore::class);
    }
}
