<?php

namespace App\Models\Gestionale\Banca;

use Illuminate\Database\Eloquent\Model;

/** Saldo a fine giornata secondo la banca (dal file o inserito a mano). */
class SaldoEstrattoConto extends Model
{
    protected $table = 'banca_saldi';

    protected $fillable = [
        'condominio_id',
        'cassa_id',
        'data',
        'saldo',
        'fonte',
        'importazione_id',
    ];

    protected $casts = [
        'condominio_id' => 'integer',
        'cassa_id' => 'integer',
        'data' => 'date',
        'saldo' => 'integer',
    ];

    /**
     * Imposta il saldo di un giorno, sostituendo quello che c'era.
     *
     * Si cerca con `whereDate` e non con un `updateOrCreate` sulla data: il cast `date` salva
     * «Y-m-d H:i:s» su SQLite e «Y-m-d» su MySQL, e il confronto per uguaglianza fra le due forme
     * non troverebbe la riga esistente — andando a sbattere sull'indice unico.
     */
    public static function imposta(int $condominioId, int $cassaId, string $data, int $saldo, string $fonte, ?int $importazioneId = null): self
    {
        $giorno = \Carbon\CarbonImmutable::parse($data)->toDateString();

        $voce = static::where('cassa_id', $cassaId)->whereDate('data', $giorno)->first()
            ?? new static(['cassa_id' => $cassaId, 'data' => $giorno]);

        $voce->fill([
            'condominio_id' => $condominioId,
            'saldo' => $saldo,
            'fonte' => $fonte,
            'importazione_id' => $importazioneId,
        ])->save();

        return $voce;
    }
}
