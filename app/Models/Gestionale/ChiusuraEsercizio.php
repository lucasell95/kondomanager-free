<?php

namespace App\Models\Gestionale;

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Saldo;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un passaggio d'esercizio eseguito con la procedura guidata (vedi la migrazione
 * `create_chiusure_esercizi_table` per il perché di ogni campo).
 *
 * `riepilogo` è la mappa di ciò che la chiusura ha generato:
 *
 *     gestioni_create        int[]   gestioni nuove (l'ordinaria del nuovo esercizio)
 *     gestioni_agganciate    int[]   straordinarie riagganciate al nuovo esercizio
 *     gestioni_disattivate   array   [id => attiva prima] gestioni spente perché non proseguono
 *     piani_conti_creati     int[]   piani dei conti copiati
 *     scritture              int[]   scritture di riporto (tipo `chiusura`) nel nuovo esercizio
 *     quote_riportate        array   [{quota_id, scrittura_id, importo, riferimento_prima}]
 *     saldi_creati           int[]   saldi iniziali del nuovo esercizio
 *     saldi_bloccati         int[]   saldi liberi del vecchio esercizio chiusi perché riportati
 *     totali                 array   riportato, mantenuto, residuo
 */
class ChiusuraEsercizio extends Model
{
    public const STATO_ESEGUITA  = 'eseguita';
    public const STATO_ANNULLATA = 'annullata';

    protected $table = 'chiusure_esercizi';

    protected $fillable = [
        'condominio_id',
        'esercizio_chiuso_id',
        'esercizio_nuovo_id',
        'stato',
        'opzioni',
        'riepilogo',
        'eseguita_da',
        'eseguita_il',
        'annullata_da',
        'annullata_il',
    ];

    protected $casts = [
        'opzioni'      => 'array',
        'riepilogo'    => 'array',
        'eseguita_il'  => 'datetime',
        'annullata_il' => 'datetime',
    ];

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    public function esercizioChiuso(): BelongsTo
    {
        return $this->belongsTo(Esercizio::class, 'esercizio_chiuso_id');
    }

    public function esercizioNuovo(): BelongsTo
    {
        return $this->belongsTo(Esercizio::class, 'esercizio_nuovo_id');
    }

    public function residui(): HasMany
    {
        return $this->hasMany(ChiusuraEsercizioResiduo::class, 'chiusura_esercizio_id');
    }

    public function saldi(): HasMany
    {
        return $this->hasMany(Saldo::class, 'chiusura_esercizio_id');
    }

    public function autore(): BelongsTo
    {
        return $this->belongsTo(User::class, 'eseguita_da');
    }

    public function eEseguita(): bool
    {
        return $this->stato === self::STATO_ESEGUITA;
    }
}
