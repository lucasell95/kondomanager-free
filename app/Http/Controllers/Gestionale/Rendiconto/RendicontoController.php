<?php

namespace App\Http\Controllers\Gestionale\Rendiconto;

use App\Contracts\IA\GeneratoreTesto;
use App\Helpers\MoneyHelper;
use App\Http\Controllers\Controller;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\RendicontoEsercizio;
use App\Services\Gestionale\Rendiconto\NotaRendicontoService;
use App\Services\Gestionale\Rendiconto\RegistroContabilitaService;
use App\Services\Gestionale\Rendiconto\RendicontoExcelExporter;
use App\Services\Gestionale\Rendiconto\RendicontoService;
use App\Services\PDF\PdfService;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rendiconto condominiale annuale (art. 1130-bis c.c.) di un esercizio: pagina, stampa PDF,
 * esportazione XLSX, nota sintetica.
 *
 * La contabilità si legge e basta. Si scrivono solo la nota sintetica e i saldi degli estratti
 * conto (`rendiconti_esercizio`), che non sono scritture: si possono aggiornare anche su un
 * esercizio chiuso, perché il rendiconto si prepara proprio dopo la chiusura.
 */
class RendicontoController extends Controller
{
    use HandleFlashMessages, HasCondomini;

    private const SEZIONI = ['tutto', 'riepilogo', 'registro', 'consuntivo', 'riparto', 'nota'];

    public function __construct(
        private readonly RendicontoService $rendiconti,
        private readonly NotaRendicontoService $note,
    ) {}

    public function show(Condominio $condominio, Esercizio $esercizio): Response
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);

        $rendiconto = $this->rendiconti->calcola($condominio, $esercizio);
        $salvati = $this->rendiconti->datiSalvati($esercizio);

        return Inertia::render('gestionale/rendiconto/RendicontoShow', [
            'condominio'      => $condominio,
            'condomini'       => CondominioResource::collection($this->getCondomini()),
            'esercizio'       => $esercizio,
            'esercizi'        => $condominio->esercizi()->orderBy('data_inizio', 'desc')->get(['id', 'nome', 'stato', 'data_inizio', 'data_fine']),
            'rendiconto'      => $rendiconto,
            'nota'            => $this->rendiconti->nota($rendiconto, $salvati),
            'iaDisponibile'   => app(GeneratoreTesto::class)->disponibile(),
        ]);
    }

    /**
     * Salva nota sintetica e saldi degli estratti conto. Gli importi arrivano in euro (stringa
     * italiana o numero) e si salvano in centesimi, per conto di liquidità.
     */
    public function aggiorna(Request $request, Condominio $condominio, Esercizio $esercizio): RedirectResponse
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);

        $dati = $request->validate([
            'nota_sintetica'         => ['nullable', 'string', 'max:60000'],
            'fonte_nota'             => ['nullable', 'string', 'in:modello,ia,manuale'],
            'modello_ia'             => ['nullable', 'string', 'max:100'],
            'versione_prompt_ia'     => ['nullable', 'string', 'max:50'],
            'saldi_estratto_conto'   => ['nullable', 'array'],
            'saldi_estratto_conto.*' => ['nullable'],
        ]);

        // Solo conti di liquidità di questo condominio: un id di un altro condominio si ignora.
        $contiValidi = app(RegistroContabilitaService::class)->contiLiquidita($condominio)->keys()->all();
        $saldi = [];
        foreach ((array) ($dati['saldi_estratto_conto'] ?? []) as $contoId => $valore) {
            if (! in_array((int) $contoId, $contiValidi, true) || $valore === null || $valore === '') continue;
            $saldi[(int) $contoId] = MoneyHelper::toCents($valore);
        }

        $attuale = RendicontoEsercizio::firstOrNew(['esercizio_id' => $esercizio->id], ['condominio_id' => $condominio->id]);

        $attributi = ['condominio_id' => $condominio->id, 'updated_by' => $request->user()?->id];
        if ($request->has('nota_sintetica')) {
            $nota = $dati['nota_sintetica'] ?? null;
            $attributi['nota_sintetica'] = ($nota !== null && trim($nota) !== '') ? $nota : null;
            $attributi['fonte_nota'] = $attributi['nota_sintetica'] === null ? null : ($dati['fonte_nota'] ?? 'manuale');
            // Modello e versione del prompt restano solo finché la nota è quella della bozza IA.
            $ia = $attributi['fonte_nota'] === 'ia';
            $attributi['modello_ia'] = $ia ? ($dati['modello_ia'] ?? null) : null;
            $attributi['versione_prompt_ia'] = $ia ? ($dati['versione_prompt_ia'] ?? null) : null;
        }
        if ($request->has('saldi_estratto_conto')) {
            $attributi['saldi_estratto_conto'] = $saldi;
        }

        $attuale->fill($attributi)->save();

        return back()->with($this->flashSuccess('Rendiconto aggiornato.'));
    }

    /** Bozza della nota sintetica (IA se configurata, altrimenti il modello predefinito). Non salva. */
    public function bozzaNota(Request $request, Condominio $condominio, Esercizio $esercizio): JsonResponse
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);

        $dati = $request->validate(['istruzioni' => ['nullable', 'string', 'max:4000']]);

        // La generazione con IA può durare un paio di minuti.
        @set_time_limit(240);

        $rendiconto = $this->rendiconti->calcola($condominio, $esercizio);
        $bozza = $this->note->bozza($rendiconto, $dati['istruzioni'] ?? null);
        $compilata = $this->note->compila($bozza['testo'], $this->note->fatti($rendiconto));

        return response()->json($bozza + ['compilata' => $compilata['testo'], 'mancanti' => $compilata['mancanti']]);
    }

    /**
     * Stampa PDF. `?sezione=` stampa una parte sola (riepilogo, registro, consuntivo, riparto,
     * nota); di default il rendiconto completo. Il riparto va in A4 orizzontale.
     */
    public function pdf(Request $request, Condominio $condominio, Esercizio $esercizio, PdfService $pdfService)
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);

        $sezione = in_array($request->query('sezione'), self::SEZIONI, true) ? $request->query('sezione') : 'tutto';

        $rendiconto = $this->rendiconti->calcola($condominio, $esercizio);
        $nota = $this->rendiconti->nota($rendiconto, $this->rendiconti->datiSalvati($esercizio));

        $soloRiparto = $sezione === 'riparto';
        $mpdf = $pdfService->generate('pdf.gestionale.rendiconto', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'r'          => $rendiconto,
            'nota'       => $nota,
            'sezione'    => $sezione,
        ], [
            'format'       => 'A4',
            'orientation'  => $soloRiparto ? 'L' : 'P',
            'margin_top'   => 32,
            'margin_left'  => 10,
            'margin_right' => 10,
        ]);

        $mpdf->SetTitle('Rendiconto '.$esercizio->nome);
        $mpdf->SetHeader($condominio->nome.'||Rendiconto '.$esercizio->nome);

        $nome = 'rendiconto_'.Str::slug(str_replace('/', '-', $esercizio->nome)).($sezione !== 'tutto' ? '_'.$sezione : '').'.pdf';

        return response($mpdf->Output($nome, 'S'))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$nome.'"');
    }

    public function xlsx(Condominio $condominio, Esercizio $esercizio, RendicontoExcelExporter $exporter)
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);

        $rendiconto = $this->rendiconti->calcola($condominio, $esercizio);
        $nota = $this->rendiconti->nota($rendiconto, $this->rendiconti->datiSalvati($esercizio));

        $percorso = $exporter->esporta($rendiconto, $nota);

        return response()->download($percorso, 'rendiconto_'.Str::slug(str_replace('/', '-', $esercizio->nome)).'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }
}
