<?php

namespace App\Http\Controllers\Assemblee\Utenti;

use App\Enums\Assemblee\StatoAssemblea;
use App\Http\Controllers\Controller;
use App\Models\Assemblee\Assemblea;
use App\Services\Assemblee\DocumentiAssembleaService;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Le assemblee viste dal condòmino: solo quelle dei suoi condomìni, e solo dopo la convocazione.
 * Sola lettura: avviso di convocazione e, quando c'è, il verbale.
 *
 * Un'assemblea in bozza risponde 404 e non 403: per il condòmino non esiste ancora.
 */
class AssembleaController extends Controller
{
    public function __construct(private DocumentiAssembleaService $documenti) {}

    public function index(): Response
    {
        $assemblee = Assemblea::query()
            ->whereIn('condominio_id', $this->condominiDellUtente())
            ->where('stato', '!=', StatoAssemblea::BOZZA->value)
            ->with(['condominio:id,nome', 'punti'])
            ->orderByDesc('prima_convocazione_at')
            ->get()
            ->map(fn (Assemblea $a) => $this->riga($a));

        return Inertia::render('assemblee/user/AssembleeList', [
            'assemblee' => $assemblee,
        ]);
    }

    public function show(Assemblea $assemblea): Response
    {
        $this->assicuraVisibile($assemblea);
        $assemblea->load(['condominio:id,nome', 'punti']);

        return Inertia::render('assemblee/user/AssembleaShow', [
            'assemblea' => $this->riga($assemblea) + [
                'prima_luogo' => $assemblea->prima_luogo,
                'seconda_luogo' => $assemblea->seconda_luogo ?: $assemblea->prima_luogo,
                'videoconferenza' => $assemblea->videoconferenza,
                'piattaforma_video' => $assemblea->piattaforma_video,
                'link_video' => $assemblea->link_video,
                'allegati' => $assemblea->elencoAllegati(),
                'punti' => $assemblea->punti->map(fn ($p) => [
                    'id' => $p->id,
                    'titolo' => $p->titolo,
                    'testo' => $p->testo,
                ])->values(),
            ],
        ]);
    }

    public function avviso(Assemblea $assemblea): HttpResponse
    {
        $this->assicuraVisibile($assemblea);

        return $this->pdf($this->documenti->avviso($assemblea), 'avviso-'.Str::slug($assemblea->titoloLeggibile()).'.pdf');
    }

    public function verbale(Assemblea $assemblea): HttpResponse
    {
        $this->assicuraVisibile($assemblea);
        abort_unless($assemblea->stato === StatoAssemblea::VERBALIZZATA, 404);

        return $this->pdf($this->documenti->verbale($assemblea), 'verbale-'.Str::slug($assemblea->titoloLeggibile()).'.pdf');
    }

    private function assicuraVisibile(Assemblea $assemblea): void
    {
        abort_unless(
            $assemblea->stato->visibileAiCondomini() && $this->condominiDellUtente()->contains($assemblea->condominio_id),
            404
        );
    }

    private function condominiDellUtente(): Collection
    {
        $anagrafica = Auth::user()?->anagrafica;

        return $anagrafica ? $anagrafica->condomini()->pluck('condomini.id') : collect();
    }

    private function riga(Assemblea $a): array
    {
        return [
            'id' => $a->id,
            'titolo' => $a->titoloLeggibile(),
            'condominio' => $a->condominio?->nome,
            'tipo_label' => $a->tipo?->label(),
            'stato' => $a->stato->value,
            'stato_label' => $a->stato->label(),
            'prima_convocazione_at' => $a->prima_convocazione_at?->toIso8601String(),
            'seconda_convocazione_at' => $a->seconda_convocazione_at?->toIso8601String(),
            'numero_registro' => $a->numeroRegistro(),
            'ha_verbale' => $a->stato === StatoAssemblea::VERBALIZZATA,
            'punti_count' => $a->punti->count(),
        ];
    }

    private function pdf(string $contenuto, string $nome): HttpResponse
    {
        return response($contenuto, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$nome.'"',
        ]);
    }
}
