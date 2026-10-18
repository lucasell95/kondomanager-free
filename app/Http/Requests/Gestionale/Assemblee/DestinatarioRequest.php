<?php

namespace App\Http\Requests\Gestionale\Assemblee;

use App\Enums\Assemblee\MezzoConvocazione;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DestinatarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $condominioId = $this->route('condominio')?->id;

        return [
            'anagrafica_id' => [
                'nullable', 'integer',
                Rule::exists('anagrafica_condominio', 'anagrafica_id')->where('condominio_id', $condominioId),
            ],
            'nominativo' => ['required', 'string', 'max:255'],
            'ruolo' => ['nullable', 'string', 'max:50'],
            'mezzo' => ['required', Rule::in(MezzoConvocazione::values())],
            'recapito' => ['nullable', 'string', 'max:255'],
            'data_invio' => ['nullable', 'date'],
            'data_ricezione' => ['nullable', 'date', 'after_or_equal:data_invio'],
            'riferimento' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
