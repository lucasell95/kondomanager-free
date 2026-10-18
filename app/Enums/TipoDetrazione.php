<?php

namespace App\Enums;

/**
 * Tipologia di detrazione fiscale per il Bonifico Parlante.
 *
 * Persistito come VARCHAR(50) su pagamenti_fornitori.tipo_detrazione.
 * NULL se il pagamento non è un bonifico parlante.
 *
 * Ogni case include il riferimento normativo ufficiale da inserire
 * nella causale del bonifico, evitando switch sparsi nel codice.
 *
 * NOTA: La banca trattiene automaticamente l'**11%** sul bonifico parlante e lo versa
 * all'A.d.E. (ritenuta ex art. 25 DL 78/2010, diversa dalla ritenuta d'acconto del
 * condominio). Era scritto 8%: l'aliquota è salita all'11% per i bonifici disposti dal
 * 1° marzo 2024 (L. 213/2023), e la pagina divulgativa dell'Agenzia riporta ancora il valore
 * vecchio — corretto qui il 04/08/2026, allineandolo a `MotivoEsclusioneRitenuta`, che l'11%
 * lo diceva già.
 *
 * Questo enum NON gestisce tale ritenuta — solo il riferimento normativo per la causale. Il
 * condominio non la versa e non la certifica: la opera la banca sul fornitore. Quel che il
 * condominio deve fare è **non applicare la propria**, ed è ciò che accade dal momento in cui
 * un pagamento è marcato come bonifico parlante (vedi `PagamentoFornitoreService`).
 */
enum TipoDetrazione: string
{
    case RISTRUTTURAZIONE = 'ristrutturazione';
    case ECOBONUS         = 'ecobonus';
    case SISMABONUS       = 'sismabonus';
    case SUPERBONUS       = 'superbonus';
    case ALTRO            = 'altro';

    // ─── Helpers ─────────────────────────────────────────────────────────────

    public function label(): string
    {
        return match($this) {
            self::RISTRUTTURAZIONE => 'Ristrutturazione edilizia',
            self::ECOBONUS         => 'Ecobonus / Riqualificazione energetica',
            self::SISMABONUS       => 'Sismabonus',
            self::SUPERBONUS       => 'Superbonus',
            self::ALTRO            => 'Altra detrazione',
        };
    }

    /**
     * Riferimento normativo ufficiale per la causale del bonifico parlante.
     * Inserito verbatim nella causale inviata alla banca.
     */
    public function riferimentoNormativo(): string
    {
        return match($this) {
            self::RISTRUTTURAZIONE => 'art. 16-bis DPR 917/1986',
            self::ECOBONUS         => 'art. 14 DL 63/2013',
            self::SISMABONUS       => 'art. 16 DL 63/2013',
            self::SUPERBONUS       => 'art. 119 DL 34/2020',
            self::ALTRO            => 'Normativa specifica',
        };
    }

    /**
     * Percentuale di detrazione standard (indicativa, può variare per anno/soglia).
     * NON usare per calcoli fiscali definitivi — sempre verificare normativa aggiornata.
     *
     * Aggiornate il 18/10/2026 (erano 50%, 50-65-90%, 50-70-80%, 110-90%). Per le spese 2025 e 2026
     * ristrutturazione, ecobonus e sismabonus hanno la stessa aliquota: 50% se l'unità è
     * l'abitazione principale del titolare del diritto, 36% negli altri casi (L. 207/2024,
     * prorogata per il 2026 dalla L. 199/2025 art. 1 c. 22); per il 2027 sono già previsti 36% e
     * 30%. Le maggiorazioni del sismabonus (70-85%) valevano fino al 2024. Fonti: EC News e
     * NotaiOnline (il sito dell'Agenzia non era raggiungibile).
     *
     * [INCERTO] Il superbonus nel 2026: dopo il 65% del 2025, riservato agli interventi già avviati
     * nei casi ammessi, resta solo per situazioni particolari — non confermato da fonte ufficiale.
     * [INCERTO] Per le parti comuni, quale delle due aliquote spetti a ciascun condòmino (in base
     * all'uso della sua unità).
     */
    public function percentualeDetrazione(): string
    {
        return match($this) {
            self::RISTRUTTURAZIONE => '50% abitazione principale / 36% altri (2025-2026); 36% / 30% nel 2027',
            self::ECOBONUS         => '50% abitazione principale / 36% altri (2025-2026); 36% / 30% nel 2027',
            self::SISMABONUS       => '50% abitazione principale / 36% altri (2025-2026); 36% / 30% nel 2027',
            self::SUPERBONUS       => 'Residuale: 65% nel 2025 solo per i casi ammessi; 2026 da verificare',
            self::ALTRO            => 'Variabile',
        };
    }

    /** Lunghezza del campo «informazioni di rimessa» non strutturate di un bonifico SEPA. */
    public const LIMITE_SEPA = 140;

    /**
     * La causale del bonifico parlante per lavori sulle **parti comuni** pagati dal condominio.
     *
     * Cosa deve risultare dal bonifico (guida AdE alle agevolazioni per ristrutturazioni, come
     * riportata dalle fonti citate nel report fiscale; il sito AdE non era raggiungibile):
     *  - la causale con il **riferimento normativo** della detrazione;
     *  - il **codice fiscale del condominio**, beneficiario della detrazione per le parti comuni;
     *  - il codice fiscale dell'**amministratore** o del condòmino che dispone il pagamento, quando
     *    lo si conosce (indicato da fonti secondarie concordi; qui facoltativo);
     *  - la **partita IVA** o, in mancanza, il **codice fiscale** del fornitore.
     * Numero e data della fattura sono utili ma non obbligatori.
     *
     * ⚠️ **Mai troncare un dato obbligatorio.** La versione precedente tagliava a 137 caratteri con
     * «...», e con sei codici fiscali dei condòmini la partita IVA del fornitore — obbligatoria —
     * finiva quasi sempre fuori. Qui si sacrifica solo ciò che è facoltativo (il riferimento alla
     * fattura); se i dati obbligatori da soli superano i 140 caratteri del bonifico SEPA, la causale
     * resta intera e il chiamante lo segnala: meglio un campo da accorciare a mano che una
     * detrazione persa.
     */
    public function generaCausalePartiComuni(
        ?string $cfCondominio,
        ?string $cfOrdinante,
        ?string $idFiscaleFornitore,
        ?string $riferimentoFattura = null,
        array $cfBeneficiariRipiego = [],
    ): string {
        $parti = ['Detrazione '.$this->riferimentoNormativo()];

        if (filled($cfCondominio)) {
            $parti[] = 'CF cond. '.strtoupper(trim($cfCondominio));
        } elseif ($cfBeneficiariRipiego !== []) {
            // Condominio senza codice fiscale (condominio minimo che non l'ha chiesto, circ. 7/E/2018):
            // si ripiega sui codici fiscali dei condòmini che detraggono, come faceva la versione 1.9.
            $parti[] = 'CF benef. '.implode(' ', array_map('strtoupper', $cfBeneficiariRipiego));
        } else {
            $parti[] = 'CF cond. DA INDICARE';
        }

        if (filled($cfOrdinante)) {
            $parti[] = 'CF amm. '.strtoupper(trim($cfOrdinante));
        }

        $parti[] = filled($idFiscaleFornitore)
            ? 'P.IVA forn. '.strtoupper(trim($idFiscaleFornitore))
            : 'P.IVA forn. DA INDICARE';

        $obbligatoria = implode(' - ', $parti);

        if (filled($riferimentoFattura)) {
            $completa = $obbligatoria.' - '.$riferimentoFattura;

            if (mb_strlen($completa) <= self::LIMITE_SEPA) {
                return $completa;
            }
        }

        return $obbligatoria;
    }

    /**
     * Genera la causale completa per il bonifico parlante.
     * Formato A.d.E.: tipo + riferimento normativo + CF beneficiari + P.IVA fornitore + FT.
     *
     * @deprecated Non usata dal programma: mette i codici fiscali dei condòmini, mentre per le
     *             parti comuni serve quello del condominio. Usa `generaCausalePartiComuni()`.
     *
     * @param  array  $cfBeneficiari   Lista di codici fiscali condòmini beneficiari
     * @param  string $partitaIvaFornitore
     * @param  string $numeroFattura
     * @param  string $dataFattura
     */
    public function generaCausale(
        array $cfBeneficiari,
        string $partitaIvaFornitore,
        string $numeroFattura,
        string $dataFattura
    ): string {
        $cfString = implode(', ', $cfBeneficiari);
        $tipo = $this->label();
        $normativa = $this->riferimentoNormativo();

        $causale = "Bonifico relativo a {$tipo} - {$normativa} - "
            . "Beneficiari CF: {$cfString} - "
            . "P.IVA: {$partitaIvaFornitore} - "
            . "FT {$numeroFattura} del {$dataFattura}";

        // Le causali bancarie SEPA hanno limite ~140 caratteri in RmtInf.
        // Se supera la soglia, il chiamante deve generare PDF allegato e
        // usare causale abbreviata con riferimento al PDF (es. "vedi distinta prot. XXX").
        return $causale;
    }

    /** Verifica se la causale generata rientra nel limite SEPA (~140 caratteri). */
    public function causaleEntraNelLimiteSEPA(
        array $cfBeneficiari,
        string $partitaIvaFornitore,
        string $numeroFattura,
        string $dataFattura
    ): bool {
        return strlen($this->generaCausale(
            $cfBeneficiari,
            $partitaIvaFornitore,
            $numeroFattura,
            $dataFattura
        )) <= 140;
    }
}