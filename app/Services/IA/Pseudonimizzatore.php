<?php

namespace App\Services\IA;

use App\Models\Condominio;
use App\Models\Fornitore;

/**
 * Toglie i dati personali dal contesto prima che parta verso l'IA, e li rimette nella risposta.
 *
 * ## Cosa fa
 *
 * 1. **Elimina** le chiavi che non devono mai partire: codice fiscale, partita IVA, IBAN, e-mail,
 *    PEC, telefoni, dati di nascita e documenti (vedi `CHIAVI_ESCLUSE`). Questi valori non tornano.
 * 2. **Sostituisce con un codice** nomi di persona, ragioni sociali, indirizzi e nome del
 *    condominio: `[PERSONA_1]`, `[AZIENDA_1]`, `[INDIRIZZO_1]`, `[CONDOMINIO_1]`. La tabella dei
 *    codici resta in memoria, nel solo oggetto che ha fatto la richiesta, e serve a `ricomponi()`.
 * 3. **Cerca nel testo libero** (note di seduta, istruzioni dell'utente) i nomi già noti, anche
 *    solo il cognome o in ordine inverso, e li sostituisce con lo stesso codice.
 * 4. **Rimuove i residui** che hanno una forma riconoscibile (e-mail, IBAN, codici fiscali,
 *    partite IVA, telefoni) anche se nessuno li aveva dichiarati.
 *
 * ## Da dove prende i nomi
 *
 * - dai campi del contesto con un nome che li identifica (`nominativo`, `ragione_sociale`,
 *   `indirizzo`, `cognome` + `nome`, `presidente`, `condominio.nome`…, vedi le costanti);
 * - dalla chiave riservata `_dati_personali` (lista di `['tipo' => 'persona'|'azienda'|'indirizzo'|'condominio', 'valore' => '…']`), che non viene inviata;
 * - da `condominio_id`: carica nome e indirizzo del condominio, le anagrafiche collegate e i
 *   fornitori con fatture nel condominio. Anche `condominio_id` non viene inviato.
 *
 * ## Limiti
 *
 * La ricerca nel testo è per parola intera, senza distinzione di maiuscole, ma **non** ignora
 * gli accenti e non riconosce soprannomi o errori di battitura. I dati pseudonimizzati restano
 * dati personali (considerando 26 GDPR): la pseudonimizzazione riduce il rischio, non lo azzera.
 */
class Pseudonimizzatore
{
    /** Chiavi il cui valore non parte mai (si cerca il nome della chiave senza maiuscole). */
    public const CHIAVI_ESCLUSE = [
        'codice_fiscale', 'cf', 'partita_iva', 'piva', 'p_iva', 'iban', 'conto_iban', 'bic', 'swift',
        'email', 'email_secondaria', 'pec', 'mail', 'telefono', 'cellulare', 'fax', 'sito_web',
        'data_nascita', 'luogo_nascita', 'tipologia_documento', 'numero_documento', 'scadenza_documento',
        'user_id', 'password', 'api_key', 'token',
    ];

    /** Chiavi il cui valore (stringa) è il nome di una persona. */
    public const CHIAVI_PERSONA = [
        'nominativo', 'nome_completo', 'persona', 'condomino', 'presidente', 'segretario', 'delegato',
        'delegante', 'intestatario', 'proprietario', 'inquilino', 'amministratore', 'referente', 'firmatario',
    ];

    /** Chiavi il cui valore (stringa) è una ragione sociale. */
    public const CHIAVI_AZIENDA = ['ragione_sociale', 'fornitore', 'denominazione_fornitore', 'ditta'];

    /** Chiavi il cui valore (stringa) è un indirizzo. */
    public const CHIAVI_INDIRIZZO = ['indirizzo', 'indirizzo_completo', 'sede', 'residenza', 'domicilio'];

    /** Chiavi il cui valore (stringa) è il nome del condominio, che spesso contiene la via. */
    public const CHIAVI_CONDOMINIO = ['denominazione_condominio', 'nome_condominio'];

    /** Parole che da sole non identificano nessuno: non diventano varianti di ricerca. */
    private const PAROLE_COMUNI = [
        'via', 'viale', 'piazza', 'piazzale', 'corso', 'largo', 'vicolo', 'strada', 'località', 'loc',
        'srl', 's.r.l.', 'srls', 'snc', 's.n.c.', 'sas', 's.a.s.', 'spa', 's.p.a.', 'coop', 'cooperativa',
        'di', 'de', 'del', 'della', 'dei', 'degli', 'da', 'dal', 'la', 'le', 'lo', 'il', 'e', 'ed',
        'condominio', 'studio', 'ditta', 'impresa', 'società', 'societa', 'sig', 'sig.', 'sig.ra',
        'signor', 'signora', 'dott', 'dott.', 'ing', 'ing.', 'geom', 'geom.', 'avv', 'avv.', 'rag', 'rag.',
        'san', 'santa', 'santo', 'nuova', 'nuovo', 'grande', 'servizi', 'pulizie', 'impianti', 'edile',
    ];

    private const FORME_SOCIETARIE = '/[\s,]+(s\.?r\.?l\.?s?|s\.?n\.?c\.?|s\.?a\.?s\.?|s\.?p\.?a\.?|soc\.?\s*coop\.?|&\s*c\.?)$/iu';

    /** Residui con forma riconoscibile → marcatore senza cifre, che non viene ricomposto. */
    private const RESIDUI = [
        'EMAIL_RIMOSSA'          => '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu',
        'IBAN_RIMOSSO'           => '/(?<![A-Z0-9])[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]){11,30}(?![A-Z0-9])/iu',
        'CODICE_FISCALE_RIMOSSO' => '/(?<![A-Z0-9])[A-Z]{6}[0-9LMNPQRSTUV]{2}[A-EHLMPRST][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z](?![A-Z0-9])/iu',
        'PARTITA_IVA_RIMOSSA'    => '/(?<!\d)(?<!\d[.,])\d{11}(?!\d)(?![.,]\d)/u',
        'TELEFONO_RIMOSSO'       => '/(?<![\d\w+])(?:(?:\+|00)39[ .\-]?)?(?:3\d{2}[ .\-]?\d{6,7}|0\d{1,3}[ .\-\/]?\d{5,8})(?![\d])/u',
    ];

    public const MARCATORI_RIMOSSI = ['EMAIL_RIMOSSA', 'IBAN_RIMOSSO', 'CODICE_FISCALE_RIMOSSO', 'PARTITA_IVA_RIMOSSA', 'TELEFONO_RIMOSSO'];

    /** @var list<array{tipo: string, valore: string, codice: ?string}> */
    private array $entita = [];

    /** @var array<string, int> valore normalizzato + tipo → indice in $entita */
    private array $indicePerValore = [];

    /** @var array<string, int> */
    private array $contatori = [];

    /** @var array<string, string> codice → valore originale */
    private array $mappa = [];

    /** @var array<string, int> marcatore → quante volte è stato applicato */
    private array $rimossi = [];

    /** Cache della regex di ricerca (si invalida a ogni nuova entità). */
    private ?array $ricerca = null;

    // ─── Registrazione ───────────────────────────────────────────────────────────

    public function registraPersona(?string $nominativo): void
    {
        $this->registra('PERSONA', $nominativo);
    }

    public function registraAzienda(?string $ragioneSociale): void
    {
        $this->registra('AZIENDA', $ragioneSociale);
    }

    public function registraIndirizzo(?string $indirizzo): void
    {
        $this->registra('INDIRIZZO', $indirizzo);
    }

    public function registraCondominio(?string $nome): void
    {
        $this->registra('CONDOMINIO', $nome);
    }

    /** Nome e indirizzo del condominio, anagrafiche collegate, fornitori con fatture nel condominio. */
    public function registraDaCondominio(Condominio $condominio): void
    {
        $this->registraCondominio($condominio->nome);
        $this->registraIndirizzo($condominio->indirizzo);

        foreach ($condominio->anagrafiche()->get(['anagrafiche.nome', 'anagrafiche.indirizzo']) as $anagrafica) {
            $this->registraPersona($anagrafica->nome);
            $this->registraIndirizzo($anagrafica->indirizzo);
        }

        $fornitori = Fornitore::query()
            ->whereIn('id', $condominio->fatture()->select('fornitore_id'))
            ->get(['ragione_sociale', 'indirizzo']);

        foreach ($fornitori as $fornitore) {
            $this->registraAzienda($fornitore->ragione_sociale);
            $this->registraIndirizzo($fornitore->indirizzo);
        }
    }

    private function registra(string $tipo, ?string $valore): ?int
    {
        $valore = trim(preg_replace('/\s+/u', ' ', (string) $valore));

        if (mb_strlen($valore) < 2) {
            return null;
        }

        $chiave = $tipo.'|'.self::normalizza($valore);

        if (! isset($this->indicePerValore[$chiave])) {
            $this->entita[] = ['tipo' => $tipo, 'valore' => $valore, 'codice' => null];
            $this->indicePerValore[$chiave] = array_key_last($this->entita);
            $this->ricerca = null;
        }

        return $this->indicePerValore[$chiave];
    }

    // ─── Pseudonimizzazione ──────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $contesto
     * @return array<string, mixed> Il contesto da inviare: senza dati esclusi e con i codici al posto dei nomi.
     */
    public function pseudonimizza(array $contesto): array
    {
        foreach ((array) ($contesto['_dati_personali'] ?? []) as $dato) {
            $tipo = strtoupper((string) ($dato['tipo'] ?? 'persona'));
            if (in_array($tipo, ['PERSONA', 'AZIENDA', 'INDIRIZZO', 'CONDOMINIO'], true)) {
                $this->registra($tipo, $dato['valore'] ?? null);
            }
        }
        unset($contesto['_dati_personali']);

        if (isset($contesto['condominio_id']) && is_numeric($contesto['condominio_id'])) {
            if ($condominio = Condominio::find((int) $contesto['condominio_id'])) {
                $this->registraDaCondominio($condominio);
            }
        }
        unset($contesto['condominio_id']);

        // Prima si raccolgono tutti i nomi dichiarati nei campi, poi si sostituisce: così un
        // cognome che compare in una nota prima del campo che lo dichiara viene trovato comunque.
        $this->raccogli($contesto, null);

        return $this->trasforma($contesto, null);
    }

    /** Pseudonimizza un testo libero (per esempio le istruzioni dell'utente). */
    public function pseudonimizzaTesto(string $testo): string
    {
        foreach (self::RESIDUI as $marcatore => $regex) {
            $testo = preg_replace_callback($regex, function () use ($marcatore) {
                $this->rimossi[$marcatore] = ($this->rimossi[$marcatore] ?? 0) + 1;

                return '['.$marcatore.']';
            }, $testo);
        }

        [$regex, $varianti] = $this->ricerca();

        if ($regex === null) {
            return $testo;
        }

        return preg_replace_callback($regex, function (array $m) use ($varianti) {
            $indice = $varianti[self::normalizza($m[0])] ?? null;

            return $indice === null ? $m[0] : $this->codice($indice);
        }, $testo);
    }

    private function raccogli(array $nodo, ?string $chiavePadre): void
    {
        if (isset($nodo['cognome']) && is_string($nodo['cognome'])) {
            $this->registra('PERSONA', trim(($nodo['nome'] ?? '').' '.$nodo['cognome']));
        }

        if ($chiavePadre === 'condominio' && isset($nodo['nome']) && is_string($nodo['nome'])) {
            $this->registra('CONDOMINIO', $nodo['nome']);
        }

        foreach ($nodo as $chiave => $valore) {
            $tipo = is_string($chiave) ? self::tipoDiChiave($chiave) : null;

            if (is_string($valore) && $tipo !== null) {
                $this->registra($tipo, $valore);
            } elseif (is_array($valore) && ! (is_string($chiave) && self::esclusa($chiave))) {
                $this->raccogli($valore, is_string($chiave) ? strtolower($chiave) : $chiavePadre);
            }
        }
    }

    private function trasforma(array $nodo, ?string $chiavePadre): array
    {
        $persona = null;
        if (isset($nodo['cognome']) && is_string($nodo['cognome'])) {
            $persona = $this->registra('PERSONA', trim(($nodo['nome'] ?? '').' '.$nodo['cognome']));
        }

        $risultato = [];

        foreach ($nodo as $chiave => $valore) {
            if (is_string($chiave) && self::esclusa($chiave)) {
                continue;
            }

            if ($persona !== null && in_array($chiave, ['nome', 'cognome'], true)) {
                if ($chiave === 'nome' || ! array_key_exists('nome', $nodo)) {
                    $risultato['nome'] = $this->codice($persona);
                }
                continue;
            }

            $tipo = is_string($chiave) ? self::tipoDiChiave($chiave) : null;
            if ($tipo === null && $chiavePadre === 'condominio' && $chiave === 'nome') {
                $tipo = 'CONDOMINIO';
            }

            if (is_string($valore) && $tipo !== null && ($indice = $this->registra($tipo, $valore)) !== null) {
                $risultato[$chiave] = $this->codice($indice);
            } elseif (is_string($valore)) {
                $risultato[$chiave] = $this->pseudonimizzaTesto($valore);
            } elseif (is_array($valore)) {
                $risultato[$chiave] = $this->trasforma($valore, is_string($chiave) ? strtolower($chiave) : $chiavePadre);
            } else {
                $risultato[$chiave] = $valore;
            }
        }

        return $risultato;
    }

    // ─── Ricomposizione ──────────────────────────────────────────────────────────

    /**
     * Rimette i valori originali al posto dei codici, in ogni stringa della struttura.
     * I codici sconosciuti restano come sono (il validatore li segnala).
     */
    public function ricomponi(mixed $dati): mixed
    {
        if (is_array($dati)) {
            return array_map(fn ($v) => $this->ricomponi($v), $dati);
        }

        if (! is_string($dati)) {
            return $dati;
        }

        return preg_replace_callback(
            '/\[(?:PERSONA|AZIENDA|INDIRIZZO|CONDOMINIO)_\d+\]/u',
            fn (array $m) => $this->mappa[$m[0]] ?? $m[0],
            $dati,
        );
    }

    /** @return list<string> I codici effettivamente usati nel contesto inviato. */
    public function codiciAssegnati(): array
    {
        return array_keys($this->mappa);
    }

    /** @return array<string, int> Marcatore → quante sostituzioni (e-mail, IBAN… trovati nel testo libero). */
    public function residuiRimossi(): array
    {
        return $this->rimossi;
    }

    // ─── Interni ─────────────────────────────────────────────────────────────────

    private function codice(int $indice): string
    {
        if ($this->entita[$indice]['codice'] === null) {
            $tipo = $this->entita[$indice]['tipo'];
            $this->contatori[$tipo] = ($this->contatori[$tipo] ?? 0) + 1;
            $codice = '['.$tipo.'_'.$this->contatori[$tipo].']';
            $this->entita[$indice]['codice'] = $codice;
            $this->mappa[$codice] = $this->entita[$indice]['valore'];
        }

        return $this->entita[$indice]['codice'];
    }

    /**
     * Regex unica con tutte le varianti (dalla più lunga) e mappa variante normalizzata → entità.
     * Una variante che appartiene a più entità (es. il cognome «Rossi» di due fratelli) diventa
     * un'entità a sé, con valore la variante stessa: niente fuga di dati e nessuna ricomposizione
     * sbagliata, al prezzo di un codice in più.
     *
     * @return array{0: ?string, 1: array<string, int>}
     */
    private function ricerca(): array
    {
        if ($this->ricerca !== null) {
            return $this->ricerca;
        }

        /** @var array<string, array{0: string, 1: list<int>}> $candidati normalizzata → [testo, entità] */
        $candidati = [];

        foreach ($this->entita as $indice => $e) {
            foreach (self::varianti($e['tipo'], $e['valore']) as $variante) {
                $norm = self::normalizza($variante);
                $candidati[$norm][0] = $variante;
                $candidati[$norm][1][] = $indice;
            }
        }

        $varianti = [];
        foreach ($candidati as $norm => [$testo, $indici]) {
            $indici = array_values(array_unique($indici));
            if (count($indici) === 1) {
                $varianti[$norm] = $indici[0];
                continue;
            }
            // Se l'ambiguità è fra tipi diversi (il nome del condominio che contiene la via) si
            // sceglie il tipo più prudente: un indirizzo prima di una persona, e così via.
            $tipi = array_map(fn (int $i) => $this->entita[$i]['tipo'], $indici);
            $tipo = current(array_intersect(['INDIRIZZO', 'PERSONA', 'AZIENDA', 'CONDOMINIO'], $tipi));
            $varianti[$norm] = $this->registra($tipo, $testo);
        }

        if ($varianti === []) {
            return $this->ricerca = [null, []];
        }

        $chiavi = array_keys($varianti);
        usort($chiavi, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        $alternative = array_map(
            fn (string $norm) => implode('[\s,]+', array_map(fn ($p) => preg_quote($p, '/'), explode(' ', $norm))),
            $chiavi,
        );

        $regex = '/(?<![\p{L}\p{N}])(?:'.implode('|', $alternative).')(?![\p{L}\p{N}])/iu';

        // registra() dentro il ciclo ha azzerato la cache: la si imposta solo ora.
        return $this->ricerca = [$regex, $varianti];
    }

    /** @return list<string> */
    private static function varianti(string $tipo, string $valore): array
    {
        $varianti = [$valore];

        if ($tipo === 'PERSONA') {
            $parole = preg_split('/\s+/u', $valore);
            $n = count($parole);

            // Rotazioni («Rossi Mario» ↔ «Mario Rossi», «De Luca Anna» ↔ «Anna De Luca») e loro
            // sottosequenze contigue, comprese le singole parole significative.
            for ($r = 0; $r < $n; $r++) {
                $rotazione = array_merge(array_slice($parole, $r), array_slice($parole, 0, $r));
                for ($i = 0; $i < $n; $i++) {
                    for ($l = 1; $i + $l <= $n; $l++) {
                        $varianti[] = implode(' ', array_slice($rotazione, $i, $l));
                    }
                }
            }
        } elseif ($tipo === 'AZIENDA') {
            $senzaForma = trim(preg_replace(self::FORME_SOCIETARIE, '', $valore));
            if ($senzaForma !== '') {
                $varianti[] = $senzaForma;
            }
        } elseif ($tipo === 'INDIRIZZO') {
            // «Via Roma 1, Milano» → anche «Via Roma 1»
            $primaParte = trim(explode(',', $valore)[0]);
            if (mb_strlen($primaParte) >= 6) {
                $varianti[] = $primaParte;
            }
        } elseif ($tipo === 'CONDOMINIO') {
            $senzaPrefisso = trim(preg_replace('/^condominio\s+/iu', '', $valore));
            if ($senzaPrefisso !== $valore && str_contains($senzaPrefisso, ' ')) {
                $varianti[] = $senzaPrefisso;
            }
        }

        return array_values(array_unique(array_filter($varianti, function (string $v) {
            $v = trim($v);
            if (str_contains($v, ' ')) {
                return true;
            }

            return mb_strlen($v) >= 3 && ! in_array(mb_strtolower($v), self::PAROLE_COMUNI, true);
        })));
    }

    private static function normalizza(string $testo): string
    {
        return mb_strtolower(trim(preg_replace('/[\s,]+/u', ' ', $testo)));
    }

    private static function esclusa(string $chiave): bool
    {
        return in_array(strtolower($chiave), self::CHIAVI_ESCLUSE, true);
    }

    private static function tipoDiChiave(string $chiave): ?string
    {
        $chiave = strtolower($chiave);

        return match (true) {
            in_array($chiave, self::CHIAVI_PERSONA, true)    => 'PERSONA',
            in_array($chiave, self::CHIAVI_AZIENDA, true)    => 'AZIENDA',
            in_array($chiave, self::CHIAVI_INDIRIZZO, true)  => 'INDIRIZZO',
            in_array($chiave, self::CHIAVI_CONDOMINIO, true) => 'CONDOMINIO',
            default => null,
        };
    }
}
