<?php

namespace App\Exceptions\Pagamenti;

/**
 * Pagamento a un fornitore in contanti, o da una cassa contanti.
 *
 * Art. 1129 c. 7 c.c.: le somme erogate a qualunque titolo per conto del condominio devono
 * transitare sul conto corrente intestato al condominio. Per i corrispettivi di appalto (art.
 * 25-ter c. 1 DPR 600/1973) il c. 2-ter dello stesso articolo impone il pagamento tramite conto
 * corrente intestato al condominio o altre modalità tracciabili, con la sanzione dell'art. 11
 * c. 1 D.Lgs. 471/1997.
 *
 * **Bypassabile** con `conferma_contanti_fornitore = true` e una motivazione: il programma
 * registra fatti, e un pagamento in contanti già avvenuto va registrato com'è — vietarlo
 * spingerebbe a registrarlo come bonifico, cioè a falsare il registro. Il blocco serve a far
 * leggere la norma **prima**, quando si è ancora in tempo a pagare con bonifico.
 *
 * Il limite antiriciclaggio dei 5.000 € resta un blocco a sé, non bypassabile
 * (`IllegalCashAmountException`).
 *
 * HTTP: 409 Conflict (con istruzioni per override)
 */
class PagamentoContantiFornitoreException extends PagamentoException {}
