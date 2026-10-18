<?php

namespace App\Http\Controllers\Gestionale\Esercizi;

use App\Actions\Gestionale\Chiusura\AnnullaChiusuraEsercizioAction;
use App\Actions\Gestionale\Chiusura\ChiudiEsercizioAction;
use App\Exceptions\Gestionale\ChiusuraEsercizioException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Esercizio\ChiudiEsercizioRequest;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\ChiusuraEsercizio;
use App\Models\Immobile;
use App\Services\Gestionale\Chiusura\ControlliChiusuraService;
use App\Services\Gestionale\Chiusura\NomiEsercizio;
use App\Services\Gestionale\Chiusura\PianoRiportoService;
use App\Services\Gestionale\Chiusura\ResiduiEsercizioService;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Passaggio d'esercizio guidato: «Chiudi esercizio e apri il successivo».
 *
 * La pagina è a passi (controlli → saldi per condòmino → gestioni e nuovo esercizio → conferma)
 * e tutto ciò che mostra viene dagli stessi servizi che l'azione usa per scrivere: l'anteprima è
 * il calcolo, non una stima.
 */
class ChiusuraEsercizioController extends Controller
{
    use HandleFlashMessages, HasCondomini;

    public function __construct(
        private ControlliChiusuraService $controlli,
        private ResiduiEsercizioService $residui,
        private PianoRiportoService $piano,
    ) {}

    public function show(Condominio $condominio, Esercizio $esercizio): Response
    {
        $this->assicuraEsercizioDelCondominio($condominio, $esercizio);

        $chiusura = ChiusuraEsercizio::where('esercizio_chiuso_id', $esercizio->id)
            ->latest('id')
            ->with(['esercizioNuovo:id,nome,data_inizio,data_fine,stato,serie_protocollo', 'autore:id,name'])
            ->first();

        $eseguita = $chiusura?->eEseguita() ? $chiusura : null;

        $props = [
            'condominio' => $condominio,
            'condomini'  => $this->getCondomini(),
            'esercizio'  => $esercizio->only(['id', 'nome', 'descrizione', 'data_inizio', 'data_fine', 'stato', 'serie_protocollo']),
            'chiusura'   => $eseguita ? $this->riepilogoChiusura($eseguita) : null,
            'ultima_annullata' => (! $eseguita && $chiusura) ? [
                'annullata_il' => $chiusura->annullata_il?->toIso8601String(),
            ] : null,
        ];

        if ($esercizio->stato === 'aperto') {
            $residui = $this->residui->calcola($esercizio);
            $gestioni = $this->residui->gestioniCoinvolte($esercizio, $residui);

            $inizio = $esercizio->data_fine->copy()->addDay();
            $fine = $inizio->copy()->addYear()->subDay();

            $props += [
                'controlli' => $this->controlli->esegui($esercizio),
                'gestioni'  => $gestioni->map(fn ($g) => $g + [
                    'modalita_predefinita' => $g['tipo'] === 'ordinaria' ? PianoRiportoService::RIPORTA : PianoRiportoService::MANTIENI,
                    'riapri_predefinito'   => $g['tipo'] === 'ordinaria' ? true : $g['nell_esercizio'],
                ])->values(),
                'residui'   => $this->residuiPerPagina($residui),
                'proposta'  => [
                    'nome'        => NomiEsercizio::nomeEsercizio($inizio, $fine),
                    'descrizione' => NomiEsercizio::descrizione($inizio, $fine),
                    'data_inizio' => $inizio->toDateString(),
                    'data_fine'   => $fine->toDateString(),
                    'serie'       => NomiEsercizio::serie($inizio, $fine),
                ],
            ];
        }

        return Inertia::render('gestionale/esercizi/ChiusuraEsercizio', $props);
    }

    public function store(ChiudiEsercizioRequest $request, Condominio $condominio, Esercizio $esercizio, ChiudiEsercizioAction $azione): RedirectResponse
    {
        $this->assicuraEsercizioDelCondominio($condominio, $esercizio);

        $dati = $request->validated();

        try {
            $chiusura = $azione->execute($esercizio, [
                'nome'                      => $dati['nome'],
                'descrizione'               => $dati['descrizione'] ?? null,
                'data_fine'                 => $dati['data_fine'],
                'numerazione_per_esercizio' => (bool) ($dati['numerazione_per_esercizio'] ?? true),
                'copia_piano_conti'         => (bool) ($dati['copia_piano_conti'] ?? true),
                'gestioni'                  => $dati['gestioni'] ?? [],
            ], $request->user()?->id);
        } catch (ChiusuraEsercizioException $e) {
            return back()->with($this->flashError($e->getMessage()));
        } catch (\Throwable $e) {
            Log::error('Errore nella chiusura dell\'esercizio', [
                'esercizio_id' => $esercizio->id, 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString(),
            ]);

            return back()->with($this->flashError('La chiusura non è riuscita e non è stato modificato niente: '.$e->getMessage()));
        }

        return to_route('admin.gestionale.esercizi.chiusura.show', [$condominio, $esercizio])
            ->with($this->flashSuccess(
                "Esercizio «{$esercizio->nome}» chiuso. Aperto «{$chiusura->esercizioNuovo->nome}» con i saldi iniziali riportati."
            ));
    }

    public function annulla(Request $request, Condominio $condominio, Esercizio $esercizio, AnnullaChiusuraEsercizioAction $azione): RedirectResponse
    {
        $this->assicuraEsercizioDelCondominio($condominio, $esercizio);

        $chiusura = $esercizio->chiusuraEseguita();

        if (! $chiusura) {
            return back()->with($this->flashError('Questo esercizio non è stato chiuso con la procedura guidata.'));
        }

        try {
            $azione->execute($chiusura, $request->user()?->id);
        } catch (ChiusuraEsercizioException $e) {
            return back()->with($this->flashError($e->getMessage()));
        }

        return to_route('admin.gestionale.esercizi.chiusura.show', [$condominio, $esercizio])
            ->with($this->flashSuccess("Chiusura annullata: l'esercizio «{$esercizio->nome}» è di nuovo aperto."));
    }

    /**
     * A quale esercizio appartiene una data, e se è già chiuso. Serve al modulo delle fatture per
     * guidare la registrazione di una fattura tardiva (vedi `AvvisoFatturaTardiva.vue`).
     */
    public function competenza(Request $request, Condominio $condominio): JsonResponse
    {
        $request->validate(['data' => ['required', 'date']]);
        $data = Carbon::parse($request->query('data'))->toDateString();

        $competenza = $condominio->esercizi()
            ->whereDate('data_inizio', '<=', $data)
            ->whereDate('data_fine', '>=', $data)
            ->orderByDesc('data_inizio')
            ->first(['id', 'nome', 'stato', 'data_inizio', 'data_fine']);

        $aperto = $condominio->esercizi()->where('stato', 'aperto')
            ->orderByDesc('data_inizio')->first(['id', 'nome', 'stato', 'data_inizio', 'data_fine']);

        $tardiva = $competenza && $competenza->stato === 'chiuso' && $aperto && $aperto->id !== $competenza->id;

        return response()->json([
            'tardiva'              => $tardiva,
            'esercizio_competenza' => $competenza?->only(['id', 'nome', 'stato']),
            'esercizio_aperto'     => $aperto?->only(['id', 'nome', 'stato']),
            'messaggio'            => $tardiva
                ? "La data cade nell'esercizio «{$competenza->nome}», che è chiuso. La fattura si registra "
                    ."nell'esercizio aperto «{$aperto->nome}» come debito pregresso, con la sua data di competenza: "
                    .'scegli la copertura (Rata 0 del nuovo piano, fondo, oppure sopravvenienza passiva). '
                    .'Il costo non entra nel consuntivo dell\'esercizio chiuso, che resta com\'è stato rendicontato.'
                : null,
        ]);
    }

    /**
     * Difesa in profondità: lo `scopeBindings()` del gruppo cerca già l'esercizio dentro il
     * condominio, ma una chiusura scrive su mezzo giornale e non deve dipendere da una sola difesa.
     */
    private function assicuraEsercizioDelCondominio(Condominio $condominio, Esercizio $esercizio): void
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);
    }

    private function residuiPerPagina($residui): array
    {
        $nomi = Anagrafica::whereIn('id', $residui->pluck('anagrafica_id')->filter())->pluck('nome', 'id');
        $immobili = Immobile::whereIn('id', $residui->pluck('immobile_id')->filter())->get()->keyBy('id');

        return $residui->map(function (array $r) use ($nomi, $immobili) {
            $scenari = [
                'riporta_riapri'  => $this->piano->ripartisci($r, PianoRiportoService::RIPORTA, 'stessa'),
                'mantieni_riapri' => $this->piano->ripartisci($r, PianoRiportoService::MANTIENI, 'stessa'),
                'mantieni_chiusa' => $this->piano->ripartisci($r, PianoRiportoService::MANTIENI, null),
                // L'ordinaria va sempre nella nuova ordinaria: lì anche i saldi liberi passano.
                'riporta_nuova'   => $this->piano->ripartisci($r, PianoRiportoService::RIPORTA, 'nuova'),
                'mantieni_nuova'  => $this->piano->ripartisci($r, PianoRiportoService::MANTIENI, 'nuova'),
            ];

            return [
                'chiave'         => $r['chiave'],
                'anagrafica_id'  => $r['anagrafica_id'],
                'anagrafica'     => $r['anagrafica_id'] ? ($nomi[$r['anagrafica_id']] ?? "#{$r['anagrafica_id']}") : 'Saldo dell\'unità (solidale)',
                'immobile_id'    => $r['immobile_id'],
                'immobile'       => $r['immobile_id'] ? ($immobili[$r['immobile_id']]?->etichetta ?? "#{$r['immobile_id']}") : '—',
                'gestione_id'    => $r['gestione_id'],
                'saldo_iniziale' => $r['saldo_iniziale'],
                'residuo'        => $r['residuo'],
                'conguaglio'     => $r['conguaglio'],
                'debito_quote'   => $r['debito_quote'],
                'credito_quote'  => $r['credito_quote'],
                'quote_aperte'   => count($r['quote_debito']),
                'extra'          => $r['extra_totale'],
                'saldi_liberi'   => $r['saldi_liberi_totale'],
                'non_emesse'     => $r['non_emesse'],
                'scenari'        => array_map(fn ($s) => [
                    'riportato' => $s['riportato'], 'mantenuto' => $s['mantenuto'], 'differenza' => $s['differenza'],
                ], $scenari),
            ];
        })->values()->all();
    }

    private function riepilogoChiusura(ChiusuraEsercizio $chiusura): array
    {
        $motivi = app(AnnullaChiusuraEsercizioAction::class)->motiviBlocco($chiusura);
        $riepilogo = $chiusura->riepilogo ?? [];

        $residui = $chiusura->residui()->with(['anagrafica:id,nome', 'immobile', 'gestione:id,nome'])->get()
            ->map(fn ($r) => [
                'anagrafica' => $r->anagrafica?->nome ?? 'Saldo dell\'unità (solidale)',
                'immobile'   => $r->immobile?->etichetta ?? '—',
                'gestione'   => $r->gestione?->nome ?? '—',
                'modalita'   => $r->modalita,
                'residuo'    => $r->residuo,
                'riportato'  => $r->riportato,
                'mantenuto'  => $r->mantenuto,
                'conguaglio' => $r->conguaglio,
            ]);

        return [
            'id'              => $chiusura->id,
            'eseguita_il'     => $chiusura->eseguita_il?->toIso8601String(),
            'autore'          => $chiusura->autore?->name,
            'esercizio_nuovo' => $chiusura->esercizioNuovo?->only(['id', 'nome', 'data_inizio', 'data_fine', 'stato', 'serie_protocollo']),
            'totali'          => $riepilogo['totali'] ?? null,
            'scritture'       => count($riepilogo['scritture'] ?? []),
            'quote_riportate' => count($riepilogo['quote_riportate'] ?? []),
            'saldi_creati'    => count($riepilogo['saldi_creati'] ?? []),
            'annullabile'     => $motivi === [],
            'motivi_blocco'   => $motivi,
            'residui'         => $residui,
        ];
    }
}
