<?php

namespace App\Http\Requests\Gestionale\Assemblee;

use App\Enums\Assemblee\Convocazione;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SvolgimentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'convocazione_tenuta' => ['nullable', Rule::in(Convocazione::values())],
            'prima_deserta' => ['boolean'],
            'presidente' => ['nullable', 'string', 'max:255'],
            'segretario' => ['nullable', 'string', 'max:255'],
            'ora_apertura' => ['nullable', 'date_format:H:i'],
            'ora_chiusura' => ['nullable', 'date_format:H:i'],
        ];
    }
}
