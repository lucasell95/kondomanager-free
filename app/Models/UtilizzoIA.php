<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una chiamata all'IA (riuscita o no). Solo metadati: mai il testo inviato o ricevuto.
 *
 * @property int $costo_microdollari Costo stimato in milionesimi di dollaro.
 */
class UtilizzoIA extends Model
{
    public const ESITO_OK = 'ok';
    public const ESITO_NON_VALIDA = 'bozza_non_valida';
    public const ESITO_RIFIUTO = 'rifiuto';
    public const ESITO_TRONCATA = 'troncata';
    public const ESITO_ERRORE = 'errore';
    public const ESITO_BLOCCATA_TETTO = 'bloccata_tetto';

    protected $table = 'ia_utilizzi';

    protected $fillable = [
        'user_id',
        'tipo_documento',
        'modello',
        'token_ingresso',
        'token_uscita',
        'costo_microdollari',
        'esito',
        'codice_errore',
        'request_id',
        'hash_contesto',
        'durata_ms',
    ];

    protected $casts = [
        'token_ingresso'     => 'integer',
        'token_uscita'       => 'integer',
        'costo_microdollari' => 'integer',
        'durata_ms'          => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
