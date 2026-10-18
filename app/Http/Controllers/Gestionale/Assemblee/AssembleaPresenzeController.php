<?php

namespace App\Http\Controllers\Gestionale\Assemblee;

use App\Enums\Assemblee\ModoPresenza;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Assemblee\DichiarazioneRequest;
use App\Http\Requests\Gestionale\Assemblee\PresenzaRequest;
use App\Http\Requests\Gestionale\Assemblee\VotiRequest;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\DichiarazioneVerbale;
use App\Models\Assemblee\PuntoOdg;
use App\Models\Condominio;
use App\Services\Assemblee\DelegheService;
use App\Services\Assemblee\RiepilogoAssembleaService;
use App\Services\Assemblee\TesteAssembleaService;
use App\Traits\GuardieAssemblea;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Il giorno dell'assemblea: presenze e deleghe, presenze e voti punto per punto, testi del verbale,
 * dichiarazioni a verbale.
 */
class AssembleaPresenzeController extends Controller
{
    use GuardieAssemblea, HandleFlashMessages;

    public function __construct(
        private TesteAssembleaService $teste,
        private DelegheService $deleghe,
        private RiepilogoAssembleaService $riepilogo,
    ) {}

    /** Presenza (o delega) di una persona all'apertura. */
    public function presenza(PresenzaRequest $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $this->assicuraPresenzeModificabili($assemblea);

        $dati = $request->validated();
        $anagraficaId = (int) $dati['anagrafica_id'];

        $titolare = collect($this->teste->persone($assemblea))->contains('anagrafica_id', $anagraficaId);
        if (! $titolare) {
            throw ValidationException::withMessages(['anagrafica_id' => 'Questa persona non è titolare di unità del condominio per questa assemblea.']);
        }

        $modo = ModoPresenza::from($dati['modo']);

        if ($modo === ModoPresenza::DELEGATO) {
            $errori = $this->deleghe->errori(
                $assemblea,
                $anagraficaId,
                isset($dati['delegato_anagrafica_id']) ? (int) $dati['delegato_anagrafica_id'] : null,
                $dati['delegato_nome'] ?? null,
                (bool) ($dati['delega_scritta'] ?? true),
            );

            if ($errori !== []) {
                throw ValidationException::withMessages(['delegato' => $errori]);
            }
        } else {
            $dati['delegato_anagrafica_id'] = null;
            $dati['delegato_nome'] = null;
        }

        $assemblea->presenze()->updateOrCreate(['anagrafica_id' => $anagraficaId], $dati);

        return back()->with($this->flashSuccess('Presenza registrata.'));
    }

    /** Presenze e voti di tutte le teste su un punto. */
    public function voti(VotiRequest $request, Condominio $condominio, Assemblea $assemblea, PuntoOdg $punto): RedirectResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $this->assicuraPresenzeModificabili($assemblea);

        $righe = $this->righeValide($assemblea, $punto, $request->validated()['voti']);

        DB::transaction(function () use ($punto, $righe) {
            foreach ($righe as $r) {
                $punto->voti()->updateOrCreate(['chiave_testa' => $r['chiave_testa']], $r);
            }
        });

        return back()->with($this->flashSuccess('Votazione del punto «'.$punto->titolo.'» salvata.'));
    }

    /** Esito calcolato su presenze e voti non ancora salvati: il calcolo in tempo reale della schermata. */
    public function anteprima(VotiRequest $request, Condominio $condominio, Assemblea $assemblea, PuntoOdg $punto): JsonResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);

        $righe = $this->righeValide($assemblea, $punto, $request->validated()['voti']);

        return response()->json($this->riepilogo->punto($assemblea, $punto, $righe));
    }

    /** Discussione e testo della delibera del punto, per il verbale. */
    public function testi(Request $request, Condominio $condominio, Assemblea $assemblea, PuntoOdg $punto): RedirectResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $this->assicuraPresenzeModificabili($assemblea);

        $dati = $request->validate([
            'discussione' => ['nullable', 'string', 'max:20000'],
            'testo_delibera' => ['nullable', 'string', 'max:20000'],
        ]);

        $punto->update($dati);

        return back()->with($this->flashSuccess('Testo del verbale per il punto «'.$punto->titolo.'» salvato.'));
    }

    public function storeDichiarazione(DichiarazioneRequest $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $this->assicuraPresenzeModificabili($assemblea);

        $dati = $request->validated();

        if (! empty($dati['assemblea_punto_id']) && ! $assemblea->punti()->whereKey($dati['assemblea_punto_id'])->exists()) {
            throw ValidationException::withMessages(['assemblea_punto_id' => 'Il punto indicato non appartiene a questa assemblea.']);
        }

        if (! empty($dati['anagrafica_id']) && ! collect($this->teste->persone($assemblea))->contains('anagrafica_id', (int) $dati['anagrafica_id'])) {
            throw ValidationException::withMessages(['anagrafica_id' => 'Il dichiarante indicato non è un condòmino di questa assemblea.']);
        }

        $assemblea->dichiarazioni()->create($dati);

        return back()->with($this->flashSuccess('Dichiarazione a verbale registrata.'));
    }

    public function destroyDichiarazione(Condominio $condominio, Assemblea $assemblea, DichiarazioneVerbale $dichiarazione): RedirectResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $this->assicuraPresenzeModificabili($assemblea);

        $dichiarazione->delete();

        return back()->with($this->flashSuccess('Dichiarazione tolta.'));
    }

    /**
     * Tiene solo le teste che votano davvero su quel punto: una chiave inventata non deve entrare
     * nel calcolo. Chi non è presente non vota.
     */
    private function righeValide(Assemblea $assemblea, PuntoOdg $punto, array $voti): array
    {
        $chiavi = array_map(fn ($t) => $t->chiave, $this->teste->testePunto($assemblea, $punto)['teste']);

        $righe = [];
        foreach ($voti as $v) {
            if (! in_array($v['chiave_testa'], $chiavi, true)) {
                throw ValidationException::withMessages(['voti' => 'Votante non riconosciuto per questo punto: aggiornare la pagina.']);
            }

            $presente = (bool) $v['presente'];
            $righe[] = [
                'chiave_testa' => $v['chiave_testa'],
                'presente' => $presente,
                'voto' => $presente ? ($v['voto'] ?? null) : null,
                'conflitto' => (bool) ($v['conflitto'] ?? false),
            ];
        }

        return $righe;
    }
}
