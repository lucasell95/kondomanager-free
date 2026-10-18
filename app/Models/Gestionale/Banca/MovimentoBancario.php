<?php

namespace App\Models\Gestionale\Banca;

use App\Models\Condominio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ScritturaContabile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Una riga dell'estratto conto, così come la dice la banca.
 *
 * Non è una scrittura contabile: diventa contabilità solo quando viene abbinata, passando da uno
 * dei flussi esistenti. `importo` è in centesimi con segno (positivo = accredito).
 */
class MovimentoBancario extends Model
{
    public const STATO_DA_ABBINARE = 'da_abbinare';
    public const STATO_ABBINATO = 'abbinato';
    public const STATO_IGNORATO = 'ignorato';

    public const STATI = [self::STATO_DA_ABBINARE, self::STATO_ABBINATO, self::STATO_IGNORATO];

    public const ABBINAMENTO_INCASSO = 'incasso_rate';
    public const ABBINAMENTO_PAGAMENTO = 'pagamento_fornitore';
    public const ABBINAMENTO_REGOLAZIONE = 'regolazione';
    public const ABBINAMENTO_ESISTENTE = 'esistente';

    protected $table = 'banca_movimenti';

    protected $fillable = [
        'condominio_id',
        'cassa_id',
        'importazione_id',
        'data_contabile',
        'data_valuta',
        'importo',
        'divisa',
        'descrizione',
        'controparte',
        'iban_controparte',
        'riferimento',
        'hash',
        'riga_origine',
        'stato',
        'tipo_abbinamento',
        'scrittura_contabile_id',
        'abbinato_da',
        'abbinato_at',
        'note',
        'idempotency_key',
    ];

    protected $casts = [
        'condominio_id' => 'integer',
        'cassa_id' => 'integer',
        'importo' => 'integer',
        'data_contabile' => 'date',
        'data_valuta' => 'date',
        'abbinato_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (MovimentoBancario $m) {
            $m->idempotency_key ??= (string) Str::uuid();
            $m->stato ??= self::STATO_DA_ABBINARE;
        });
    }

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    public function cassa(): BelongsTo
    {
        return $this->belongsTo(Cassa::class);
    }

    public function importazione(): BelongsTo
    {
        return $this->belongsTo(ImportazioneBancaria::class, 'importazione_id');
    }

    public function scrittura(): BelongsTo
    {
        return $this->belongsTo(ScritturaContabile::class, 'scrittura_contabile_id');
    }

    public function isEntrata(): bool
    {
        return $this->importo > 0;
    }

    public function scopeDaAbbinare(Builder $q): Builder
    {
        return $q->where('stato', self::STATO_DA_ABBINARE);
    }

    /** Il testo su cui si cerca la controparte: descrizione, controparte e IBAN insieme. */
    public function testoCompleto(): string
    {
        return trim(implode(' ', array_filter([$this->controparte, $this->descrizione, $this->iban_controparte])));
    }
}
