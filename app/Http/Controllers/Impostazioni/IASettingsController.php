<?php

namespace App\Http\Controllers\Impostazioni;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateIASettingsRequest;
use App\Models\UtilizzoIA;
use App\Services\IA\ClientAnthropic;
use App\Services\IA\ErroreAnthropic;
use App\Services\IA\RegistroUtilizzoIA;
use App\Settings\IASettings;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Impostazioni dell'assistenza IA (bozze di convocazioni, verbali, note di preventivo e rendiconto).
 *
 * ⚠️ La chiave API non arriva **mai** al browser, nemmeno cifrata: la pagina riceve solo se è
 * impostata, se è leggibile e le ultime quattro cifre. Per cambiarla si scrive la nuova; il campo
 * vuoto lascia quella salvata (come la password SMTP).
 */
class IASettingsController extends Controller
{
    use HandleFlashMessages;

    public function edit(IASettings $settings, RegistroUtilizzoIA $registro): Response
    {
        Gate::authorize('manage', $settings);

        $chiave = $settings->chiaveInChiaro();
        $tetto = $settings->tetto_spesa_mensile_centesimi;

        return Inertia::render('impostazioni/impostazioniIA', [
            'impostazioni' => [
                'ia_abilitata'              => $settings->ia_abilitata,
                'modello'                   => $settings->modello,
                'tetto_spesa_mensile'       => $tetto === null ? null : number_format($tetto / 100, 2, '.', ''),
                'informativa_confermata_il' => $settings->informativa_confermata_il,
            ],
            'chiave' => [
                'impostata' => ! empty($settings->api_key),
                'leggibile' => $chiave !== null,
                'finale'    => $chiave !== null ? mb_substr($chiave, -4) : null,
            ],
            'modelli_suggeriti'   => config('ia.modelli_suggeriti'),
            'modello_predefinito' => config('ia.modello_predefinito'),
            'spesa' => [
                'mese_usd'  => round($registro->spesaMese() / 1_000_000, 4),
                'tetto_usd' => $tetto === null ? null : $tetto / 100,
                'chiamate'  => UtilizzoIA::query()->where('created_at', '>=', now()->startOfMonth())->count(),
            ],
            'utilizzi' => UtilizzoIA::query()->latest('id')->limit(10)->get()->map(fn (UtilizzoIA $u) => [
                'id'             => $u->id,
                'data'           => $u->created_at?->toIso8601String(),
                'tipo_documento' => $u->tipo_documento,
                'modello'        => $u->modello,
                'token_ingresso' => $u->token_ingresso,
                'token_uscita'   => $u->token_uscita,
                'costo_usd'      => round($u->costo_microdollari / 1_000_000, 4),
                'esito'          => $u->esito,
                'codice_errore'  => $u->codice_errore,
            ]),
        ]);
    }

    public function update(UpdateIASettingsRequest $request, IASettings $settings): RedirectResponse
    {
        Gate::authorize('manage', $settings);

        $validati = $request->validated();

        try {
            if ($request->boolean('rimuovi_chiave')) {
                $settings->api_key = null;
            }
            if ($request->filled('api_key')) {
                $settings->api_key = Crypt::encryptString(trim($validati['api_key']));
            }

            $settings->ia_abilitata = $request->boolean('ia_abilitata') && $settings->chiaveInChiaro() !== null;
            $settings->modello = trim($validati['modello']);
            $settings->tetto_spesa_mensile_centesimi = isset($validati['tetto_spesa_mensile']) && $validati['tetto_spesa_mensile'] !== ''
                ? (int) round(((float) $validati['tetto_spesa_mensile']) * 100)
                : null;

            $settings->save();
        } catch (\Throwable $e) {
            // Solo la classe dell'eccezione: il messaggio potrebbe contenere dati della richiesta.
            Log::error('Errore nel salvataggio delle impostazioni IA: '.$e::class);

            return back()->with($this->flashError('Non è stato possibile salvare le impostazioni IA.'));
        }

        return back()->with($this->flashSuccess('Impostazioni IA salvate.'));
    }

    /**
     * «Prova connessione»: GET /v1/models/{modello} con la chiave scritta nel modulo o, se vuoto,
     * con quella salvata. Verifica chiave e nome del modello senza consumare token.
     */
    public function test(Request $request, IASettings $settings, ClientAnthropic $client): JsonResponse
    {
        Gate::authorize('manage', $settings);

        $request->validate([
            'api_key' => 'nullable|string|max:300',
            'modello' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9.\-]+$/'],
        ]);

        $chiave = $request->filled('api_key') ? trim((string) $request->input('api_key')) : $settings->chiaveInChiaro();
        $modello = $request->filled('modello') ? (string) $request->input('modello') : $settings->modello;

        if ($chiave === null) {
            return response()->json(['success' => false, 'message' => 'Nessuna chiave API: scrivila nel campo e riprova.'], 422);
        }

        try {
            $info = $client->modello($chiave, $modello);
        } catch (ErroreAnthropic $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => sprintf('Connessione riuscita: modello %s disponibile.', $info['display_name'] ?? $info['id'] ?? $modello),
            'modello' => $info['id'] ?? $modello,
        ]);
    }
}
