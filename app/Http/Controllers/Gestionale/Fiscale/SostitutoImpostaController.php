<?php

namespace App\Http\Controllers\Gestionale\Fiscale;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Fiscale\AggiornaSostitutoRequest;
use App\Http\Requests\Gestionale\Fiscale\StoreRavvedimentoRequest;
use App\Models\Condominio;
use App\Models\Gestionale\RavvedimentoF24;
use App\Models\Gestionale\RigaF24;
use App\Services\Gestionale\Fiscale\EsportazioneProspettoService;
use App\Services\Gestionale\Fiscale\ProspettoSostitutoService;
use App\Services\PDF\PdfService;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CU e 770 del condominio in autonomia: il prospetto annuale del sostituto d'imposta.
 *
 * Una pagina per anno solare dei pagamenti, con i dati di ciascun percipiente per la CU, la
 * quadratura fra ritenute operate e versate, le righe del quadro ST e le stampe: il prospetto
 * intero (PDF, CSV, XLSX) e la CU sintetica da consegnare a ciascun fornitore.
 *
 * Tutti i numeri vengono da `ProspettoSostitutoService`: qui si sceglie l'anno, si autorizza e si
 * impagina. Il programma non trasmette niente all'Agenzia.
 */
class SostitutoImpostaController extends Controller
{
    use HandleFlashMessages;
    use HasCondomini;

    public function __construct(
        private ProspettoSostitutoService $prospetto,
        private EsportazioneProspettoService $esportazione,
    ) {}

    public function index(Request $request, Condominio $condominio): Response
    {
        $anni = $this->prospetto->anniDisponibili($condominio);
        $anno = $this->annoRichiesto($request, $anni);

        return Inertia::render('gestionale/fiscale/SostitutoImposta', [
            'condominio' => $condominio,
            'condomini' => $this->getCondomini(),
            'esercizio' => $condominio->esercizi()->where('stato', 'aperto')->latest('id')->first(),
            'anno' => $anno,
            'anni' => $anni,
            'prospetto' => $this->prospetto->prospetto($condominio, $anno),
            'codici_carica' => collect(config('fiscale.codici_carica', []))
                ->map(fn ($label, $codice) => ['value' => (string) $codice, 'label' => "{$codice} — {$label}"])
                ->values(),
            'codice_sanzione_suggerito' => config('fiscale.ravvedimento.codice_sanzione_suggerito'),
        ]);
    }

    public function pdf(Condominio $condominio, int $anno, PdfService $pdf): HttpResponse
    {
        $dati = $this->prospetto->prospetto($condominio, $this->assicuraAnnoValido($anno));

        $documento = $pdf->generate('pdf.gestionale.prospetto_sostituto', [
            'condominio' => $condominio,
            'prospetto' => $dati,
        ], ['orientation' => 'L']);

        return response($documento->Output("prospetto-cu-770-{$anno}.pdf", 'I'))
            ->header('Content-Type', 'application/pdf');
    }

    public function export(Condominio $condominio, int $anno, string $formato, Request $request): HttpResponse
    {
        $dati = $this->prospetto->prospetto($condominio, $this->assicuraAnnoValido($anno));
        $base = 'prospetto-cu-770-'.Str::slug($condominio->nome).'-'.$anno;

        if ($formato === 'xlsx') {
            return response($this->esportazione->xlsx($dati), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => "attachment; filename=\"{$base}.xlsx\"",
            ]);
        }

        $sezione = $request->string('sezione')->toString() ?: 'percipienti';
        abort_unless(in_array($sezione, EsportazioneProspettoService::SEZIONI, true), 422, 'Sezione non valida.');

        return response($this->esportazione->csv($dati, $sezione), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$base}-{$sezione}.csv\"",
        ]);
    }

    /**
     * La CU sintetica di un percipiente, da consegnargli.
     *
     * Il fornitore non è figlio del condominio nel modello dati (l'anagrafica dei fornitori è
     * comune a tutti i condomìni): per questo arriva come numero e non come modello, e il
     * vincolo lo dà il prospetto stesso — se il condominio non gli ha pagato niente con ritenuta
     * in quell'anno, non c'è nessuna certificazione e la risposta è 404.
     */
    public function cu(Condominio $condominio, int $anno, int $fornitoreId, PdfService $pdf): HttpResponse
    {
        $anno = $this->assicuraAnnoValido($anno);
        $certificazioni = $this->prospetto->certificazioniDi($condominio, $anno, $fornitoreId);

        abort_if($certificazioni === [], 404, 'Nessuna certificazione per questo percipiente nell\'anno indicato.');

        $documento = $pdf->generate('pdf.gestionale.cu_sintetica', [
            'condominio' => $condominio,
            'anno' => $anno,
            'sostituto' => $this->prospetto->sostituto($condominio),
            'certificazioni' => $certificazioni,
        ]);

        $nome = 'cu-'.($anno + 1).'-'.Str::slug($certificazioni[0]['anagrafica']['denominazione'] ?? 'percipiente').'.pdf';

        return response($documento->Output($nome, 'I'))->header('Content-Type', 'application/pdf');
    }

    public function aggiornaSostituto(AggiornaSostitutoRequest $request, Condominio $condominio): RedirectResponse
    {
        // `forceFill` perché le colonne del sostituto non sono fra i `fillable` del modello
        // Condominio, che appartiene a un'altra area: si scrive solo ciò che la request valida.
        $condominio->forceFill($request->validated())->save();

        Log::info('Dati del sostituto d\'imposta aggiornati.', [
            'condominio_id' => $condominio->id,
            'user_id' => Auth::id(),
        ]);

        return back()->with($this->flashSuccess('Dati del sostituto d\'imposta salvati.'));
    }

    public function salvaRavvedimento(StoreRavvedimentoRequest $request, Condominio $condominio): RedirectResponse
    {
        $dati = $request->validated();
        $riga = RigaF24::with('delega')->findOrFail($dati['riga_f24_id']);

        abort_if((int) $riga->delega?->condominio_id !== (int) $condominio->id, 404);

        if (! $riga->delega->isVersata()) {
            return back()->with($this->flashError('Il ravvedimento si annota su una riga di una delega già versata.'));
        }

        RavvedimentoF24::updateOrCreate(
            ['riga_f24_id' => $riga->id],
            [
                'condominio_id' => $condominio->id,
                'interessi' => $dati['interessi'],
                'sanzione' => $dati['sanzione'],
                'codice_tributo_sanzione' => $dati['codice_tributo_sanzione'] ?? null,
                'data_versamento' => $dati['data_versamento'],
                'note' => $dati['note'] ?? null,
                'user_id' => Auth::id(),
            ]
        );

        return back()->with($this->flashSuccess('Ravvedimento annotato. Ricorda di registrare a parte in contabilità interessi e sanzione versati.'));
    }

    public function eliminaRavvedimento(Condominio $condominio, int $ravvedimentoId): RedirectResponse
    {
        $ravvedimento = RavvedimentoF24::findOrFail($ravvedimentoId);

        abort_if($ravvedimento->condominio_id !== $condominio->id, 404);

        $ravvedimento->delete();

        return back()->with($this->flashSuccess('Annotazione del ravvedimento eliminata.'));
    }

    // ── Supporto ─────────────────────────────────────────────────────────────

    /**
     * L'anno chiesto, oppure quello su cui si lavora adesso.
     *
     * Fino all'invio del 770 (fine ottobre) si lavora sull'anno precedente; da novembre su
     * quello in corso, che è il prossimo da certificare.
     */
    private function annoRichiesto(Request $request, array $anni): int
    {
        if ($request->filled('anno')) {
            return $this->assicuraAnnoValido((int) $request->input('anno'));
        }

        $oggi = now();

        return $oggi->month <= 10 ? $oggi->year - 1 : $oggi->year;
    }

    /**
     * L'anno dell'indirizzo è un numero, non un figlio del condominio: la guardia sul condominio
     * è il prospetto stesso, che legge solo i pagamenti e le deleghe del condominio della rotta.
     * Qui si rifiutano gli anni senza senso.
     */
    private function assicuraAnnoValido(int $anno): int
    {
        abort_if($anno < 2000 || $anno > 2100, 404);

        return $anno;
    }
}
