<?php

namespace App\Http\Controllers\Gestionale\Banca;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Gestionale\Banca\Concerns\DatiBanca;
use App\Http\Requests\Gestionale\Banca\AnteprimaEstrattoContoRequest;
use App\Http\Requests\Gestionale\Banca\ConfermaImportazioneRequest;
use App\Models\Condominio;
use App\Models\Gestionale\Banca\ImportazioneBancaria;
use App\Models\Gestionale\Banca\ProfiloImportazione;
use App\Models\Gestionale\Cassa;
use App\Services\Gestionale\Banca\ImportazioneBancariaService;
use App\Services\Gestionale\Banca\Lettori\LettoreTabellare;
use App\Support\LimiteCaricamento;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Banca → Importa: caricamento dell'estratto conto, anteprima con mappatura delle colonne,
 * conferma.
 */
class ImportazioneBancaController extends Controller
{
    use DatiBanca, HandleFlashMessages, HasCondomini, HasEsercizio;

    public function __construct(private readonly ImportazioneBancariaService $service) {}

    public function create(Condominio $condominio): Response
    {
        return Inertia::render('gestionale/banca/BancaImporta', $this->datiIntestazione($condominio) + [
            'casse' => $this->casseImportabili($condominio),
            'profili' => ProfiloImportazione::where('condominio_id', $condominio->id)
                ->get(['id', 'cassa_id', 'nome', 'updated_at']),
            'importazioni' => ImportazioneBancaria::where('condominio_id', $condominio->id)
                ->with('cassa:id,nome')
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (ImportazioneBancaria $i) => [
                    'id' => $i->id,
                    'cassa' => $i->cassa?->nome,
                    'formato' => $i->formato,
                    'nome_file' => $i->nome_file,
                    'data_da' => $i->data_da?->toDateString(),
                    'data_a' => $i->data_a?->toDateString(),
                    'righe_importate' => $i->righe_importate,
                    'righe_duplicate' => $i->righe_duplicate,
                    'righe_collegate' => $i->righe_collegate,
                    'creata' => $i->created_at?->toDateTimeString(),
                ]),
            'ruoli' => LettoreTabellare::RUOLI,
            'limiteFile' => LimiteCaricamento::etichetta(),
        ]);
    }

    /**
     * Primo invio: il file. Invii successivi: il gettone e la mappatura corretta. Risponde JSON,
     * perché l'anteprima si aggiorna sul posto mentre l'utente sistema le colonne.
     */
    public function anteprima(AnteprimaEstrattoContoRequest $request, Condominio $condominio): JsonResponse
    {
        $cassa = Cassa::where('condominio_id', $condominio->id)->findOrFail($request->integer('cassa_id'));

        try {
            $token = $request->hasFile('file')
                ? $this->service->conserva($request->file('file'), $cassa, $request->user()?->id)
                : (string) $request->input('token');

            $meta = $this->service->meta($token, $cassa);
            $estratto = $this->service->leggi($cassa, $meta, $this->configurazioneDa($request));

            return response()->json([
                'token' => $token,
                'anteprima' => $this->service->anteprima($cassa, $estratto, $meta),
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function conferma(ConfermaImportazioneRequest $request, Condominio $condominio): RedirectResponse
    {
        $cassa = Cassa::where('condominio_id', $condominio->id)->findOrFail($request->integer('cassa_id'));
        $token = (string) $request->input('token');

        try {
            $meta = $this->service->meta($token, $cassa);
            $estratto = $this->service->leggi($cassa, $meta, $this->configurazioneDa($request));

            if ($estratto->movimenti === []) {
                return back()->withErrors(['importazione' => 'Nessun movimento da importare: controlla la mappatura delle colonne.']);
            }

            $scelte = $request->only([
                'importa_possibili_duplicati', 'collega',
                'saldo_iniziale', 'data_saldo_iniziale', 'saldo_finale', 'data_saldo_finale',
            ]);
            $scelte['salva_profilo'] = $request->boolean('salva_profilo', true);

            $importazione = $this->service->conferma($cassa, $estratto, $meta, $scelte, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['importazione' => $e->getMessage()]);
        }

        $this->service->scarta($token);

        return to_route('admin.gestionale.banca.movimenti.index', [
            'condominio' => $condominio->id,
            'cassa_id' => $cassa->id,
        ])->with($this->flashSuccess(sprintf(
            'Importati %d movimenti (%d già presenti saltati, %d collegati a scritture già registrate).',
            $importazione->righe_importate,
            $importazione->righe_duplicate,
            $importazione->righe_collegate,
        )));
    }

    public function scarta(Request $request, Condominio $condominio): JsonResponse
    {
        $validato = $request->validate(['token' => ['required', 'uuid'], 'cassa_id' => ['required', 'integer']]);
        $cassa = Cassa::where('condominio_id', $condominio->id)->findOrFail($validato['cassa_id']);

        try {
            $this->service->meta($validato['token'], $cassa);
            $this->service->scarta($validato['token']);
        } catch (RuntimeException) {
            // Già scaduto: niente da togliere.
        }

        return response()->json(['ok' => true]);
    }

    /** La mappatura scelta nell'anteprima vale più del profilo salvato. */
    private function configurazioneDa(Request $request): ?array
    {
        $config = $request->input('configurazione');

        if (! is_array($config) || $config === []) {
            return null;
        }

        $config['da_anteprima'] = true;
        $config['colonne'] = array_map(
            fn ($v) => $v === null || $v === '' ? null : (int) $v,
            array_intersect_key((array) ($config['colonne'] ?? []), LettoreTabellare::RUOLI)
        );
        $config['riga_intestazione'] = isset($config['riga_intestazione']) && $config['riga_intestazione'] !== ''
            ? (int) $config['riga_intestazione'] : null;
        $config['inverti_segno'] = filter_var($config['inverti_segno'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return $config;
    }
}
