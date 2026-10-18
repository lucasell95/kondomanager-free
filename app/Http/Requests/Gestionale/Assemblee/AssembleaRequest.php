<?php

namespace App\Http\Requests\Gestionale\Assemblee;

use App\Enums\Assemblee\TipoAssemblea;
use App\Services\Assemblee\ControlliConvocazioneService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Dati generali dell'assemblea (creazione e modifica).
 *
 * Le regole sulla seconda convocazione — non lo stesso giorno della prima, non oltre dieci giorni —
 * sono errori di validazione: si correggono cambiando una data, e un'assemblea con date sbagliate
 * non deve nemmeno arrivare all'avviso.
 */
class AssembleaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $condominioId = $this->route('condominio')?->id;

        return [
            'tipo' => ['required', Rule::in(TipoAssemblea::values())],
            'titolo' => ['nullable', 'string', 'max:255'],
            'esercizio_id' => ['nullable', 'integer', Rule::exists('esercizi', 'id')->where('condominio_id', $condominioId)],
            'tabella_id' => ['nullable', 'integer', Rule::exists('tabelle', 'id')->where('condominio_id', $condominioId)],
            'prima_convocazione_at' => ['required', 'date'],
            'prima_luogo' => ['nullable', 'string', 'max:255'],
            'seconda_convocazione_at' => ['nullable', 'date'],
            'seconda_luogo' => ['nullable', 'string', 'max:255'],
            'videoconferenza' => ['boolean'],
            'piattaforma_video' => ['nullable', 'string', 'max:255'],
            'link_video' => ['nullable', 'string', 'max:500'],
            'consenso_video_acquisito' => ['boolean'],
            'convocante' => ['nullable', 'string', 'max:255'],
            'amministratore_anagrafica_id' => [
                'nullable', 'integer',
                Rule::exists('anagrafica_condominio', 'anagrafica_id')->where('condominio_id', $condominioId),
            ],
            'amministratore_nome' => ['nullable', 'string', 'max:255'],
            'allegati' => ['nullable', 'string', 'max:5000'],
            'note' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'prima_convocazione_at' => 'prima convocazione',
            'seconda_convocazione_at' => 'seconda convocazione',
            'amministratore_anagrafica_id' => 'amministratore',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $prima = $this->input('prima_convocazione_at') ? Carbon::parse($this->input('prima_convocazione_at')) : null;
            $seconda = $this->input('seconda_convocazione_at') ? Carbon::parse($this->input('seconda_convocazione_at')) : null;

            foreach (app(ControlliConvocazioneService::class)->erroriDate($prima, $seconda) as $errore) {
                $validator->errors()->add('seconda_convocazione_at', $errore);
            }
        });
    }
}
