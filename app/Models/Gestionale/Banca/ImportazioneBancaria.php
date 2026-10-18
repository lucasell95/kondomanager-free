<?php

namespace App\Models\Gestionale\Banca;

use App\Models\Gestionale\Cassa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Un caricamento di estratto conto: file, periodo, saldi dichiarati, contatori. */
class ImportazioneBancaria extends Model
{
    protected $table = 'banca_importazioni';

    protected $fillable = [
        'condominio_id',
        'cassa_id',
        'profilo_id',
        'formato',
        'nome_file',
        'hash_file',
        'data_da',
        'data_a',
        'saldo_iniziale',
        'saldo_finale',
        'righe_lette',
        'righe_importate',
        'righe_duplicate',
        'righe_collegate',
        'created_by',
    ];

    protected $casts = [
        'condominio_id' => 'integer',
        'cassa_id' => 'integer',
        'data_da' => 'date',
        'data_a' => 'date',
        'saldo_iniziale' => 'integer',
        'saldo_finale' => 'integer',
    ];

    public function cassa(): BelongsTo
    {
        return $this->belongsTo(Cassa::class);
    }

    public function movimenti(): HasMany
    {
        return $this->hasMany(MovimentoBancario::class, 'importazione_id');
    }
}
