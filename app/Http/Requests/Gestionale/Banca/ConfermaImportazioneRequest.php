<?php

namespace App\Http\Requests\Gestionale\Banca;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Conferma dell'import dopo l'anteprima. Le scelte sono per hash della riga. */
class ConfermaImportazioneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $condominioId = $this->route('condominio')?->id;

        return [
            'cassa_id' => [
                'required', 'integer',
                Rule::exists('casse', 'id')
                    ->where('condominio_id', $condominioId)
                    ->whereIn('tipo', ['banca', 'contanti', 'virtuale']),
            ],
            'token' => ['required', 'uuid'],
            'configurazione' => ['nullable', 'array'],
            'configurazione.riga_intestazione' => ['nullable', 'integer', 'min:0', 'max:500'],
            'configurazione.colonne' => ['nullable', 'array'],
            'configurazione.colonne.*' => ['nullable', 'integer', 'min:0', 'max:200'],
            'configurazione.separatore_decimale' => ['nullable', Rule::in([',', '.'])],
            'configurazione.formato_data' => ['nullable', Rule::in(['auto', 'd/m/Y', 'd/m/y', 'd-m-Y', 'd.m.Y', 'Y-m-d', 'm/d/Y'])],
            'configurazione.inverti_segno' => ['nullable', 'boolean'],
            'salva_profilo' => ['nullable', 'boolean'],

            'importa_possibili_duplicati' => ['nullable', 'array'],
            'importa_possibili_duplicati.*' => ['string', 'size:64'],
            // hash → id della scrittura da collegare (null = importa senza collegare)
            'collega' => ['nullable', 'array'],
            'collega.*' => ['nullable', 'integer'],

            'saldo_iniziale' => ['nullable', 'integer'],
            'data_saldo_iniziale' => ['nullable', 'date'],
            'saldo_finale' => ['nullable', 'integer'],
            'data_saldo_finale' => ['nullable', 'date'],
        ];
    }
}
