<?php

namespace App\Http\Requests\Gestionale\Assemblee;

use App\Enums\Assemblee\MezzoConvocazione;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ComunicazioneVerbaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'verbale_mezzo' => ['nullable', Rule::in(MezzoConvocazione::values())],
            'verbale_data_invio' => ['nullable', 'date'],
            'verbale_data_ricezione' => ['nullable', 'date', 'after_or_equal:verbale_data_invio'],
            'verbale_riferimento' => ['nullable', 'string', 'max:255'],
        ];
    }
}
