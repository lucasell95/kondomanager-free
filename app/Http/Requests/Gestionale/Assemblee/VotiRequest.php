<?php

namespace App\Http\Requests\Gestionale\Assemblee;

use App\Enums\Assemblee\EspressioneVoto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VotiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'voti' => ['present', 'array'],
            'voti.*.chiave_testa' => ['required', 'string', 'max:191'],
            'voti.*.presente' => ['required', 'boolean'],
            'voti.*.voto' => ['nullable', Rule::in(EspressioneVoto::values())],
            'voti.*.conflitto' => ['boolean'],
        ];
    }
}
