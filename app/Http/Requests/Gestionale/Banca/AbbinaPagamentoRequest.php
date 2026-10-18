<?php

namespace App\Http\Requests\Gestionale\Banca;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Abbinamento di un addebito al pagamento di fatture passive (importi in centesimi). */
class AbbinaPagamentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'allocazioni' => ['required', 'array', 'min:1'],
            'allocazioni.*.fattura_id' => [
                'required', 'integer',
                Rule::exists('fatture_passive', 'id')->where('condominio_id', $this->route('condominio')?->id),
            ],
            'allocazioni.*.importo' => ['required', 'integer', 'min:1'],
            'commissioni' => ['nullable', 'integer', 'min:0'],
            'iban_confermato_manualmente' => ['nullable', 'boolean'],
            'allow_overdraft' => ['nullable', 'boolean'],
            'conferma_duplicato_verificato' => ['nullable', 'boolean'],
            'nota_override' => ['nullable', 'string', 'max:2000', 'required_if:allow_overdraft,true'],
        ];
    }
}
