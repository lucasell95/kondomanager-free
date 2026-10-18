<?php

namespace App\Http\Controllers\Gestionale\Assemblee;

use App\Enums\Assemblee\StatoAssemblea;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Assemblee\RegistraVerbaleRequest;
use App\Models\Assemblee\Assemblea;
use App\Models\Condominio;
use App\Services\Assemblee\BozzeAssembleaService;
use App\Services\Assemblee\DocumentiAssembleaService;
use App\Services\Assemblee\RegistroVerbaliService;
use App\Traits\GuardieAssemblea;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Stampe PDF, bozze di testo e registrazione del verbale. */
class AssembleaDocumentiController extends Controller
{
    use GuardieAssemblea, HandleFlashMessages;

    public function __construct(
        private DocumentiAssembleaService $documenti,
        private RegistroVerbaliService $registro,
        private BozzeAssembleaService $bozze,
    ) {}

    /** avviso | presenze | verbale | assenti */
    public function stampa(Condominio $condominio, Assemblea $assemblea, string $documento): Response
    {
        $this->assicuraAssemblea($condominio, $assemblea);

        $pdf = match ($documento) {
            'avviso' => $this->documenti->avviso($assemblea),
            'presenze' => $this->documenti->foglioPresenze($assemblea),
            'verbale' => $this->documenti->verbale($assemblea),
            'assenti' => $this->documenti->letteraAssenti($assemblea),
            default => abort(404),
        };

        return $this->pdf($pdf, $documento.'-'.Str::slug($assemblea->titoloLeggibile()).'.pdf');
    }

    public function registroPdf(Condominio $condominio): Response
    {
        return $this->pdf($this->documenti->registro($condominio), 'registro-verbali.pdf');
    }

    /**
     * Bozza di testo: `convocazione` (formulazione dei punti) o `verbale` (discussione e dispositivo).
     * Il testo non si salva: la schermata mostra la proposta e l'utente decide. Sull'assemblea resta
     * solo la traccia (fonte, modello, versione del prompt, avvisi) in `bozze_ia`.
     */
    public function bozza(Condominio $condominio, Assemblea $assemblea, string $tipo): JsonResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);

        // La chiamata al servizio di IA può superare il minuto: la schermata mostra l'attesa.
        @set_time_limit(240);

        return response()->json(match ($tipo) {
            BozzeAssembleaService::TIPO_CONVOCAZIONE => $this->bozze->convocazione($assemblea),
            BozzeAssembleaService::TIPO_VERBALE => $this->bozze->verbale($assemblea),
            default => abort(404),
        });
    }

    /** Anteprima di ciò che verrebbe inviato al servizio di IA per la bozza, prima di inviarlo. */
    public function contestoBozza(Condominio $condominio, Assemblea $assemblea, string $tipo): JsonResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);

        return response()->json([
            'ia_disponibile' => $this->bozze->iaDisponibile(),
            'contesto' => $this->bozze->anteprimaContesto($assemblea, $tipo),
        ]);
    }

    /** Numera il verbale nel registro e lo archivia in Documenti → Verbali. */
    public function registra(RegistraVerbaleRequest $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);

        if ($assemblea->stato !== StatoAssemblea::SVOLTA) {
            throw ValidationException::withMessages([
                'stato' => 'Si registra il verbale di un\'assemblea svolta (presenze e votazioni chiuse).',
            ]);
        }

        $this->registro->registra(
            $assemblea,
            (int) $request->user()->id,
            $request->file('file'),
            (bool) $request->boolean('pubblica'),
        );

        return back()->with($this->flashSuccess('Verbale n. '.$assemblea->numeroRegistro().' registrato e archiviato nei documenti (categoria Verbali).'));
    }

    private function pdf(string $contenuto, string $nome): Response
    {
        return response($contenuto, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$nome.'"',
        ]);
    }
}
