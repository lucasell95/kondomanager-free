<?php

namespace App\Http\Requests\Gestionale\Banca;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Abbinamento di un accredito a un incasso rate. Stesse guardie di appartenenza di
 * `StoreIncassoRateRequest` (pagante del condominio, quote del condominio); importi in centesimi.
 */
class AbbinaIncassoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $condominioId = $this->route('condominio')?->id;

        return [
            'pagante_id' => [
                'required', 'integer',
                Rule::exists('anagrafiche', 'id')->where(
                    fn ($q) => $q
                        ->whereIn('id', fn ($sub) => $sub->select('anagrafica_id')
                            ->from('anagrafica_condominio')
                            ->where('condominio_id', $condominioId))
                        ->orWhereIn('id', fn ($sub) => $sub->select('anagrafica_immobile.anagrafica_id')
                            ->from('anagrafica_immobile')
                            ->join('immobili', 'immobili.id', '=', 'anagrafica_immobile.immobile_id')
                            ->where('immobili.condominio_id', $condominioId))
                ),
            ],
            'righe' => ['required', 'array', 'min:1'],
            'righe.*.quota_id' => [
                'required', 'integer',
                Rule::exists('rate_quote', 'id')->whereIn(
                    'rata_id',
                    fn ($q) => $q->select('rate.id')->from('rate')
                        ->join('piani_rate', 'rate.piano_rate_id', '=', 'piani_rate.id')
                        ->where('piani_rate.condominio_id', $condominioId)
                ),
            ],
            'righe.*.importo' => ['required', 'integer', 'min:0'],
            'eccedenza' => ['nullable', 'integer', 'min:0'],
            'descrizione' => ['nullable', 'string', 'max:255'],
        ];
    }
}
