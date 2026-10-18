<?php

namespace App\Models\Assemblee;

use App\Enums\Assemblee\Convocazione;
use App\Enums\Assemblee\StatoAssemblea;
use App\Enums\Assemblee\TipoAssemblea;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Esercizio;
use App\Models\Evento;
use App\Models\Tabella;
use App\Models\User;
use App\Traits\RisolveIFigliDelleRotte;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un'assemblea di condominio: convocazione, svolgimento e verbale.
 *
 * Vedi `App\Services\Assemblee\QuorumService` per i calcoli e `analisi/svil-assemblee.md` per il
 * flusso completo.
 */
class Assemblea extends Model
{
    use RisolveIFigliDelleRotte;

    protected $table = 'assemblee';

    protected $fillable = [
        'condominio_id',
        'esercizio_id',
        'tabella_id',
        'tipo',
        'titolo',
        'stato',
        'prima_convocazione_at',
        'prima_luogo',
        'seconda_convocazione_at',
        'seconda_luogo',
        'videoconferenza',
        'piattaforma_video',
        'link_video',
        'consenso_video_acquisito',
        'convocante',
        'amministratore_anagrafica_id',
        'amministratore_nome',
        'convocazione_tenuta',
        'prima_deserta',
        'presidente',
        'segretario',
        'ora_apertura',
        'ora_chiusura',
        'allegati',
        'note',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'tipo' => TipoAssemblea::class,
        'stato' => StatoAssemblea::class,
        'prima_convocazione_at' => 'datetime',
        'seconda_convocazione_at' => 'datetime',
        'videoconferenza' => 'boolean',
        'consenso_video_acquisito' => 'boolean',
        'prima_deserta' => 'boolean',
        'verbale_registrato_at' => 'datetime',
        'numero_verbale' => 'integer',
        'anno_verbale' => 'integer',
        'bozze_ia' => 'array',
    ];

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    public function esercizio(): BelongsTo
    {
        return $this->belongsTo(Esercizio::class);
    }

    public function tabella(): BelongsTo
    {
        return $this->belongsTo(Tabella::class);
    }

    public function amministratoreAnagrafica(): BelongsTo
    {
        return $this->belongsTo(Anagrafica::class, 'amministratore_anagrafica_id');
    }

    public function documentoVerbale(): BelongsTo
    {
        return $this->belongsTo(Documento::class, 'documento_verbale_id');
    }

    public function evento(): BelongsTo
    {
        return $this->belongsTo(Evento::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function punti(): HasMany
    {
        return $this->hasMany(PuntoOdg::class)->orderBy('ordine')->orderBy('id');
    }

    public function destinatari(): HasMany
    {
        return $this->hasMany(DestinatarioConvocazione::class)->orderBy('nominativo');
    }

    public function unita(): HasMany
    {
        return $this->hasMany(UnitaAssemblea::class)->orderBy('id');
    }

    public function presenze(): HasMany
    {
        return $this->hasMany(PresenzaAssemblea::class);
    }

    public function dichiarazioni(): HasMany
    {
        return $this->hasMany(DichiarazioneVerbale::class)->orderBy('id');
    }

    /** La convocazione in cui si delibera: quella dichiarata, altrimenti la seconda se la prima è andata deserta. */
    public function convocazioneEffettiva(): Convocazione
    {
        if ($this->convocazione_tenuta) {
            return Convocazione::from($this->convocazione_tenuta);
        }

        return $this->prima_deserta && $this->seconda_convocazione_at ? Convocazione::SECONDA : Convocazione::PRIMA;
    }

    /** Data e ora dell'adunanza effettiva. */
    public function dataSvolgimento(): ?\Illuminate\Support\Carbon
    {
        return $this->convocazioneEffettiva() === Convocazione::SECONDA
            ? $this->seconda_convocazione_at
            : $this->prima_convocazione_at;
    }

    public function luogoSvolgimento(): ?string
    {
        return $this->convocazioneEffettiva() === Convocazione::SECONDA
            ? ($this->seconda_luogo ?: $this->prima_luogo)
            : $this->prima_luogo;
    }

    public function numeroRegistro(): ?string
    {
        return $this->numero_verbale ? $this->numero_verbale.'/'.$this->anno_verbale : null;
    }

    /** @return list<string> */
    public function elencoAllegati(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $this->allegati))));
    }

    public function titoloLeggibile(): string
    {
        $tipo = $this->tipo?->label() ?? 'Ordinaria';

        return $this->titolo ?: 'Assemblea '.mb_strtolower($tipo).' del '.$this->prima_convocazione_at?->format('d/m/Y');
    }

    protected function relazioniDeiFigliNelleRotte(): array
    {
        return [
            'punto' => 'punti',
            'destinatario' => 'destinatari',
            'presenza' => 'presenze',
            'dichiarazione' => 'dichiarazioni',
        ];
    }
}
