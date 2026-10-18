<?php

namespace App\Models\Gestionale;

use App\Models\Condominio;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Interessi e sanzione versati per ravvedere una riga F24 pagata in ritardo.
 *
 * Sta **a fianco** della delega, non dentro: la delega versata è sotto sigillo e non si
 * riscrive. Serve al quadro ST del 770 (colonna degli interessi e casella del ravvedimento).
 * Importi in centesimi.
 */
class RavvedimentoF24 extends Model
{
    protected $table = 'ravvedimenti_f24';

    protected $guarded = ['id'];

    protected $casts = [
        'condominio_id' => 'integer',
        'riga_f24_id' => 'integer',
        'interessi' => 'integer',
        'sanzione' => 'integer',
        'data_versamento' => 'date',
    ];

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    public function riga(): BelongsTo
    {
        return $this->belongsTo(RigaF24::class, 'riga_f24_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
