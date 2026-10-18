<?php

namespace App\Http\Requests\Gestionale\Assemblee;

use Illuminate\Foundation\Http\FormRequest;

class DichiarazioneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assemblea_punto_id' => ['nullable', 'integer'],
            'anagrafica_id' => ['nullable', 'integer'],
            'dichiarante' => ['required', 'string', 'max:255'],
            'testo' => ['required', 'string', 'max:10000'],
        ];
    }
}
