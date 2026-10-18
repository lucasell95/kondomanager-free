<?php

namespace App\Http\Requests\Gestionale\Esercizio;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @method bool merge(string $key)
 * @method bool input(string $key)
 * @method \Illuminate\Routing\Route|null route(string|null $param = null, mixed $default = null)
 */
class UpdateEsercizioRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nome'                => 'required|string|max:255', 
            'descrizione'         => 'required|string|max:255',
            'data_inizio'         => 'required|date',
            'data_fine'           => 'required|date|after_or_equal:data_inizio',
            'stato'               => 'required|string|in:aperto,chiuso',
            'note'                => 'sometimes|nullable|string',
        ];
    }

    /**
     * Stessa invariante di CreateEsercizioRequest: riaprire un esercizio chiuso
     * mentre ne esiste un altro aperto renderebbe ambiguo l'esercizio corrente.
     *
     * Due precisazioni imparate dalla review:
     *  - il controllo scatta solo sulla TRANSIZIONE chiuso → aperto. Il form rimanda
     *    sempre `stato`, quindi su un esercizio già aperto il vincolo bloccherebbe
     *    perfino la rinomina — e su un DB con due esercizi aperti (ripristino di un
     *    backup anteriore a questa versione) renderebbe entrambi immodificabili;
     *  - il condominio si legge dall'ESERCIZIO, non dalla rotta: le rotte annidate
     *    non hanno scopeBindings, quindi il condominio di rotta può non essere quello
     *    a cui l'esercizio appartiene.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $esercizio = $this->route('esercizio');

            // ⚠️ **Un esercizio chiuso non si sposta.** Lo stato patrimoniale per esercizio include
            // gli esercizi «iniziati prima» (StatoPatrimonialeService): cambiare le date di uno chiuso
            // ne sposta il perimetro e cambia i numeri di un periodo già rendicontato. Anche il
            // nome resta: è quello stampato sui documenti di quel periodo. Descrizione e note sì.
            if ($esercizio instanceof \App\Models\Esercizio && $esercizio->stato === 'chiuso') {
                $stessaData = fn (string $campo) => $this->filled($campo)
                    && \Carbon\Carbon::parse($this->input($campo))->toDateString() === $esercizio->{$campo}?->toDateString();

                foreach (['data_inizio', 'data_fine'] as $campo) {
                    if (! $stessaData($campo)) {
                        $validator->errors()->add($campo, "L'esercizio è chiuso: le sue date non si possono più cambiare.");
                    }
                }

                // Senza badare alle maiuscole: il modulo riceve il nome con l'iniziale maiuscola
                // (EsercizioResource) e lo rimanda così.
                if (mb_strtolower(trim((string) $this->input('nome'))) !== mb_strtolower(trim((string) $esercizio->nome))) {
                    $validator->errors()->add('nome', "L'esercizio è chiuso: il nome non si può più cambiare.");
                }

                // Chiuso con la procedura guidata: si riapre solo annullando la chiusura, che
                // rimette a posto anche quote e saldi riportati. Riaprirlo dal modulo lascerebbe
                // il residuo contato due volte (nelle vecchie quote e nel saldo iniziale nuovo).
                if ($this->input('stato') === 'aperto' && $esercizio->chiusuraEseguita()) {
                    $validator->errors()->add('stato',
                        "L'esercizio è stato chiuso con la procedura di passaggio d'esercizio: per riaprirlo usa "
                        ."«Annulla chiusura» nella pagina del passaggio d'esercizio.");
                }
            }

            if ($this->input('stato') !== 'aperto') {
                return;
            }

            // Già aperto e resta aperto: nessuna transizione, nessun vincolo.
            if ($esercizio instanceof \App\Models\Esercizio && $esercizio->stato === 'aperto') {
                return;
            }

            $condominioId = $esercizio instanceof \App\Models\Esercizio
                ? $esercizio->condominio_id
                : $this->route('condominio')?->id;

            $altroAperto = \App\Models\Esercizio::where('condominio_id', $condominioId)
                ->where('stato', 'aperto')
                ->when($esercizio?->id, fn ($q) => $q->where('id', '!=', $esercizio->id))
                ->first();

            if ($altroAperto) {
                $validator->errors()->add(
                    'stato',
                    "Esiste già un esercizio aperto per questo condominio («{$altroAperto->nome}»). "
                    .'Chiudilo prima di riaprire questo.'
                );
            }
        });
    }
}
