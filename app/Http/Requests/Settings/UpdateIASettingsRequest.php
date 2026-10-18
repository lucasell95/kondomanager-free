<?php

namespace App\Http\Requests\Settings;

use App\Settings\IASettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Salvataggio delle impostazioni IA. L'autorizzazione la fa il controller con la policy. */
class UpdateIASettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ia_abilitata'        => 'required|boolean',
            // Vuoto = non cambiare la chiave salvata.
            'api_key'             => 'nullable|string|min:20|max:300|starts_with:sk-ant-',
            'rimuovi_chiave'      => 'nullable|boolean',
            'modello'             => ['required', 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9.\-]+$/'],
            // Dollari, con al massimo due decimali; vuoto = nessun tetto.
            'tetto_spesa_mensile' => 'nullable|numeric|min:0|max:10000|decimal:0,2',
            'conferma_informativa' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'api_key.starts_with' => 'La chiave API di Anthropic inizia con «sk-ant-».',
            'api_key.min'         => 'La chiave API sembra incompleta.',
            'modello.regex'       => 'Il nome del modello può contenere solo lettere minuscole, cifre, punti e trattini (es. claude-sonnet-5-5).',
            'tetto_spesa_mensile.decimal' => 'Il tetto di spesa può avere al massimo due decimali.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (! $this->boolean('ia_abilitata')) {
                    return;
                }

                $settings = app(IASettings::class);

                $avraChiave = $this->filled('api_key')
                    || (! $this->boolean('rimuovi_chiave') && $settings->chiaveInChiaro() !== null);

                if (! $avraChiave) {
                    $validator->errors()->add('api_key', empty($settings->api_key) || $this->boolean('rimuovi_chiave')
                        ? 'Per attivare l\'IA serve una chiave API.'
                        : 'La chiave salvata non è più leggibile (APP_KEY cambiata, per esempio dopo un ripristino): reinseriscila.');
                }

            },
        ];
    }
}
