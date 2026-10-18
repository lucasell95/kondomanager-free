<?php

namespace App\Http\Controllers\Gestionale\Assemblee;

use App\Enums\Assemblee\Convocazione;
use App\Enums\Assemblee\MateriaDelibera;
use App\Enums\Assemblee\MezzoConvocazione;
use App\Enums\Assemblee\StatoAssemblea;
use App\Enums\Assemblee\TipoAssemblea;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Assemblee\AssembleaRequest;
use App\Http\Requests\Gestionale\Assemblee\SvolgimentoRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\PuntoOdg;
use App\Models\Condominio;
use App\Models\Tabella;
use App\Services\Assemblee\AgendaAssembleaService;
use App\Services\Assemblee\BozzeAssembleaService;
use App\Services\Assemblee\ControlliConvocazioneService;
use App\Services\Assemblee\FotografiaUnitaService;
use App\Services\Assemblee\RiepilogoAssembleaService;
use App\Services\Assemblee\TesteAssembleaService;
use App\Traits\GuardieAssemblea;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Elenco, creazione, scheda e ciclo di vita delle assemblee di un condominio.
 */
class AssembleaController extends Controller
{
    use GuardieAssemblea, HandleFlashMessages, HasCondomini, HasEsercizio;

    public function __construct(
        private FotografiaUnitaService $fotografia,
        private RiepilogoAssembleaService $riepilogo,
        private TesteAssembleaService $teste,
        private ControlliConvocazioneService $controlli,
        private AgendaAssembleaService $agenda,
        private BozzeAssembleaService $bozze,
    ) {}

    public function index(Request $request, Condominio $condominio): Response
    {
        $esercizioCorrente = $this->getEsercizioCorrente($condominio);
        $esercizi = $condominio->esercizi()->orderByDesc('data_inizio')->get(['id', 'nome', 'stato', 'data_inizio', 'data_fine']);

        $filtro = $request->query('esercizio');
        $esercizioId = $filtro === 'tutti' ? null : ((int) $filtro ?: $esercizioCorrente?->id);

        $assemblee = $condominio->assemblee()
            ->when($esercizioId, fn ($q) => $q->where('esercizio_id', $esercizioId))
            ->withCount('punti')
            ->orderByDesc('prima_convocazione_at')
            ->get()
            ->map(fn (Assemblea $a) => $this->riga($a));

        $registro = $condominio->assemblee()
            ->whereNotNull('numero_verbale')
            ->orderByDesc('anno_verbale')
            ->orderByDesc('numero_verbale')
            ->get()
            ->map(fn (Assemblea $a) => $this->riga($a));

        return Inertia::render('gestionale/assemblee/AssembleeList', [
            'condominio' => $condominio,
            'esercizio' => $esercizioCorrente,
            'condomini' => CondominioResource::collection($this->getCondomini()),
            'esercizi' => $esercizi,
            'esercizioSelezionato' => $esercizioId,
            'assemblee' => $assemblee,
            'registro' => $registro,
        ]);
    }

    public function create(Condominio $condominio): Response
    {
        return Inertia::render('gestionale/assemblee/AssembleeNew', [
            'condominio' => $condominio,
            'esercizio' => $this->getEsercizioCorrente($condominio),
            'condomini' => CondominioResource::collection($this->getCondomini()),
            'opzioni' => $this->opzioni($condominio),
            'tabellaProposta' => $this->fotografia->tabellaProprieta($condominio)?->id,
        ]);
    }

    public function store(AssembleaRequest $request, Condominio $condominio): RedirectResponse
    {
        $dati = $request->validated();

        $assemblea = DB::transaction(function () use ($dati, $condominio, $request) {
            $dati['esercizio_id'] = ($dati['esercizio_id'] ?? null) ?: $this->getEsercizioCorrente($condominio)?->id;

            $assemblea = $condominio->assemblee()->create($dati + [
                'stato' => StatoAssemblea::BOZZA,
                'created_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
            ]);

            $this->fotografia->fotografa($assemblea);

            return $assemblea;
        });

        return to_route('admin.gestionale.assemblee.show', [$condominio, $assemblea])
            ->with($this->flashSuccess('Assemblea creata. Aggiungi i punti all\'ordine del giorno.'));
    }

    public function show(Condominio $condominio, Assemblea $assemblea): Response
    {
        $this->assicuraAssemblea($condominio, $assemblea);

        return Inertia::render('gestionale/assemblee/AssembleaShow', $this->scheda($condominio, $assemblea));
    }

    public function update(AssembleaRequest $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $this->assicuraDatiModificabili($assemblea);

        $vecchiaTabella = $assemblea->tabella_id;
        $assemblea->update($request->validated() + ['updated_by' => $request->user()?->id]);

        if ((int) $vecchiaTabella !== (int) $assemblea->tabella_id) {
            $this->fotografia->fotografa($assemblea);
        }

        $this->agenda->sincronizza($assemblea, $request->user()?->id);

        return back()->with($this->flashSuccess('Dati dell\'assemblea salvati.'));
    }

    public function destroy(Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);

        if ($assemblea->stato !== StatoAssemblea::BOZZA) {
            throw ValidationException::withMessages([
                'stato' => 'Si elimina solo un\'assemblea in bozza: una convocata va riportata in bozza, una verbalizzata resta nel registro.',
            ]);
        }

        $this->agenda->rimuovi($assemblea);
        $assemblea->delete();

        return to_route('admin.gestionale.assemblee.index', $condominio)
            ->with($this->flashSuccess('Assemblea eliminata.'));
    }

    /** Passaggio di stato: bozza ↔ convocata ↔ svolta. La verbalizzazione passa dal registro. */
    public function stato(Request $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);

        $request->validate([
            'stato' => ['required', Rule::in([StatoAssemblea::BOZZA->value, StatoAssemblea::CONVOCATA->value, StatoAssemblea::SVOLTA->value])],
        ]);

        $verso = StatoAssemblea::from($request->input('stato'));

        if (! $assemblea->stato->puoPassareA($verso)) {
            throw ValidationException::withMessages([
                'stato' => 'Da «'.$assemblea->stato->label().'» non si passa a «'.$verso->label().'».',
            ]);
        }

        if ($verso === StatoAssemblea::CONVOCATA && $assemblea->stato === StatoAssemblea::BOZZA && ! $assemblea->punti()->exists()) {
            throw ValidationException::withMessages([
                'stato' => 'Prima di convocare serve almeno un punto all\'ordine del giorno.',
            ]);
        }

        if ($verso === StatoAssemblea::BOZZA && $assemblea->presenze()->exists()) {
            throw ValidationException::withMessages([
                'stato' => 'Ci sono presenze registrate: l\'assemblea non può tornare in bozza.',
            ]);
        }

        $assemblea->update(['stato' => $verso, 'updated_by' => $request->user()?->id]);

        if ($verso === StatoAssemblea::SVOLTA && ! $assemblea->convocazione_tenuta) {
            $assemblea->update(['convocazione_tenuta' => $assemblea->convocazioneEffettiva()->value]);
        }

        $this->agenda->sincronizza($assemblea, $request->user()?->id);

        return back()->with($this->flashSuccess('Assemblea: '.mb_strtolower($verso->label()).'.'));
    }

    /** Rifà la fotografia di unità, titolari e millesimi (per esempio dopo aver corretto una tabella). */
    public function fotografia(Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);

        if ($assemblea->stato === StatoAssemblea::VERBALIZZATA) {
            throw ValidationException::withMessages(['stato' => 'Il verbale è registrato: i millesimi restano quelli del giorno dell\'assemblea.']);
        }

        $this->fotografia->fotografa($assemblea);

        return back()->with($this->flashSuccess('Unità, titolari e millesimi aggiornati dalle anagrafiche e dalla tabella.'));
    }

    /** Dati dello svolgimento: convocazione in cui si tiene, presidente, segretario, orari. */
    public function svolgimento(SvolgimentoRequest $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $this->assicuraPresenzeModificabili($assemblea);

        $dati = $request->validated();

        if (($dati['convocazione_tenuta'] ?? null) === Convocazione::SECONDA->value && ! $assemblea->seconda_convocazione_at) {
            throw ValidationException::withMessages(['convocazione_tenuta' => 'Non è stata fissata una seconda convocazione.']);
        }

        $assemblea->update($dati + ['updated_by' => $request->user()?->id]);

        return back()->with($this->flashSuccess('Dati dello svolgimento salvati.'));
    }

    /** Le opzioni dei menu delle schermate. */
    private function opzioni(Condominio $condominio): array
    {
        return [
            'tipi' => array_map(fn (TipoAssemblea $t) => ['value' => $t->value, 'label' => $t->label()], TipoAssemblea::cases()),
            'materie' => MateriaDelibera::opzioni(),
            'mezzi' => MezzoConvocazione::opzioni(),
            'esercizi' => $condominio->esercizi()->orderByDesc('data_inizio')->get(['id', 'nome', 'stato']),
            'tabelle' => Tabella::where('condominio_id', $condominio->id)
                ->where('quota', 'millesimi')
                ->orderBy('nome')
                ->get(['id', 'nome', 'tipo', 'attiva']),
            'anagrafiche' => $condominio->anagrafiche()->orderBy('nome')->get(['anagrafiche.id', 'anagrafiche.nome'])
                ->map(fn ($a) => ['id' => $a->id, 'nome' => $a->nome])->values(),
        ];
    }

    private function riga(Assemblea $a): array
    {
        return [
            'id' => $a->id,
            'titolo' => $a->titoloLeggibile(),
            'tipo' => $a->tipo?->value,
            'tipo_label' => $a->tipo?->label(),
            'stato' => $a->stato->value,
            'stato_label' => $a->stato->label(),
            'esercizio_id' => $a->esercizio_id,
            'prima_convocazione_at' => $a->prima_convocazione_at?->toIso8601String(),
            'seconda_convocazione_at' => $a->seconda_convocazione_at?->toIso8601String(),
            'data_svolgimento' => $a->dataSvolgimento()?->toIso8601String(),
            'punti_count' => $a->punti_count ?? null,
            'numero_registro' => $a->numeroRegistro(),
            'verbale_registrato_at' => $a->verbale_registrato_at?->toIso8601String(),
            'documento_verbale_id' => $a->documento_verbale_id,
        ];
    }

    /** Tutti i dati della scheda a schede. */
    private function scheda(Condominio $condominio, Assemblea $assemblea): array
    {
        $this->fotografia->assicura($assemblea);
        $assemblea->load(['punti', 'destinatari', 'dichiarazioni', 'documentoVerbale', 'tabella', 'esercizio']);

        $unita = $this->teste->unita($assemblea);
        $totale = (int) $unita->sum('valore');
        $decimali = $this->riepilogo->decimali($assemblea);
        $presenze = $this->teste->presenze($assemblea);

        $persone = collect($this->teste->persone($assemblea))->map(function ($persona) use ($presenze) {
            $p = $presenze->get($persona['anagrafica_id']);

            return $persona + [
                'presenza' => $p ? [
                    'modo' => $p->modo->value,
                    'delegato_anagrafica_id' => $p->delegato_anagrafica_id,
                    'delegato_nome' => $p->delegato_nome,
                    'delegato' => $p->nomeDelegato(),
                    'delega_scritta' => (bool) $p->delega_scritta,
                    'ora_ingresso' => $p->ora_ingresso,
                    'ora_uscita' => $p->ora_uscita,
                    'note' => $p->note,
                ] : null,
            ];
        })->values();

        $punti = $assemblea->punti->map(function (PuntoOdg $p) use ($assemblea) {
            $votazione = $this->riepilogo->punto($assemblea, $p);

            return [
                'id' => $p->id,
                'ordine' => $p->ordine,
                'titolo' => $p->titolo,
                'testo' => $p->testo,
                'materia' => $p->materia?->value,
                'materia_label' => $p->materia?->label(),
                'norma' => $p->materia?->norma(),
                'maggioranza_prima' => $p->materia?->regola(Convocazione::PRIMA)?->descrizione,
                'maggioranza_seconda' => $p->materia?->regola(Convocazione::SECONDA)?->descrizione,
                'vota_usufruttuario' => $p->materia?->votaUsufruttuario(),
                'richiede_votazione' => $p->materia?->richiedeVotazione(),
                'avvertenza' => $p->materia?->avvertenza(),
                'tabella_id' => $p->tabella_id,
                'discussione' => $p->discussione,
                'testo_delibera' => $p->testo_delibera,
                'votazione' => $votazione,
            ];
        })->values();

        $esercizio = $assemblea->esercizio ?? $this->getEsercizioCorrente($condominio);

        return [
            'condominio' => $condominio,
            'esercizio' => $esercizio,
            'condomini' => CondominioResource::collection($this->getCondomini()),
            'assemblea' => [
                'id' => $assemblea->id,
                'titolo' => $assemblea->titolo,
                'titolo_leggibile' => $assemblea->titoloLeggibile(),
                'tipo' => $assemblea->tipo?->value,
                'stato' => $assemblea->stato->value,
                'stato_label' => $assemblea->stato->label(),
                'transizioni' => array_map(fn ($s) => ['value' => $s->value, 'label' => $s->label()], $assemblea->stato->transizioniAmmesse()),
                'dati_modificabili' => $assemblea->stato->datiModificabili(),
                'presenze_modificabili' => $assemblea->stato->presenzeModificabili(),
                'esercizio_id' => $assemblea->esercizio_id,
                'tabella_id' => $assemblea->tabella_id,
                'tabella_nome' => $assemblea->tabella?->nome,
                'prima_convocazione_at' => $assemblea->prima_convocazione_at?->format('Y-m-d\TH:i'),
                'prima_luogo' => $assemblea->prima_luogo,
                'seconda_convocazione_at' => $assemblea->seconda_convocazione_at?->format('Y-m-d\TH:i'),
                'seconda_luogo' => $assemblea->seconda_luogo,
                'videoconferenza' => $assemblea->videoconferenza,
                'piattaforma_video' => $assemblea->piattaforma_video,
                'link_video' => $assemblea->link_video,
                'consenso_video_acquisito' => $assemblea->consenso_video_acquisito,
                'convocante' => $assemblea->convocante,
                'amministratore_anagrafica_id' => $assemblea->amministratore_anagrafica_id,
                'amministratore_nome' => $assemblea->amministratore_nome,
                'convocazione_tenuta' => $assemblea->convocazione_tenuta,
                'convocazione_effettiva' => $assemblea->convocazioneEffettiva()->value,
                'prima_deserta' => $assemblea->prima_deserta,
                'presidente' => $assemblea->presidente,
                'segretario' => $assemblea->segretario,
                'ora_apertura' => $assemblea->ora_apertura,
                'ora_chiusura' => $assemblea->ora_chiusura,
                'allegati' => $assemblea->allegati,
                'note' => $assemblea->note,
                'numero_registro' => $assemblea->numeroRegistro(),
                'verbale_registrato_at' => $assemblea->verbale_registrato_at?->toIso8601String(),
                'documento_verbale' => $assemblea->documentoVerbale ? [
                    'id' => $assemblea->documentoVerbale->id,
                    'name' => $assemblea->documentoVerbale->name,
                    'is_published' => (bool) $assemblea->documentoVerbale->is_published,
                ] : null,
            ],
            'punti' => $punti,
            'unita' => $unita->map(fn ($u) => [
                'id' => $u->id,
                'etichetta' => $u->etichetta,
                'valore_testo' => \App\Support\Assemblee\Millesimi::formatta((int) $u->valore, $decimali),
            ])->values(),
            'totaleMillesimi' => \App\Support\Assemblee\Millesimi::formatta($totale, $decimali),
            'costitutivo' => [
                'prima' => $this->riepilogo->costitutivo($assemblea, Convocazione::PRIMA),
                'seconda' => $assemblea->seconda_convocazione_at ? $this->riepilogo->costitutivo($assemblea, Convocazione::SECONDA) : null,
            ],
            'persone' => $persone,
            'destinatari' => $this->riepilogo->destinatari($assemblea),
            'dichiarazioni' => $assemblea->dichiarazioni->map(fn ($d) => [
                'id' => $d->id,
                'assemblea_punto_id' => $d->assemblea_punto_id,
                'anagrafica_id' => $d->anagrafica_id,
                'dichiarante' => $d->dichiarante,
                'testo' => $d->testo,
            ])->values(),
            'assenti' => $this->riepilogo->assenti($assemblea),
            'scadenzaPresenti' => $this->riepilogo->scadenzaPresenti($assemblea),
            'controlli' => $this->controlli->controlli($assemblea, $totale),
            'opzioni' => $this->opzioni($condominio),
            'iaDisponibile' => $this->bozze->iaDisponibile(),
        ];
    }
}
