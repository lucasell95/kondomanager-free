<?php

namespace App\Services\Gestionale;

use App\Models\Evento;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;

/**
 * Quello che succede **dopo** la registrazione di un incasso rate, fuori dalla contabilità:
 * gli eventi dello scadenziario del condòmino (pagata / parziale / da pagare), il task admin da
 * cui l'incasso è nato e il task globale «Verifica incassi» della rata saldata per intero.
 *
 * Era scritto dentro `IncassoRateController::store` e lì è rimasto identico fino alla 1.10: è
 * stato spostato qui, senza cambiarne una riga di logica, perché anche l'abbinamento dei
 * movimenti bancari registra incassi con `StoreIncassoRateAction` e deve lasciare lo
 * scadenziario nello stesso stato della maschera incassi. Due copie avrebbero cominciato a
 * divergere alla prima correzione.
 */
class SincronizzaScadenziarioIncasso
{
    /**
     * @param  int|string|null  $paganteId  l'anagrafica che ha pagato
     * @param  array<int, array{rata_id: int|string, importo: float|int|string}>  $dettaglioPagamenti  come in `StoreIncassoRateRequest` (rata_id = id della quota)
     * @param  int|string|null  $relatedTaskId  il task admin da chiudere, se l'incasso nasce da una segnalazione
     */
    public function dopoIncasso(int|string|null $paganteId, array $dettaglioPagamenti, int|string|null $relatedTaskId = null): void
    {
        // --- AGGIORNAMENTO EVENTI SCADENZIARIO ---
        // FIX: Consideriamo SOLO i pagamenti ordinari (importo > 0)
        $quoteOrdinarie = collect($dettaglioPagamenti)
            ->filter(fn($item) => $item['importo'] > 0)
            ->pluck('rata_id')
            ->filter()
            ->toArray();

        $rataIdsReali = [];
        if (!empty($quoteOrdinarie)) {
            // FIX: Convertiamo ID Quote -> ID Rate (Padri)
            $rataIdsReali = RataQuote::whereIn('id', $quoteOrdinarie)
                ->pluck('rata_id')
                ->unique()
                ->toArray();
        }

        if (!empty($quoteOrdinarie) && $paganteId) {

            $eventiDaAggiornare = Evento::where('meta->type', 'scadenza_rata_condomino')
                ->where(function ($q) use ($rataIdsReali) {
                    foreach ($rataIdsReali as $rId) {
                        $q->orWhere('meta->context->rata_id', (int)$rId)
                          ->orWhere('meta->context->rata_id', (string)$rId);
                    }
                })
                ->whereHas('anagrafiche', fn($q) => $q->where('anagrafica_id', $paganteId))
                ->get();

            foreach ($eventiDaAggiornare as $evento) {
            
                $rataId = $evento->meta['context']['rata_id'] ?? null;
                $rataFresca = Rata::with('rateQuote')->find($rataId);
            
                if ($rataFresca) {
                    // Escludiamo le quote saldo_iniziale negative (crediti) dal calcolo
                    $quoteUtente = $rataFresca->rateQuote
                        ->where('anagrafica_id', $paganteId)
                        ->where('importo', '>', 0);
                
                    $totaleDovuto = $quoteUtente->sum('importo');
                    $totalePagato = $quoteUtente->sum('importo_pagato');
                    $restante = $totaleDovuto - $totalePagato;

                    $meta = $evento->meta;
                    $meta['importo_pagato'] = $totalePagato;
                    $meta['importo_restante'] = max(0, $restante);

                    if ($restante <= 0.01) {
                        $meta['status'] = 'paid';
                    } elseif ($totalePagato > 0.01) {
                        $meta['status'] = 'partial';
                    } else {
                        $meta['status'] = 'pending';
                    }

                    $evento->update(['meta' => $meta]);
                }
            }
        }

        // Chiusura del Task Admin
        

        if ($relatedTaskId) {
            /** @var \App\Models\Evento|null $task */
            $task = Evento::find($relatedTaskId);

            if ($task && !$task->is_completed) {
                $task->update([
                    'is_completed' => true,
                    'completed_at' => now(),
                ]);
            }
        }

        // -------------------------------------------------------------
        // 2. SMART TASK KILLER: Uccisione Globale (Verifica incassi rata)
        // -------------------------------------------------------------
        if (!empty($quoteOrdinarie)) {
            // Prendiamo le rate padri coinvolte in questo pagamento
            $ratePadri = Rata::whereIn('id', $rataIdsReali)->with('rateQuote')->get();

            foreach ($ratePadri as $rataPadre) {
                // Calcoliamo quanto è stato pagato in totale per QUESTA rata in TUTTO il condominio
                $totaleDovutoCondominio = $rataPadre->rateQuote->where('importo', '>', 0)->sum('importo');
                $totalePagatoCondominio = $rataPadre->rateQuote->where('importo', '>', 0)->sum('importo_pagato');
            
                $residuoCondominio = $totaleDovutoCondominio - $totalePagatoCondominio;

                // Se l'intera rata del condominio è stata saldata (tolleranza 1 centesimo)
                if ($residuoCondominio <= 0.01) {
                
                    // Cerchiamo l'evento globale "Verifica incassi" per questa rata
                    $rataPadreId = (int) $rataPadre->id;
                
                    Evento::where('meta->type', 'controllo_incassi')
                        ->where(function($q) use ($rataPadreId) {
                            $q->where('meta->context->rata_id', $rataPadreId)
                              ->orWhere('meta->context->rata_id', (string) $rataPadreId);
                        })
                        ->where('is_completed', false)
                        ->update([
                            'is_completed' => true,
                            'completed_at' => now(),
                        ]);
                }
            }
        }

        // Svuotiamo la cache della inbox dell'admin una sola volta alla fine
        InboxService::clearAdminCache();
    }
}
