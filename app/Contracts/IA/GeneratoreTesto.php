<?php

namespace App\Contracts\IA;

/**
 * Generatore di testi con IA (bozze di convocazioni, verbali, note di preventivo e rendiconto).
 *
 * Regola del progetto: l'IA scrive solo testo. Numeri, millesimi, quorum, esiti dei voti e dati
 * anagrafici li produce il programma; nel testo generato compaiono come segnaposto
 * `{{F:chiave}}` che il chiamante sostituisce con i valori veri dopo la validazione.
 *
 * L'implementazione è legata nel container da `IAServiceProvider`: senza chiave API configurata
 * si usa `GeneratoreTestoNullo`, che dichiara `disponibile() === false`, e i chiamanti ripiegano
 * sui modelli fissi.
 */
interface GeneratoreTesto
{
    /** L'IA è configurata e attiva? Se no, i chiamanti usano il testo predefinito. */
    public function disponibile(): bool;

    /**
     * @param  string  $tipoDocumento  Es. 'convocazione', 'verbale', 'nota_preventivo', 'nota_rendiconto'.
     * @param  array<string, mixed>  $contesto  Dati strutturati del condominio e del documento. I nomi
     *         di persona vengono pseudonimizzati dall'implementazione prima dell'invio.
     * @param  array<string, mixed>  $schema  JSON Schema dell'output atteso.
     * @param  string|null  $istruzioni  Indicazioni dell'utente (es. l'ordine del giorno in testo libero).
     */
    public function genera(string $tipoDocumento, array $contesto, array $schema, ?string $istruzioni = null): RisultatoGenerazione;
}
