<?php

namespace App\Http\Controllers\Gestionale\Banca;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Gestionale\Banca\Concerns\DatiBanca;
use App\Models\Condominio;
use App\Models\Gestionale\Banca\ImportazioneBancaria;
use App\Models\Gestionale\Banca\SaldoEstrattoConto;
use App\Models\Gestionale\Cassa;
use App\Services\Gestionale\Banca\RiconciliazioneBancariaService;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Banca → Riconciliazione: saldo dell'estratto conto contro saldo contabile, per conto e periodo. */
class RiconciliazioneBancaController extends Controller
{
    use DatiBanca, HandleFlashMessages, HasCondomini, HasEsercizio;

    public function __construct(private readonly RiconciliazioneBancariaService $service) {}

    public function index(Request $request, Condominio $condominio): Response
    {
        $dati = $request->validate([
            'cassa_id' => ['nullable', 'integer'],
            'da' => ['nullable', 'date'],
            'a' => ['nullable', 'date', 'after_or_equal:da'],
            'includi_fondi' => ['nullable', 'boolean'],
        ]);

        $casse = $this->casseImportabili($condominio);
        $cassa = Cassa::where('condominio_id', $condominio->id)
            ->whereIn('tipo', ['banca', 'contanti', 'virtuale'])
            ->when($dati['cassa_id'] ?? null, fn ($q, $id) => $q->whereKey($id), fn ($q) => $q->whereKey($casse->first()['id'] ?? 0))
            ->first();

        $haFondi = Cassa::where('condominio_id', $condominio->id)->where('tipo', 'fondo')->exists();
        $risultato = null;
        $periodo = null;

        if ($cassa) {
            // Periodo predefinito: quello dell'ultimo estratto importato, altrimenti il mese scorso.
            $ultimo = ImportazioneBancaria::where('cassa_id', $cassa->id)->whereNotNull('data_a')->latest('data_a')->first();
            $da = isset($dati['da']) ? CarbonImmutable::parse($dati['da'])
                : ($ultimo?->data_da ? CarbonImmutable::parse($ultimo->data_da) : now()->toImmutable()->subMonthNoOverflow()->startOfMonth());
            $a = isset($dati['a']) ? CarbonImmutable::parse($dati['a'])
                : ($ultimo?->data_a ? CarbonImmutable::parse($ultimo->data_a) : $da->endOfMonth());
            $includiFondi = array_key_exists('includi_fondi', $dati)
                ? (bool) $dati['includi_fondi']
                : ($cassa->tipo === 'banca' && $haFondi);

            $risultato = $this->service->calcola($cassa, $da->startOfDay(), $a->startOfDay(), null, null, $includiFondi);
            $periodo = ['da' => $da->toDateString(), 'a' => $a->toDateString()];
        }

        return Inertia::render('gestionale/banca/BancaRiconciliazione', $this->datiIntestazione($condominio) + [
            'casse' => $casse,
            'cassa_id' => $cassa?->id,
            'periodo' => $periodo,
            'ha_fondi' => $haFondi,
            'riconciliazione' => $risultato,
        ]);
    }

    /** Saldo dell'estratto conto inserito a mano, a fine giornata. */
    public function salvaSaldo(Request $request, Condominio $condominio): RedirectResponse
    {
        $dati = $request->validate([
            'cassa_id' => ['required', 'integer', Rule::exists('casse', 'id')->where('condominio_id', $condominio->id)],
            'data' => ['required', 'date'],
            'saldo' => ['required', 'integer'],
        ]);

        SaldoEstrattoConto::imposta($condominio->id, (int) $dati['cassa_id'], $dati['data'], (int) $dati['saldo'], 'manuale');

        return back()->with($this->flashSuccess('Saldo dell\'estratto conto salvato.'));
    }
}
