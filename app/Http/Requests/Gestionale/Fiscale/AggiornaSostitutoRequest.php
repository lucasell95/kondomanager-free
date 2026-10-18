<?php

namespace App\Http\Requests\Gestionale\Fiscale;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * I dati del condominio come sostituto d'imposta: chi firma CU e 770, con quale carica.
 *
 * Il codice fiscale del condominio non si cambia da qui: sta nella scheda del condominio.
 */
class AggiornaSostitutoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'firmatario_codice_fiscale' => ['nullable', 'string', 'size:16', 'regex:/^[A-Z0-9]{16}$/'],
            'firmatario_cognome' => ['nullable', 'string', 'max:100'],
            'firmatario_nome' => ['nullable', 'string', 'max:100'],
            'firmatario_codice_carica' => ['nullable', Rule::in(array_map('strval', array_keys(config('fiscale.codici_carica', []))))],
            'firmatario_data_carica' => ['nullable', 'date'],
            'sostituto_telefono' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'firmatario_codice_fiscale.size' => 'Il codice fiscale del firmatario è quello di una persona fisica: 16 caratteri.',
            'firmatario_codice_fiscale.regex' => 'Il codice fiscale del firmatario contiene caratteri non ammessi.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'firmatario_codice_fiscale' => $this->firmatario_codice_fiscale
                ? Str::upper(str_replace(' ', '', $this->firmatario_codice_fiscale)) : null,
            'firmatario_codice_carica' => $this->firmatario_codice_carica !== null && $this->firmatario_codice_carica !== ''
                ? (string) $this->firmatario_codice_carica : null,
            'firmatario_data_carica' => $this->firmatario_data_carica
                ? Carbon::parse($this->firmatario_data_carica)->toDateString() : null,
        ]);
    }
}
