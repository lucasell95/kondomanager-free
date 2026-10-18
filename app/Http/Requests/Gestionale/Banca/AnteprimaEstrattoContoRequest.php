<?php

namespace App\Http\Requests\Gestionale\Banca;

use App\Support\LimiteCaricamento;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Caricamento dell'estratto conto e anteprima.
 *
 * Due forme: il primo invio porta il **file**; i successivi (mappatura delle colonne cambiata
 * nell'anteprima) portano il **gettone** del file già caricato e la configurazione.
 */
class AnteprimaEstrattoContoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $condominioId = $this->route('condominio')?->id;

        return [
            // Solo conti veri: i fondi sono partizioni contabili del c/c e non hanno estratto conto.
            'cassa_id' => [
                'required', 'integer',
                Rule::exists('casse', 'id')
                    ->where('condominio_id', $condominioId)
                    ->whereIn('tipo', ['banca', 'contanti', 'virtuale']),
            ],
            'file' => ['required_without:token', 'nullable', 'file', 'extensions:csv,txt,xlsx,xls,xml,ofx,qfx', 'max:'.LimiteCaricamento::regolaMax()],
            'token' => ['required_without:file', 'nullable', 'uuid'],

            'configurazione' => ['nullable', 'array'],
            'configurazione.riga_intestazione' => ['nullable', 'integer', 'min:0', 'max:500'],
            'configurazione.colonne' => ['nullable', 'array'],
            'configurazione.colonne.*' => ['nullable', 'integer', 'min:0', 'max:200'],
            'configurazione.separatore_decimale' => ['nullable', Rule::in([',', '.'])],
            'configurazione.formato_data' => ['nullable', Rule::in(['auto', 'd/m/Y', 'd/m/y', 'd-m-Y', 'd.m.Y', 'Y-m-d', 'm/d/Y'])],
            'configurazione.inverti_segno' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required_without' => 'Scegli il file dell\'estratto conto.',
            'file.extensions' => 'Formati accettati: CSV, XLSX, XLS, CAMT.053/052 (XML), OFX.',
            'file.max' => 'Il file supera il limite di '.LimiteCaricamento::etichetta().'.',
            'cassa_id.exists' => 'Il conto scelto non appartiene a questo condominio.',
        ];
    }
}
