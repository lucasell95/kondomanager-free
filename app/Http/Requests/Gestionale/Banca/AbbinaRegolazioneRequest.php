<?php

namespace App\Http\Requests\Gestionale\Banca;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Registrazione di commissioni, bolli e simili sul capitolo di spesa scelto, con l'opzione di
 * ricordare la scelta come regola per i movimenti con la stessa descrizione.
 */
class AbbinaRegolazioneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $condominioId = $this->route('condominio')?->id;

        return [
            'conto_id' => [
                'required', 'integer',
                Rule::exists('conti', 'id')->whereIn(
                    'piano_conto_id',
                    fn ($q) => $q->select('id')->from('piani_conti')->where('condominio_id', $condominioId)
                ),
            ],
            'gestione_id' => [
                'required', 'integer',
                Rule::exists('gestioni', 'id')->where('condominio_id', $condominioId),
            ],
            'causale' => ['nullable', 'string', 'min:3', 'max:255'],
            'crea_regola' => ['nullable', 'boolean'],
            'testo_regola' => ['nullable', 'required_if:crea_regola,true', 'string', 'min:3', 'max:255'],
        ];
    }
}
