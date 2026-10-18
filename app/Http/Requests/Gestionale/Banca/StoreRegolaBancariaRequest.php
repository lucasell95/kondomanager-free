<?php

namespace App\Http\Requests\Gestionale\Banca;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Regola «se la descrizione contiene…» per commissioni, bolli e movimenti da ignorare. */
class StoreRegolaBancariaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $condominioId = $this->route('condominio')?->id;

        return [
            'nome' => ['required', 'string', 'min:3', 'max:255'],
            'testo_contiene' => ['required', 'string', 'min:3', 'max:255'],
            'verso' => ['required', Rule::in(['entrata', 'uscita', 'entrambi'])],
            'azione' => ['required', Rule::in(['regolazione', 'ignora'])],
            'cassa_id' => ['nullable', 'integer', Rule::exists('casse', 'id')->where('condominio_id', $condominioId)],
            'conto_id' => [
                'nullable', 'required_if:azione,regolazione', 'integer',
                Rule::exists('conti', 'id')->whereIn(
                    'piano_conto_id',
                    fn ($q) => $q->select('id')->from('piani_conti')->where('condominio_id', $condominioId)
                ),
            ],
            'gestione_id' => [
                'nullable', 'required_if:azione,regolazione', 'integer',
                Rule::exists('gestioni', 'id')->where('condominio_id', $condominioId),
            ],
            'causale' => ['nullable', 'string', 'max:255'],
            'importo_min' => ['nullable', 'integer', 'min:0'],
            'importo_max' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
