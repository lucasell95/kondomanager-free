<?php

namespace App\Listeners\Gestionale;

use App\Events\Gestionale\PagamentoAggiornato;
use App\Events\Gestionale\PagamentoRegistrato;
use App\Events\Gestionale\PagamentoStornato;
use App\Models\Evento;
use App\Services\Gestionale\InboxService;
use App\Services\Gestionale\PromemoriaRitenuteService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Tiene allineato il promemoria «Versare F24 Ritenuta» dell'Admin Inbox ai pagamenti fornitore
 * con ritenuta d'acconto.
 *
 * Il promemoria nasce al PAGAMENTO — non alla registrazione della fattura — perché è il
 * pagamento il fatto generatore della ritenuta (art. 25-ter DPR 600/1973, «all'atto del
 * pagamento»).
 *
 * La scadenza NON si calcola qui: la decide lo scadenziario F24 con le soglie di legge
 * (4% appalti: 500 €, comunque entro 16/06 e 16/12, dicembre → 16/01; 20% lavoro autonomo:
 * 100 €, entro il 16/12), tramite `PromemoriaRitenuteService`. Fino al collaudo 1 (E1) qui
 * c'era «16 del mese successivo» per ogni pagamento, e l'Inbox chiedeva di versare mesi prima
 * dello scadenziario, con un F24 per pagamento. Poiché un pagamento può spostare anche la
 * scadenza degli altri, ogni evento risincronizza tutti i promemoria del condominio.
 *
 * Implementazione: auto-discovery via metodo subscribe(), stessa convenzione di
 * SyncScadenziarioWithPagamento. $afterCommit = true obbligatorio: il lock
 * pessimistico del pagamento deve essere già rilasciato prima che questo
 * listener legga i dati.
 */
class SyncF24WithPagamento implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    /**
     * Auto-registra il listener sull'evento PagamentoRegistrato.
     */
    public function subscribe($events): void
    {
        $events->listen(
            PagamentoRegistrato::class,
            [SyncF24WithPagamento::class, 'handle']
        );

        $events->listen(
            PagamentoAggiornato::class,
            [SyncF24WithPagamento::class, 'handleAggiornato']
        );

        // Senza questa iscrizione il promemoria restava aperto dopo uno storno, e
        // segnalava un versamento non più dovuto (design §8 punto 4).
        $events->listen(
            PagamentoStornato::class,
            [SyncF24WithPagamento::class, 'handleStornato']
        );
    }

    public function handle(PagamentoRegistrato $event): void
    {
        $pagamento = $event->pagamento;

        // Guard: nessuna ritenuta → nessun F24 da versare
        if (!$pagamento->importo_ritenuta || $pagamento->importo_ritenuta <= 0) {
            return;
        }

        // Guard: se è un record di storno, la ritenuta è già stata annullata
        if ($pagamento->pagamento_padre_id !== null) {
            return;
        }

        $condominio = $pagamento->condominio;

        if (!$condominio || !$pagamento->fornitore) {
            Log::warning("SyncF24WithPagamento: fornitore o condominio mancante per pagamento #{$pagamento->id}");
            return;
        }

        if (!$pagamento->data_pagamento) {
            Log::warning("SyncF24WithPagamento: data_pagamento mancante per pagamento #{$pagamento->id}");
            return;
        }

        app(PromemoriaRitenuteService::class)->sincronizza($condominio);
    }

    /**
     * Aggiorna, crea o chiude il task F24 Inbox quando un pagamento viene modificato.
     *
     * Casistiche:
     *  - ritenuta da valore → 0 → il task F24 si chiude
     *  - ritenuta > 0 (nuova, cambiata, o cambiata la data) → task creato/aggiornato con la
     *    scadenza dello scadenziario, e riaperto se era stato chiuso
     * In ogni caso si risincronizzano anche gli altri promemoria del condominio.
     */
    public function handleAggiornato(PagamentoAggiornato $event): void
    {
        $pagamento             = $event->pagamento;
        $importoRitenutaBefore = $event->importoRitenutaBefore;
        $importoRitenutaAfter  = $pagamento->importo_ritenuta ?? 0;

        // Guard: storno — non dovrebbe mai arrivare qui, ma sicurezza prima di tutto
        if ($pagamento->pagamento_padre_id !== null) {
            return;
        }

        // Caso: ritenuta era > 0, ora è 0 → chiudi il task F24
        if ($importoRitenutaBefore > 0 && $importoRitenutaAfter <= 0) {
            Evento::where('meta->context->pagamento_id', $pagamento->id)
                ->where('meta->type', 'versamento_ritenuta')
                ->update(['is_completed' => true]);

            Log::info("SyncF24WithPagamento: task F24 chiuso per pagamento #{$pagamento->id} (ritenuta azzerata)");
        } elseif ($importoRitenutaAfter <= 0) {
            // Caso: nessuna ritenuta né prima né dopo → nessun F24
            return;
        }

        $condominio = $pagamento->condominio;

        if (!$condominio) {
            Log::warning("SyncF24WithPagamento::handleAggiornato: condominio mancante per pagamento #{$pagamento->id}");
            return;
        }

        app(PromemoriaRitenuteService::class)->sincronizza($condominio, [$pagamento->id]);
    }

    /**
     * Chiude il promemoria F24 quando il pagamento che lo aveva generato viene stornato.
     *
     * Difetto §8 punto 4 del design: il listener era iscritto solo a `PagamentoRegistrato` e
     * `PagamentoAggiornato`, quindi dopo uno storno il task restava aperto e continuava a
     * chiedere il versamento di una ritenuta che non era più dovuta — chiudibile solo a
     * mano, e solo da chi si accorgeva che non serviva più.
     *
     * L'evento porta il pagamento ORIGINALE, non il record di storno: il task da chiudere è
     * quello agganciato a quell'id. Poi si risincronizzano gli altri: senza quella ritenuta
     * il cumulo può scendere sotto soglia e spostare la loro scadenza.
     */
    public function handleStornato(PagamentoStornato $event): void
    {
        $pagamento = $event->pagamento;

        try {
            $chiusi = Evento::where('meta->context->pagamento_id', $pagamento->id)
                ->where('meta->type', 'versamento_ritenuta')
                ->update(['is_completed' => true]);

            if ($chiusi > 0) {
                InboxService::clearAdminCache();
                Log::info("SyncF24WithPagamento: task F24 chiuso per storno del pagamento #{$pagamento->id}");
            }

        } catch (\Exception $e) {
            Log::error("SyncF24WithPagamento::handleStornato: errore per pagamento #{$pagamento->id}: " . $e->getMessage());
        }

        if ($pagamento->condominio) {
            app(PromemoriaRitenuteService::class)->sincronizza($pagamento->condominio);
        }
    }
}
