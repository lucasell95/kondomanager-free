<?php

namespace App\Actions\Gestionale\Movimenti;

use App\Enums\TipoMovimentoContabile;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\DoubleEntryValidator;
use Illuminate\Support\Facades\DB;

class StornoIncassoRateAction
{
    public function execute(ScritturaContabile $scrittura, Condominio $condominio): void
    {
        // 1. GUARD CLAUSE: Preveniamo lo "Storno dello Storno" (Inception contabile)
        // tipo_movimento è castato all'enum: il confronto con la stringa era sempre
        // falso, quindi questa protezione non scattava mai.
        if ($scrittura->tipo_movimento === TipoMovimentoContabile::RETTIFICA) {
            throw new \RuntimeException('Non è possibile stornare una scrittura di rettifica.');
        }

        DB::transaction(function () use ($scrittura, $condominio) {
            
            // Raccogliamo la scrittura padre e tutte le sue figlie precaricate
            $scrittureDaStornare = collect([$scrittura])->merge($scrittura->figlie);

            foreach ($scrittureDaStornare as $scr) {
                
                // Protezione contro i doppi click
                if ($scr->stato === 'annullata') continue;

                // 2. Cross-esercizio: stessa regola di StornaGirocontoAction (Variante B1). Un
                //    esercizio chiuso non si riscrive: se l'incasso sta in un esercizio chiuso, la
                //    rettifica si appoggia all'esercizio aperto, con la provenienza in causale e la
                //    data di oggi (lo storno è un fatto dell'esercizio nuovo). Prima la rettifica
                //    finiva nell'esercizio chiuso, alterando un esercizio già rendicontato.
                [$esercizioId, $dataCompetenza, $causale] = $this->collocazione($scr, $condominio);

                // 3. Creiamo la Scrittura speculare di Rettifica
                $rettifica = ScritturaContabile::create([
                    'condominio_id'      => $condominio->id,
                    'esercizio_id'       => $esercizioId,
                    'gestione_id'        => $scr->gestione_id,
                    'scrittura_padre_id' => $scr->id, // 🟢 LINK DIRETTO ALLA SCRITTURA ORIGINALE
                    'data_registrazione' => now(),
                    'data_competenza'    => $dataCompetenza,
                    'causale'            => mb_substr($causale, 0, 255),
                    'tipo_movimento'     => 'rettifica',
                    'stato'              => 'registrata',
                    'note'               => 'Annullamento prot. ' . $scr->numero_protocollo,
                ]);

                // 4. Invertiamo le righe (Partita Doppia)
                foreach ($scr->righe as $riga) {
                    $rettifica->righe()->create([
                        'conto_contabile_id' => $riga->conto_contabile_id,
                        'cassa_id'           => $riga->cassa_id,
                        'anagrafica_id'      => $riga->anagrafica_id,
                        'rata_id'            => $riga->rata_id,
                        'immobile_id'        => $riga->immobile_id,
                        'tipo_riga'          => $riga->tipo_riga === 'dare' ? 'avere' : 'dare',
                        'importo'            => $riga->importo,
                    ]);
                }

                // 5. Ripristiniamo il debito/credito sulle quote (Metodo Audit-Safe)
                foreach ($scr->quotePagate as $quota) {
                    
                    // Sfruttiamo il caricamento Eager per leggere il dato storico dalla pivot
                    $importoStorico = $quota->pivot->importo_pagato;
                        
                    // Attach() con importo negativo per stornare
                    $quota->pagamenti()->attach($rettifica->id, [
                        'importo_pagato' => -$importoStorico,
                        'data_pagamento' => $dataCompetenza,
                    ]);
                    
                    // Ricalcolo dello stato leggendo la nuova pivot aggiornata
                    $quota->ricalcolaStato();
                }

                // La rettifica è il mirror esatto dell'originale: se l'originale
                // quadrava deve quadrare anche lei. Verificarlo qui intercetta
                // un'inversione incompleta prima che entri nel giornale.
                DoubleEntryValidator::validateOrFail($rettifica->id);

                // 6. Sigilliamo la scrittura originale
                $scr->update(['stato' => 'annullata']);
            }
        });
    }

    /**
     * Dove va la rettifica: esercizio, data di competenza e causale.
     *
     * Esercizio dell'originale aperto → lì, con la data dell'originale (com'era sempre).
     * Esercizio chiuso (o mancante) → nell'esercizio aperto, con la data di oggi e la provenienza
     * in causale. Senza un esercizio aperto lo storno non si può registrare.
     *
     * @return array{0: int, 1: mixed, 2: string}
     *
     * @throws \DomainException
     */
    private function collocazione(ScritturaContabile $scr, Condominio $condominio): array
    {
        $originale = $scr->esercizio;

        if ($originale && $originale->stato !== 'chiuso') {
            return [$scr->esercizio_id, $scr->data_competenza, 'Storno: ' . $scr->causale];
        }

        $aperto = Esercizio::where('condominio_id', $condominio->id)
            ->where('stato', 'aperto')
            ->orderByDesc('data_inizio')
            ->first();

        if (! $aperto) {
            throw new \DomainException(
                "L'esercizio dell'incasso è chiuso e non esiste un esercizio aperto in cui "
                . 'registrare lo storno. Apri l\'esercizio successivo e riprova.'
            );
        }

        return [
            $aperto->id,
            now()->toDateString(),
            sprintf('Storno: %s. Originale in esercizio «%s».', $scr->causale, $originale?->nome ?? '—'),
        ];
    }
}