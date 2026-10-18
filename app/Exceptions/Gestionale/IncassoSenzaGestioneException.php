<?php

namespace App\Exceptions\Gestionale;

/**
 * L'incasso non ha una gestione a cui appartenere: nessuna gestione scelta, nessuna rata da cui
 * ricavarla, e l'esercizio non ha gestioni.
 *
 * Prima `StoreIncassoRateAction` faceva `$esercizio->gestioni()->first()->id` e, senza gestioni,
 * rispondeva con una pagina 500. Estende la base della famiglia, quindi il controller la cattura
 * e torna al modulo con il motivo.
 */
class IncassoSenzaGestioneException extends IncassoNonRegistrabileException
{
    public function __construct()
    {
        parent::__construct(
            'L\'esercizio corrente non ha gestioni a cui intestare l\'incasso. '
            . 'Crea la gestione (ordinaria o straordinaria) dell\'esercizio, oppure scegli le rate da pagare.'
        );
    }

    public function campo(): string
    {
        return 'gestione_id';
    }
}
