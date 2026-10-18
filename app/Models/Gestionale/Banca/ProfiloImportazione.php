<?php

namespace App\Models\Gestionale\Banca;

use App\Models\Gestionale\Cassa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Come si legge l'export tabellare (CSV/XLSX) di un conto: riga di intestazione, separatori,
 * formato data e quale colonna fa da data, importo, descrizione…
 *
 * `configurazione` ha la forma di `LettoreTabellare::configurazionePredefinita()`.
 */
class ProfiloImportazione extends Model
{
    protected $table = 'banca_profili_importazione';

    protected $fillable = [
        'condominio_id',
        'cassa_id',
        'formato',
        'nome',
        'configurazione',
        'created_by',
    ];

    protected $casts = [
        'condominio_id' => 'integer',
        'cassa_id' => 'integer',
        'configurazione' => 'array',
    ];

    public function cassa(): BelongsTo
    {
        return $this->belongsTo(Cassa::class);
    }
}
