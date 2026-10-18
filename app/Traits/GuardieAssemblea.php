<?php

namespace App\Traits;

use App\Models\Assemblee\Assemblea;
use App\Models\Condominio;
use Illuminate\Validation\ValidationException;

/**
 * Guardie comuni ai controller del modulo Assemblee.
 *
 * `assicuraAssemblea()` è la difesa in profondità richiesta da `RotteAnnidateSenzaGuardiaTest`:
 * il vincolo di rotta (`scopeBindings`) già rifiuta l'assemblea di un altro condominio, ma la
 * guardia resta accesa se un giorno una rotta ne uscisse.
 */
trait GuardieAssemblea
{
    protected function assicuraAssemblea(Condominio $condominio, Assemblea $assemblea): void
    {
        abort_unless((int) $assemblea->condominio_id === (int) $condominio->id, 404);
    }

    /** Dati, ordine del giorno e destinatari: solo in bozza o convocata. */
    protected function assicuraDatiModificabili(Assemblea $assemblea): void
    {
        if (! $assemblea->stato->datiModificabili()) {
            throw ValidationException::withMessages([
                'stato' => 'L\'assemblea è '.mb_strtolower($assemblea->stato->label()).': dati e ordine del giorno non si modificano più.',
            ]);
        }
    }

    /** Presenze, deleghe, voti e testi del verbale: solo da convocata a svolta. */
    protected function assicuraPresenzeModificabili(Assemblea $assemblea): void
    {
        if (! $assemblea->stato->presenzeModificabili()) {
            throw ValidationException::withMessages([
                'stato' => $assemblea->stato->value === 'bozza'
                    ? 'Presenze e votazioni si registrano dopo la convocazione.'
                    : 'Il verbale è registrato: presenze e votazioni non si modificano più.',
            ]);
        }
    }
}
