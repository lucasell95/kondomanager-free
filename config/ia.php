<?php

/*
|--------------------------------------------------------------------------
| Generazione di bozze con l'IA (Anthropic Claude)
|--------------------------------------------------------------------------
|
| Le impostazioni che l'amministratore cambia dall'interfaccia (attivazione, chiave, modello,
| tetto di spesa) stanno in App\Settings\IASettings. Qui ci sono i valori tecnici che cambiano
| solo con una nuova versione del programma: indirizzo dell'API, tempi, prezzi per la stima dei
| costi e parametri per tipo di documento.
|
| Prezzi verificati il 18/10/2026 su https://platform.claude.com/docs/en/about-claude/pricing
| (dollari USA per milione di token). Servono solo a stimare la spesa e a far rispettare il
| tetto mensile: la fattura vera è quella della Console Anthropic.
*/

return [

    'url_base' => env('IA_ANTHROPIC_URL', 'https://api.anthropic.com'),

    // Versione dell'API HTTP (header anthropic-version). Stabile dal 2023.
    'versione_api' => '2023-06-01',

    'modello_predefinito' => 'claude-sonnet-5-5',

    // Modelli proposti nella pagina delle impostazioni. Il campo resta libero: gli ID cambiano a
    // ogni generazione e l'amministratore deve poterne scrivere uno nuovo senza aggiornare il programma.
    'modelli_suggeriti' => [
        'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (consigliato)',
        'claude-opus-5-5'   => 'Claude Opus 5.5 (qualità massima, costo doppio)',
        'claude-haiku-5-5'  => 'Claude Haiku 5.5 (economico)',
    ],

    // Secondi. Una bozza di verbale con ragionamento può richiedere più di un minuto.
    'timeout' => 120,
    'timeout_connessione' => 10,

    // Nuovi tentativi su errori temporanei (rete, 5xx, 529, 429 con retry-after): attese in millisecondi.
    'attese_nuovi_tentativi' => [2000, 6000],

    // Prezzi in dollari per milione di token. 'soglia_prompt_lungo': oltre questo numero di token
    // in ingresso la richiesta intera si paga ai prezzi «lunghi» (oggi solo Haiku 5.5).
    'prezzi' => [
        'claude-fable-5-1'  => ['ingresso' => 10.00, 'uscita' => 50.00],
        'claude-opus-5-5'   => ['ingresso' => 4.00,  'uscita' => 20.00],
        'claude-sonnet-5-5' => ['ingresso' => 2.00,  'uscita' => 10.00],
        'claude-haiku-5-5'  => [
            'ingresso' => 0.10, 'uscita' => 0.50,
            'soglia_prompt_lungo' => 100000,
            'ingresso_lungo' => 0.50, 'uscita_lunga' => 2.50,
        ],
    ],

    // Modello sconosciuto: si stima col listino più caro, così il tetto di spesa non viene
    // superato per una stima troppo ottimista.
    'prezzo_sconosciuto' => ['ingresso' => 10.00, 'uscita' => 50.00],

    // Parametri per tipo di documento. 'max_tokens' comprende il ragionamento del modello.
    'tipi' => [
        'convocazione'    => ['effort' => 'medium', 'max_tokens' => 8000],
        'verbale'         => ['effort' => 'medium', 'max_tokens' => 16000],
        'nota_preventivo' => ['effort' => 'high',   'max_tokens' => 16000],
        'nota_rendiconto' => ['effort' => 'high',   'max_tokens' => 16000],
    ],

    'tipo_predefinito' => ['effort' => 'medium', 'max_tokens' => 12000],

    // Cartella dei prompt (file Markdown modificabili senza toccare il codice).
    'cartella_prompt' => resource_path('prompts/ia'),
];
