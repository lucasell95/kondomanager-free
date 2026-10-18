<?php

namespace App\Http\Controllers\Gestionale\Assemblee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Assemblee\PuntoOdgRequest;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\PuntoOdg;
use App\Models\Condominio;
use App\Traits\GuardieAssemblea;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** I punti all'ordine del giorno. */
class AssembleaOdgController extends Controller
{
    use GuardieAssemblea, HandleFlashMessages;

    public function store(PuntoOdgRequest $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $this->assicuraDatiModificabili($assemblea);

        $assemblea->punti()->create($request->validated() + [
            'ordine' => (int) $assemblea->punti()->max('ordine') + 1,
        ]);

        return back()->with($this->flashSuccess('Punto aggiunto all\'ordine del giorno.'));
    }

    public function update(PuntoOdgRequest $request, Condominio $condominio, Assemblea $assemblea, PuntoOdg $punto): RedirectResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $this->assicuraDatiModificabili($assemblea);

        $punto->update($request->validated());

        return back()->with($this->flashSuccess('Punto aggiornato.'));
    }

    public function destroy(Condominio $condominio, Assemblea $assemblea, PuntoOdg $punto): RedirectResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $this->assicuraDatiModificabili($assemblea);

        DB::transaction(function () use ($assemblea, $punto) {
            $punto->delete();
            $this->rinumera($assemblea);
        });

        return back()->with($this->flashSuccess('Punto eliminato.'));
    }

    /** Sposta un punto di una posizione in su o in giù. */
    public function sposta(Request $request, Condominio $condominio, Assemblea $assemblea, PuntoOdg $punto): RedirectResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $this->assicuraDatiModificabili($assemblea);

        $request->validate(['direzione' => ['required', Rule::in(['su', 'giu'])]]);

        DB::transaction(function () use ($assemblea, $punto, $request) {
            $punti = $assemblea->punti()->get()->values();
            $i = $punti->search(fn ($p) => $p->id === $punto->id);
            $j = $request->input('direzione') === 'su' ? $i - 1 : $i + 1;

            if ($i === false || $j < 0 || $j >= $punti->count()) {
                return;
            }

            $ordine = $punti->all();
            [$ordine[$i], $ordine[$j]] = [$ordine[$j], $ordine[$i]];

            foreach ($ordine as $k => $p) {
                $p->update(['ordine' => $k + 1]);
            }
        });

        return back();
    }

    private function rinumera(Assemblea $assemblea): void
    {
        foreach ($assemblea->punti()->get()->values() as $k => $p) {
            if ($p->ordine !== $k + 1) {
                $p->update(['ordine' => $k + 1]);
            }
        }
    }
}
