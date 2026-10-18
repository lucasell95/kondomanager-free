<?php

namespace App\Http\Requests\Gestionale\Fiscale;

use App\Helpers\MoneyHelper;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Interessi e sanzione versati per ravvedere una riga F24 pagata in ritardo.
 *
 * Gli importi arrivano in euro dal modulo (come negli altri form del gestionale) e si salvano
 * in centesimi. Che la riga appartenga al condominio lo controlla il controller.
 */
class StoreRavvedimentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'riga_f24_id' => ['required', 'integer', 'exists:righe_f24,id'],
            'interessi' => ['required', 'integer', 'min:0'],
            'sanzione' => ['required', 'integer', 'min:0'],
            'codice_tributo_sanzione' => ['nullable', 'string', 'regex:/^[0-9]{4}$/'],
            'data_versamento' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'interessi' => MoneyHelper::toCents($this->interessi) ?? 0,
            'sanzione' => MoneyHelper::toCents($this->sanzione) ?? 0,
            'data_versamento' => $this->data_versamento ? Carbon::parse($this->data_versamento)->toDateString() : null,
        ]);
    }
}
