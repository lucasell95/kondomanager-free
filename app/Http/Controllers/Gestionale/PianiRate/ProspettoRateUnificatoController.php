<?php

namespace App\Http\Controllers\Gestionale\PianiRate;

use App\Http\Controllers\Controller;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Services\Gestionale\ProspettoRateUnificatoService;
use App\Services\PDF\PdfService;
use App\Traits\HasCondomini;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Prospetto rate unificato: per ogni condòmino, le rate di **tutti** i piani dell'esercizio
 * sommate per data di scadenza.
 *
 * Sola lettura. Le gestioni restano separate in contabilità; qui si guarda soltanto la somma, con
 * il dettaglio per piano sempre a portata di mano. Il calcolo vive in
 * `ProspettoRateUnificatoService`, che riusa `PianoRateQuoteService::quotePerAnagrafica()` — la
 * stessa fonte della pagina del singolo piano — così i numeri delle due schermate coincidono.
 */
class ProspettoRateUnificatoController extends Controller
{
    use HasCondomini;

    public function __construct(
        private readonly ProspettoRateUnificatoService $prospettoService,
    ) {}

    /**
     * La pagina del prospetto.
     *
     * @param Condominio $condominio Il condominio corrente
     * @param Esercizio $esercizio L'esercizio di cui sommare i piani rate
     * @return Response
     */
    public function show(Condominio $condominio, Esercizio $esercizio): Response
    {
        // Difesa in profondità: lo scoping delle rotte lega già l'esercizio al condominio.
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);

        $esercizi = $condominio->esercizi()
            ->orderBy('data_inizio', 'desc')
            ->get(['id', 'nome', 'stato']);

        return Inertia::render('gestionale/pianiRate/ProspettoRateUnificato', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'esercizi'   => $esercizi,
            'condomini'  => CondominioResource::collection($this->getCondomini()),
            'prospetto'  => $this->prospettoService->calcola($condominio, $esercizio),
        ]);
    }

    /**
     * Stampa PDF del prospetto (landscape).
     *
     * Query param `?dettaglio=0|1` (default 1): con il dettaglio, sotto ogni condòmino compare
     * una riga per piano con la sua parte di ciascuna scadenza.
     *
     * @param Request $request
     * @param Condominio $condominio
     * @param Esercizio $esercizio
     * @param PdfService $pdfService
     */
    public function print(Request $request, Condominio $condominio, Esercizio $esercizio, PdfService $pdfService)
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);

        $prospetto = $this->prospettoService->calcola($condominio, $esercizio);
        $conDettaglio = $request->boolean('dettaglio', true);

        // Stesso criterio delle altre stampe del piano: oltre una certa larghezza si passa all'A3.
        $formato = count($prospetto['scadenze']) > 6 ? 'A3-L' : 'A4-L';

        $mpdf = $pdfService->generate('pdf.gestionale.prospetto_rate_unificato', [
            'condominio'   => $condominio,
            'esercizio'    => $esercizio,
            'prospetto'    => $prospetto,
            'conDettaglio' => $conDettaglio,
        ], [
            'format'       => $formato,
            'orientation'  => 'L',
            'margin_top'   => 32,
            'margin_left'  => 8,
            'margin_right' => 8,
        ]);

        $mpdf->SetHeader($condominio->nome . '||Prospetto rate unificato – ' . $esercizio->nome);

        return response($mpdf->Output('prospetto_rate_unificato.pdf', 'I'))
            ->header('Content-Type', 'application/pdf');
    }
}
