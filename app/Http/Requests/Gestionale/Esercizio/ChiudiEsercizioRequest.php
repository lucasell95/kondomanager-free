<?php

namespace App\Http\Requests\Gestionale\Esercizio;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Le scelte della procedura «Chiudi esercizio e apri il successivo».
 *
 * La data di inizio del nuovo esercizio non si sceglie: è il giorno dopo la fine di quello che
 * si chiude, perché fra i due non deve restare un giorno scoperto (lo stato patrimoniale e i
 * protocolli ragionano per esercizi contigui).
 */
class ChiudiEsercizioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $esercizio = $this->route('esercizio');
        $inizio = $esercizio?->data_fine?->copy()->addDay()->toDateString();

        return [
            'nome' => [
                'required', 'string', 'max:255',
                Rule::unique('esercizi', 'nome')->where('condominio_id', $esercizio?->condominio_id),
            ],
            'descrizione'               => ['nullable', 'string', 'max:255'],
            'data_fine'                 => ['required', 'date', 'after:'.$inizio],
            'numerazione_per_esercizio' => ['boolean'],
            'copia_piano_conti'         => ['boolean'],
            'gestioni'                  => ['array'],
            'gestioni.*.modalita'       => ['required', Rule::in(['riporta', 'mantieni'])],
            'gestioni.*.riapri'         => ['boolean'],
            'conferma'                  => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'nome.unique'       => 'Esiste già un esercizio con questo nome.',
            'data_fine.after'   => 'La fine del nuovo esercizio deve essere dopo il suo inizio.',
            'conferma.accepted' => 'Conferma di aver controllato l\'anteprima.',
        ];
    }
}
