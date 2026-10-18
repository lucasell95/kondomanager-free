<?php

/**
 * Dati fiscali che DESCRIVONO, non GUIDANO comportamento: codici tributo,
 * aliquote storicizzate, festività, tassi legali. Vivono in config (versionati
 * in git) e non in tabelle — sono dati globali, non per-condominio, e la loro
 * storia sta già nel version control. Se manca una riga di aliquota valida
 * alla data richiesta, il calcolo deve fermarsi (mai un default silenzioso).
 *
 * Design: docs/design/f24_ritenute_design.md §2.3.
 */

return [

    'codici_tributo' => [
        '1001' => ['descrizione' => 'Ritenute su retribuzioni lavoro dipendente', 'sezione' => 'erario'],
        '1019' => ['descrizione' => 'Ritenuta su appalti (persone fisiche/società di persone IRPEF)', 'sezione' => 'erario'],
        '1020' => ['descrizione' => 'Ritenuta su appalti (soggetti IRES)', 'sezione' => 'erario'],
        // Il 1038 era il codice delle PROVVIGIONI, non del lavoro autonomo: soppresso dal
        // 01/01/2017 e sostituito dal 1040 (ris. AdE 13/E del 17/03/2016).
        '1038' => ['descrizione' => 'Ritenute su provvigioni (commissione, agenzia, mediazione, rappresentanza) — soppresso, sostituito da 1040', 'sezione' => 'erario', 'soppresso_dal' => '2017-01-01'],
        '1040' => ['descrizione' => "Ritenute su redditi di lavoro autonomo (art. 25 DPR 600/1973) e, dal 2017, su provvigioni (art. 25-bis)", 'sezione' => 'erario'],

        // ── Sanzioni da ravvedimento sulle ritenute ─────────────────────────────
        // Istituiti dalla ris. AdE 18/E del 28/04/2023, operativi dal 03/07/2023 al posto
        // dell'8906. Le descrizioni sono le denominazioni ufficiali della risoluzione: prima
        // qui 8947 era descritto come «interessi», 8948 come «imposte sostitutive» e 8949 come
        // «altre imposte dirette», tutti e tre sbagliati.
        //
        // Gli INTERESSI da ravvedimento non hanno un codice proprio: si sommano al tributo che
        // si ravvede e si versano con il suo stesso codice (es. 1019 o 1020), come dice la
        // stessa ris. 18/E/2023.
        //
        // [INCERTO] Quale dei tre abbinare alle ritenute 4% del condominio (1019/1020, art.
        // 25-ter) la risoluzione non lo dice: le fonti non ufficiali indicano 8948. Per questo
        // nessuno dei tre viene precompilato.
        '8947' => ['descrizione' => 'Sanzione per ravvedimento su ritenute erariali — redditi di lavoro dipendente, assimilati e assistenza fiscale', 'sezione' => 'erario', 'sanzione' => true, 'auto_precompila' => false],
        '8948' => ['descrizione' => 'Sanzione per ravvedimento su ritenute erariali — redditi di lavoro autonomo, provvigioni, redditi diversi e locazioni brevi', 'sezione' => 'erario', 'sanzione' => true, 'auto_precompila' => false],
        '8949' => ['descrizione' => 'Sanzione per ravvedimento su ritenute erariali — redditi di capitale', 'sezione' => 'erario', 'sanzione' => true, 'auto_precompila' => false],
    ],

    // Storicizzate: [regime][] => ['dal', 'al'?, 'aliquota', 'base', 'titolo', 'norma'].
    // TipoRitenuta::aliquota() cerca la prima riga con dal <= data <= (al ?? +inf).
    'aliquote' => [
        'appalto_4' => [
            ['dal' => '2007-01-01', 'aliquota' => 4.0, 'base' => 100, 'titolo' => 'acconto'],
        ],
        'lavoro_autonomo_20' => [
            ['dal' => '1973-09-29', 'aliquota' => 20.0, 'base' => 100, 'titolo' => 'acconto'],
        ],
        'provvigioni_base_50' => [
            ['dal' => '2007-01-01', 'aliquota' => 23.0, 'base' => 50, 'titolo' => 'acconto'],
        ],
        'provvigioni_base_20' => [
            ['dal' => '2007-01-01', 'aliquota' => 23.0, 'base' => 20, 'titolo' => 'acconto'],
        ],
        'non_residente_30' => [
            ['dal' => '1973-09-29', 'aliquota' => 30.0, 'base' => 100, 'titolo' => 'imposta'],
        ],
        'lavoro_dipendente' => [
            ['dal' => '1973-09-29', 'aliquota' => 0.0, 'base' => 100, 'titolo' => 'acconto'],
        ],
    ],

    // Plafond in centesimi, coerente col resto del progetto. null = mensile sempre.
    'plafond' => [
        'appalto_4' => 50000,
        'lavoro_autonomo_20' => 10000,
        'provvigioni_base_50' => 10000,
        'provvigioni_base_20' => 10000,
        'non_residente_30' => 10000,
        'lavoro_dipendente' => null,
    ],

    'riferimenti_normativi' => [
        'appalto_4' => [
            ['fino_al' => '2026-12-31', 'testo' => 'art. 25-ter DPR 600/1973'],
            ['dal' => '2027-01-01', 'testo' => 'art. 40 D.Lgs. 33/2025'],
        ],
        'lavoro_autonomo_20' => [
            ['testo' => 'art. 25 DPR 600/1973'],
        ],
        'provvigioni_base_50' => [
            ['testo' => 'art. 25-bis DPR 600/1973'],
        ],
        'provvigioni_base_20' => [
            ['testo' => 'art. 25-bis DPR 600/1973 (dichiarazione percipiente)'],
        ],
        'non_residente_30' => [
            ['testo' => 'art. 25 DPR 600/1973 (non residenti)'],
        ],
        'lavoro_dipendente' => [
            ['testo' => 'art. 23 DPR 600/1973'],
        ],
    ],

    'festivita_nazionali' => [
        '01-01', '01-06', '04-25', '05-01', '06-02', '08-15', '11-01', '12-08', '12-25', '12-26',
    ],

    'interessi_legali' => [
        ['dal' => '2024-01-01', 'tasso' => 2.5],
        ['dal' => '2025-01-01', 'tasso' => 2.0],
        ['dal' => '2026-01-01', 'tasso' => 1.6],
    ],

    // Come si aggregano più ritenute dello stesso mese in un'unica riga F24
    // quando il periodo di riferimento non coincide (Fase 3 — non ancora consumato in Fase 1).
    'mese_riferimento_cumulo' => 'righe_distinte',

];
