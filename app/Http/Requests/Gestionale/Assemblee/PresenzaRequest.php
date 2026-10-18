<?php

namespace App\Http\Requests\Gestionale\Assemblee;

use App\Enums\Assemblee\ModoPresenza;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PresenzaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $condominioId = $this->route('condominio')?->id;

        return [
            'anagrafica_id' => ['required', 'integer'],
            'modo' => ['required', Rule::in(ModoPresenza::values())],
            'delegato_anagrafica_id' => [
                'nullable', 'integer',
                Rule::exists('anagrafica_condominio', 'anagrafica_id')->where('condominio_id', $condominioId),
            ],
            'delegato_nome' => ['nullable', 'string', 'max:255'],
            'delega_scritta' => ['boolean'],
            'ora_ingresso' => ['nullable', 'date_format:H:i'],
            'ora_uscita' => ['nullable', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
