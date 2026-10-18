<?php

namespace App\Traits;

use App\Models\Condominio;


/**
 * Trait for retrieving current esercizio (fiscal period) for condominio
 * 
 * Provides a standardized method to get the currently active and open
 * esercizio (fiscal period) for a given condominio. This is essential
 * for financial operations and period-based data management.
 *
 * @package App\Traits
 */
trait HasEsercizio
{
    /**
     * Get the current open esercizio for the condominio
     *
     * Retrieves the active fiscal period (esercizio) that is currently
     * in 'aperto' (open) state for the specified condominio. This is
     * typically used for financial transactions, reporting, and
     * period-based operations.
     *
     * @param \App\Models\Condominio $condominio The condominio to get the esercizio for
     * @return \App\Models\Esercizio|null The open esercizio or null if none found
     *
     * @example
     * // Usage in controller
     * $esercizio = $this->getEsercizioCorrente($condominio);
     * 
     * if ($esercizio) {
     *     // Use the open esercizio for financial operations
     *     $transactions = $esercizio->transazioni()->get();
     * } else {
     *     // Handle case where no esercizio is open
     *     return back()->with($this->flashError('No open fiscal period'));
     * }
     *
     * @see \App\Models\Esercizio
     * @see \App\Models\Condominio::esercizi()
     */
    protected function getEsercizioCorrente(Condominio $condominio)
    {
        // Le FormRequest ammettono un solo esercizio aperto, ma due aperti possono comparire
        // (ripristino di un backup anteriore al vincolo, dati importati). Con `->first()` senza
        // ordinamento l'esercizio «corrente» cambiava da una richiesta all'altra. La scelta ora è
        // fissa: quello che contiene la data di oggi, altrimenti il più recente. Con un solo
        // esercizio aperto il risultato è lo stesso di prima.
        $aperti = $condominio->esercizi()
            ->where('stato', 'aperto')
            ->orderByDesc('data_inizio')
            ->orderByDesc('id')
            ->get();

        if ($aperti->count() <= 1) {
            return $aperti->first();
        }

        $oggi = now()->startOfDay();

        return $aperti->first(fn ($e) => $e->data_inizio && $e->data_inizio->lte($oggi)
                && (! $e->data_fine || $e->data_fine->gte($oggi)))
            ?? $aperti->first();
    }
}