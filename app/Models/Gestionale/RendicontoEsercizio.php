<?php

namespace App\Models\Gestionale;

use App\Models\Condominio;
use App\Models\Esercizio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Le parti del rendiconto 1130-bis scritte dall'amministratore: la nota sintetica (con i
 * segnaposto `{{F:chiave}}`) e i saldi degli estratti conto a fine esercizio. Tutto il resto del
 * rendiconto si calcola dal giornale e non si salva.
 */
class RendicontoEsercizio extends Model
{
    protected $table = 'rendiconti_esercizio';

    protected $fillable = [
        'condominio_id',
        'esercizio_id',
        'nota_sintetica',
        'fonte_nota',
        'modello_ia',
        'versione_prompt_ia',
        'saldi_estratto_conto',
        'updated_by',
    ];

    protected $casts = [
        'saldi_estratto_conto' => 'array',
    ];

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    public function esercizio(): BelongsTo
    {
        return $this->belongsTo(Esercizio::class);
    }

    /** @return array<int,int> `conto_contabile_id => centesimi` */
    public function estrattiConto(): array
    {
        $saldi = [];
        foreach ((array) ($this->saldi_estratto_conto ?? []) as $contoId => $cents) {
            if ($cents === null || $cents === '') continue;
            $saldi[(int) $contoId] = (int) $cents;
        }

        return $saldi;
    }
}
