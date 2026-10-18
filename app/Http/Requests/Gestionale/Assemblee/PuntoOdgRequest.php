<?php

namespace App\Http\Requests\Gestionale\Assemblee;

use App\Enums\Assemblee\MateriaDelibera;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PuntoOdgRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $condominioId = $this->route('condominio')?->id;

        return [
            'titolo' => ['required', 'string', 'max:255'],
            'testo' => ['nullable', 'string', 'max:10000'],
            'materia' => ['required', Rule::in(MateriaDelibera::values())],
            'tabella_id' => ['nullable', 'integer', Rule::exists('tabelle', 'id')->where('condominio_id', $condominioId)],
        ];
    }
}
