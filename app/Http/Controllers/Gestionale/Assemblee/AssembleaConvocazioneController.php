<?php

namespace App\Http\Controllers\Gestionale\Assemblee;

use App\Enums\Assemblee\MezzoConvocazione;
use App\Enums\Assemblee\StatoAssemblea;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Assemblee\ComunicazioneVerbaleRequest;
use App\Http\Requests\Gestionale\Assemblee\DestinatarioRequest;
use App\Models\Anagrafica;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\DestinatarioConvocazione;
use App\Models\Condominio;
use App\Services\Assemblee\TesteAssembleaService;
use App\Traits\GuardieAssemblea;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Destinatari dell'avviso con il mezzo e la prova di ricezione, e comunicazione del verbale agli
 * assenti (art. 1137 c.c.: per loro il termine d'impugnazione decorre dalla comunicazione).
 */
class AssembleaConvocazioneController extends Controller
{
    use GuardieAssemblea, HandleFlashMessages;

    public function __construct(private TesteAssembleaService $teste) {}

    /**
     * Crea un destinatario per ogni titolare (proprietari, nudi proprietari e usufruttuari: con
     * l'usufrutto l'avviso va a entrambi, art. 67 c. 7 disp. att.). Chi c'è già non si duplica.
     * Mezzo proposto: PEC se l'anagrafica ne ha una, altrimenti raccomandata.
     */
    public function genera(Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $this->assicuraDatiModificabili($assemblea);

        $esistenti = $assemblea->destinatari()->pluck('anagrafica_id')->filter()->all();
        $persone = collect($this->teste->persone($assemblea))->reject(fn ($p) => in_array($p['anagrafica_id'], $esistenti));
        $anagrafiche = Anagrafica::whereIn('id', $persone->pluck('anagrafica_id'))->get()->keyBy('id');

        foreach ($persone as $persona) {
            $a = $anagrafiche->get($persona['anagrafica_id']);
            $pec = $a?->pec;

            $assemblea->destinatari()->create([
                'anagrafica_id' => $persona['anagrafica_id'],
                'nominativo' => $persona['nome'],
                'ruolo' => implode(', ', $persona['ruoli']),
                'mezzo' => $pec ? MezzoConvocazione::PEC : MezzoConvocazione::RACCOMANDATA,
                'recapito' => $pec ?: $a?->indirizzo,
            ]);
        }

        return back()->with($this->flashSuccess($persone->isEmpty()
            ? 'Tutti i titolari sono già fra i destinatari.'
            : 'Aggiunti '.$persone->count().' destinatari. Verificare mezzo e recapito di ciascuno.'));
    }

    public function store(DestinatarioRequest $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $this->assicuraDatiModificabili($assemblea);

        $assemblea->destinatari()->create($request->validated());

        return back()->with($this->flashSuccess('Destinatario aggiunto.'));
    }

    public function update(DestinatarioRequest $request, Condominio $condominio, Assemblea $assemblea, DestinatarioConvocazione $destinatario): RedirectResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $this->assicuraDatiModificabili($assemblea);

        $destinatario->update($request->validated());

        return back()->with($this->flashSuccess('Convocazione di '.$destinatario->nominativo.' aggiornata.'));
    }

    public function destroy(Condominio $condominio, Assemblea $assemblea, DestinatarioConvocazione $destinatario): RedirectResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);
        $this->assicuraDatiModificabili($assemblea);

        $destinatario->delete();

        return back()->with($this->flashSuccess('Destinatario tolto.'));
    }

    /** Registra l'invio e la ricezione del verbale a un assente. */
    public function comunicazioneVerbale(ComunicazioneVerbaleRequest $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        $this->assicuraAssemblea($condominio, $assemblea);

        if (! in_array($assemblea->stato, [StatoAssemblea::SVOLTA, StatoAssemblea::VERBALIZZATA], true)) {
            throw ValidationException::withMessages(['stato' => 'Il verbale si comunica dopo lo svolgimento dell\'assemblea.']);
        }

        $request->validate(['anagrafica_id' => ['required', 'integer']]);
        $anagraficaId = (int) $request->input('anagrafica_id');

        $persona = collect($this->teste->persone($assemblea))->firstWhere('anagrafica_id', $anagraficaId);
        abort_unless($persona !== null, 404);

        $destinatario = $assemblea->destinatari()->firstOrCreate(
            ['anagrafica_id' => $anagraficaId],
            ['nominativo' => $persona['nome'], 'ruolo' => implode(', ', $persona['ruoli']), 'mezzo' => MezzoConvocazione::RACCOMANDATA]
        );

        $destinatario->update($request->validated());

        return back()->with($this->flashSuccess('Comunicazione del verbale a '.$persona['nome'].' registrata.'));
    }
}
