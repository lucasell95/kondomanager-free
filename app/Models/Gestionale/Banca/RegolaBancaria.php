<?php

namespace App\Models\Gestionale\Banca;

use App\Models\Gestionale\Conto;
use App\Models\Gestione;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * «Se la descrizione contiene X» → registra una regolazione immediata sul capitolo scelto
 * (commissioni, bolli, canone del conto) oppure ignora il movimento.
 */
class RegolaBancaria extends Model
{
    public const AZIONE_REGOLAZIONE = 'regolazione';
    public const AZIONE_IGNORA = 'ignora';

    protected $table = 'banca_regole';

    protected $fillable = [
        'condominio_id',
        'cassa_id',
        'nome',
        'verso',
        'testo_contiene',
        'importo_min',
        'importo_max',
        'azione',
        'conto_id',
        'gestione_id',
        'causale',
        'attiva',
    ];

    protected $casts = [
        'condominio_id' => 'integer',
        'cassa_id' => 'integer',
        'conto_id' => 'integer',
        'gestione_id' => 'integer',
        'importo_min' => 'integer',
        'importo_max' => 'integer',
        'attiva' => 'boolean',
    ];

    public function conto(): BelongsTo
    {
        return $this->belongsTo(Conto::class);
    }

    public function gestione(): BelongsTo
    {
        return $this->belongsTo(Gestione::class);
    }

    /** La regola vale per questo movimento? Confronto sul testo normalizzato, senza regex. */
    public function siApplicaA(MovimentoBancario $m): bool
    {
        if (! $this->attiva) {
            return false;
        }

        if ($this->cassa_id && $this->cassa_id !== $m->cassa_id) {
            return false;
        }

        if ($this->verso === 'entrata' && $m->importo <= 0) {
            return false;
        }

        if ($this->verso === 'uscita' && $m->importo >= 0) {
            return false;
        }

        $assoluto = abs($m->importo);

        if ($this->importo_min !== null && $assoluto < $this->importo_min) {
            return false;
        }

        if ($this->importo_max !== null && $assoluto > $this->importo_max) {
            return false;
        }

        $ago = \App\Services\Gestionale\Banca\Testo::normalizza($this->testo_contiene);

        return $ago !== '' && str_contains(\App\Services\Gestionale\Banca\Testo::normalizza($m->testoCompleto()), $ago);
    }
}
