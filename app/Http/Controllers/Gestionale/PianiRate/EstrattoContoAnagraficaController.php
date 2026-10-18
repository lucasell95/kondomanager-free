<?php

namespace App\Http\Controllers\Gestionale\PianiRate;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Anagrafica;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\RataQuote;
use App\Helpers\MoneyHelper;
use App\Services\Gestionale\CreditoService;
use App\Services\Gestionale\EstrattoContoAggregatoService;
use App\Traits\HasEsercizio;
use App\Services\PDF\PdfService;
use Inertia\Inertia;
use Illuminate\Http\Request;

class EstrattoContoAnagraficaController extends Controller
{
    use HasEsercizio;

    /**
     * Genera l'Estratto Conto finanziario di una singola Anagrafica (vista Inertia).
     * Costruisce un "Ledger" (Libro Mastro) cronologico in Dare/Avere con saldo progressivo
     * "Waterfall" al centesimo, basato sul pattern Double-Entry.
     */
    public function show(Request $request, Condominio $condominio, Anagrafica $anagrafica)
    {
        $esercizio = $this->esercizioRichiesto($request, $condominio);

        $anagrafica->load(['immobili' => function($q) use ($condominio) {
            $q->where('condominio_id', $condominio->id);
        }]);

        [$timeline, $stats, $saldoInizialeCents] = $this->buildLedger($condominio, $esercizio, $anagrafica);

        // Vista aggregata (predefinita: una riga per movimento) o per unità immobiliare (una riga
        // per riga contabile, com'era). I totali in `$stats` vengono dalla timeline espansa e non
        // cambiano fra le due.
        $vista = EstrattoContoAggregatoService::vistaValida($request->query('vista'));

        return Inertia::render('gestionale/pianiRate/EstrattoContoAnagrafica', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'anagrafica' => $anagrafica,
            'timeline'   => $this->timelinePerVista($timeline, $saldoInizialeCents, $vista),
            'stats'      => $stats,
            'vista'      => $vista,
            // Gli esercizi fra cui scegliere (dal più recente): il selettore compare solo
            // quando ce n'è più di uno.
            'esercizi'   => $condominio->esercizi()
                ->orderByDesc('data_inizio')
                ->get(['id', 'nome', 'stato', 'data_inizio', 'data_fine']),
        ]);
    }

    /**
     * Genera il PDF dell'Estratto Conto di una singola Anagrafica.
     * Aperto in una nuova scheda dal frontend tramite window.open().
     */
    public function print(Request $request, Condominio $condominio, Anagrafica $anagrafica, PdfService $pdfService)
    {
        $esercizio = $this->esercizioRichiesto($request, $condominio);

        $anagrafica->load(['immobili' => function($q) use ($condominio) {
            $q->where('condominio_id', $condominio->id);
        }]);

        [$timeline, $stats, $saldoInizialeCents] = $this->buildLedger($condominio, $esercizio, $anagrafica);

        // Stessa scelta della pagina: `?vista=aggregata` (predefinita) o `?vista=unita`.
        $vista = EstrattoContoAggregatoService::vistaValida($request->query('vista'));

        $data = [
            'condominio'         => $condominio,
            'esercizio'          => $esercizio,
            'anagrafica'         => $anagrafica,
            'timeline'           => $this->timelinePerVista($timeline, $saldoInizialeCents, $vista),
            'stats'              => $stats,
            'saldoInizialeCents' => $saldoInizialeCents,
            'vista'              => $vista,
        ];

        $mpdf = $pdfService->generate('pdf.gestionale.estratto_conto_anagrafica', $data, [
            'orientation' => 'P',
            'margin_top'  => 30,
        ]);

        $nomeFile = 'EC_' . str_replace(' ', '_', $anagrafica->nome) . '_' . $esercizio->nome . '.pdf';
        $mpdf->SetHeader($condominio->nome . '||Estratto Conto – ' . $anagrafica->nome);

        return response($mpdf->Output($nomeFile, 'I'))
            ->header('Content-Type', 'application/pdf');
    }

    // -------------------------------------------------------------------------
    // HELPER PRIVATO — Logica condivisa tra show() e print()
    // -------------------------------------------------------------------------

    /**
     * L'esercizio di cui mostrare l'estratto conto: quello chiesto con `?esercizio=`, altrimenti
     * quello corrente (aperto), come sempre.
     *
     * Se non c'è nessun esercizio aperto (tutti chiusi) si ripiega sul più recente invece di
     * fallire: l'estratto conto è una lettura e deve restare consultabile anche fra la chiusura
     * di un esercizio e l'apertura del successivo.
     *
     * Un id che non è un esercizio di questo condominio risponde 404: ripiegare in silenzio su
     * un altro esercizio mostrerebbe numeri di un periodo diverso da quello chiesto.
     */
    private function esercizioRichiesto(Request $request, Condominio $condominio): Esercizio
    {
        $richiesto = $request->query('esercizio');

        if ($richiesto !== null && $richiesto !== '') {
            $esercizio = $condominio->esercizi()->whereKey((int) $richiesto)->first();

            abort_if(! $esercizio, 404);

            return $esercizio;
        }

        $esercizio = $this->getEsercizioCorrente($condominio)
            ?? $condominio->esercizi()->orderByDesc('data_inizio')->first();

        abort_if(! $esercizio, 404);

        return $esercizio;
    }

    /**
     * La timeline nella vista richiesta: espansa così com'è, o aggregata per movimento.
     */
    private function timelinePerVista($timeline, int $saldoInizialeCents, string $vista)
    {
        if ($vista === EstrattoContoAggregatoService::VISTA_UNITA) {
            return $timeline;
        }

        return app(EstrattoContoAggregatoService::class)->aggrega($timeline, $saldoInizialeCents);
    }

    /**
     * La data in cui l'operazione è avvenuta: la competenza della scrittura.
     *
     * È il campo che i flussi di registrazione riempiono con la data dell'operazione: la data di
     * emissione scelta per le rate (`EmissioneRateController`), la data del pagamento per incassi
     * e compensazioni (`StoreIncassoRateAction`), quella della scrittura originaria per gli
     * storni. La data di registrazione è invece il giorno in cui qualcuno l'ha inserita: un
     * incasso del 10/07 caricato il 18/10 stava in coda all'estratto conto, con la data sbagliata.
     *
     * Ripiega sulla registrazione per le scritture che la competenza non l'hanno.
     */
    private function dataOperazione($scrittura): ?\Carbon\CarbonInterface
    {
        return $scrittura?->data_competenza ?? $scrittura?->data_registrazione;
    }

    /**
     * Costruisce la timeline (ledger) e le statistiche per una anagrafica.
     * Restituisce [$timeline, $stats, $saldoInizialeCents].
     */
    private function buildLedger(Condominio $condominio, Esercizio $esercizio, Anagrafica $anagrafica): array
    {
        // Calcolo Saldo Iniziale (Crediti/Debiti portati dall'anno precedente)
        $saldoInizialeCents = $anagrafica->saldi()
            ->where('condominio_id', $condominio->id)
            ->where('esercizio_id', $esercizio->id)
            ->sum('saldo_iniziale'); 

        // I movimenti dell'anagrafica nell'esercizio richiesto, sui soli conti dei condòmini.
        //
        // ⚠️ **Per esercizio.** Il saldo iniziale qui sopra è quello dell'esercizio: è il residuo
        // degli anni prima. Prendendo anche i movimenti degli esercizi precedenti, dal secondo
        // esercizio quel residuo si contava due volte — una nel saldo iniziale, una nelle
        // emissioni e negli incassi vecchi che lo avevano prodotto.
        //
        // ⚠️ **Per mastro.** `anagrafica_id` sulle righe non vuol dire sempre «condòmino»: le
        // fatture e i pagamenti dei fornitori lo valorizzano con il primo referente del fornitore
        // (debiti v/fornitori, ritenute), le regolazioni immediate sul conto di costo. Se quel
        // referente è anche un condòmino, quelle righe finivano nel suo estratto conto. Restano i
        // conti che descrivono la posizione del condòmino verso il condominio: Crediti verso
        // condòmini (1101: rate emesse, incassi, compensazioni, storni) e Anticipi da condòmini
        // (2101: le eccedenze di un incasso senza una quota su cui appoggiarle). Si leggono per
        // `ruolo`, come fa chi le scrive, non per codice.
        $contiCondomini = ContoContabile::where('condominio_id', $condominio->id)
            ->whereIn('ruolo', ['crediti_condomini', 'anticipi_condomini'])
            ->pluck('id');

        // Esclusi anche i movimenti fittizi di cassa interna (`cassa_id`).
        $movimenti = $anagrafica->movimenti()
            ->whereHas('scrittura', function($q) use ($condominio, $esercizio) {
                $q->where('condominio_id', $condominio->id)
                  ->where('esercizio_id', $esercizio->id)
                  // Le scritture di riporto della chiusura d'esercizio (tipo `chiusura`) chiudono
                  // le quote vecchie dentro il nuovo esercizio: il loro effetto è già il saldo
                  // iniziale qui sopra, e contarle lo toglierebbe una seconda volta.
                  // Vedi `App\Actions\Gestionale\Chiusura\ChiudiEsercizioAction`.
                  ->whereNotIn('tipo_movimento', \App\Services\Gestionale\Chiusura\ResiduiEsercizioService::TIPI_ESCLUSI);
            })
            ->whereIn('conto_contabile_id', $contiCondomini)
            ->whereNull('cassa_id')
            ->with(['scrittura.gestione', 'rata', 'immobile']) 
            ->orderBy('created_at', 'asc') 
            ->orderBy('id', 'asc')
            ->get();

        // --- STEP 1: PRE-CARICAMENTO QUOTE (singola query per prevenire N+1) ---
        $rataIds = $movimenti
            ->filter(fn($r) => $r->rata && $r->tipo_riga === 'dare')
            ->pluck('rata.id')
            ->unique()
            ->values();

        $quoteMap = RataQuote::whereIn('rata_id', $rataIds)
            ->where('anagrafica_id', $anagrafica->id)
            ->get()
            ->keyBy(fn($q) => $q->rata_id . '_' . $q->immobile_id);

        
        // --- STEP 2: PRE-ELABORAZIONE QUOTE PURE E SALDI USATI ---
        $movimenti->each(function ($riga) use ($quoteMap) {
            $riga->quotaPura       = $riga->importo;
            $riga->saldoUsato      = 0;
            $riga->totaleRichiesto = $riga->importo;
            $riga->quotaRecord     = null;

            if ($riga->rata && $riga->tipo_riga === 'dare') {
                $key   = $riga->rata->id . '_' . $riga->immobile_id;
                $quota = $quoteMap->get($key);

                if ($quota) {
                    $riga->quotaRecord = $quota;
                    
                    $regole = $quota->regole_calcolo;

                    // Decodifica JSON Snapshot per isolare quota reale dal saldo compensato
                    if (!empty($regole)) {
                        $data = (array) $regole;
                        $importi = $data['importi'] ?? [];
                        $riga->saldoUsato      = $importi['saldo_usato'] ?? 0;
                        $riga->quotaPura       = $importi['quota_pura_gestione'] ?? ($riga->importo - $riga->saldoUsato);
                        $riga->totaleRichiesto = $importi['totale_calcolato'] ?? $riga->importo;
                    }
                }
            }
        });

        // --- STEP 3: ORDINAMENTO VISIVO INTELLIGENTE ---
        $movimenti = $movimenti->sortBy(function($riga) {
            // 0. Data dell'operazione (competenza): l'emissione al giorno dell'emissione, l'incasso
            //    al giorno del pagamento — non al giorno in cui sono stati registrati. Un incasso
            //    del 10/07 registrato il 18/10 sta fra le operazioni di luglio, e il saldo
            //    progressivo segue lo stesso ordine.
            // 1. Raggruppamento per scrittura contabile originaria
            // 2. Dare (0) precede Avere (1) per garantire un flusso visivo lineare del Saldo Progressivo
            // 3. A parità di riga, ordina per importo decrescente
            $ordineRiga = $riga->tipo_riga === 'dare' ? 0 : 1; 
            return [$this->dataOperazione($riga->scrittura)?->format('Y-m-d') ?? '', $riga->scrittura_id, $ordineRiga, -abs($riga->quotaPura)];
        })->values();

        // --- STEP 4: CREAZIONE TIMELINE & CALCOLO SALDI PROGRESSIVI ---
        $runningBalance = $saldoInizialeCents;
        
        $timeline = $movimenti->map(function ($riga) use (&$runningBalance) {
            
            $importoContabile = $riga->importo; 
            $quotaPura        = $riga->quotaPura;
            $saldoUsato       = $riga->saldoUsato;
            $totaleRichiesto  = $riga->totaleRichiesto;
            $quota            = $riga->quotaRecord;
            
            $waterfallStart = $runningBalance;
            // `tipo_movimento` è un enum (TipoMovimentoContabile): i confronti qui sotto sono su
            // stringhe, quindi serve il valore. Confrontando l'enum con una stringa ogni test era
            // falso: icone sempre generiche, e storni e prelievi da credito in dare trattati come
            // emissioni (sommavano la quota pura invece dell'importo contabile).
            $tm = $riga->scrittura->tipo_movimento ?? null;
            $tipoMovimento = $tm instanceof \BackedEnum ? $tm->value : ($tm ?: 'generico');
            
            // CALCOLO DARE E AVERE PROGRESSIVI (Motore Partita Doppia)
            if ($riga->tipo_riga === 'dare') {
                
                // Le rettifiche (storni) e gli utilizzi credito (salvadanaio) in Dare 
                // re-incrementano fisicamente il debito globale.
                if ($tipoMovimento === 'rettifica' || $tipoMovimento === 'storno_credito') {
                    $importoDaSommare = $importoContabile;
                } else {
                    // Emissione standard (ignora i crediti figurativi)
                    $importoDaSommare = $quotaPura;
                }

                $runningBalance += $importoDaSommare;
                $dare  = $importoDaSommare; 
                $avere = 0;

            } else {
                // Gli incassi in contanti, compensazioni e i rimborsi abbattono il debito.
                $runningBalance -= $importoContabile;
                $dare  = 0; 
                $avere = $importoContabile;
            }
            
            $waterfallEnd = $runningBalance;

            // ASSEGNAZIONE ICONE VISIVE
            $icona = 'file'; 
            if ($tipoMovimento === 'emissione_rata') $icona = 'bill';
            if ($tipoMovimento === 'incasso_rata')   $icona = 'payment';
            if ($tipoMovimento === 'saldo_iniziale') $icona = 'landmark';
            if ($tipoMovimento === 'rettifica' || $tipoMovimento === 'storno_credito') $icona = 'rotate-ccw'; 
            
            $dettagli  = [];
            $breakdown = null;
            $rataLabel = null;   // «Rata n.1 (Scad. …)», prima dei suffissi: serve alla vista aggregata
            $statoRata = null;

            if ($riga->rata) {
    
                $labelBase = "Rata" . ($riga->rata->numero_rata ? " n.{$riga->rata->numero_rata}" : "");
                if ($riga->rata->data_scadenza) {
                    $labelBase .= " (Scad. " . $riga->rata->data_scadenza->format('d/m/Y') . ")";
                }
                $rataLabel = $labelBase;

                // GESTIONE DESCRIZIONI E BREAKDOWN DELLE SINGOLE VOCI
                if ($riga->tipo_riga === 'dare') {
                    
                    // CASO A: È UNO STORNO O ANNULLAMENTO IN DARE (Ripristino Debito)
                    if ($tipoMovimento === 'rettifica') {
                        $dettagli[] = [
                            'type'   => 'rata',
                            'text'   => "Storno incasso su " . $labelBase,
                            'status' => 'stornata' // Badge grigio visivo
                        ];
                        $breakdown = [
                            'type'             => 'storno',
                            'start'            => MoneyHelper::fromCents($waterfallStart),
                            'cost'             => MoneyHelper::fromCents($importoContabile), 
                            'end'              => MoneyHelper::fromCents($waterfallEnd),
                            'immobile'         => $riga->immobile ? $riga->immobile->interno : 'Generico'
                        ];
                    }
                    // CASO B: PRELIEVO DAL SALVADANAIO
                    elseif ($tipoMovimento === 'storno_credito') {
                        $dettagli[] = [
                            'type'   => 'info',
                            'text'   => "Prelievo da salvadanaio per compensazione",
                            'status' => null
                        ];
                        $breakdown = [
                            'type'             => 'storno', 
                            'start'            => MoneyHelper::fromCents($waterfallStart),
                            'cost'             => MoneyHelper::fromCents($importoContabile), 
                            'end'              => MoneyHelper::fromCents($waterfallEnd),
                            'immobile'         => 'Generico'
                        ];
                    }
                    // CASO C: EMISSIONE STANDARD
                    else {
                        $statoRata = $quota ? $quota->stato : ($waterfallEnd <= 0 ? 'credito' : 'da_pagare');
                        
                        if ($saldoUsato > 0) {
                            $labelBase .= " + Recupero debito";
                        } elseif ($saldoUsato < 0) {
                            $labelBase .= " (Sconto da credito)";
                        }

                        $dettagli[] = [
                            'type'   => 'rata',
                            'text'   => $labelBase,
                            'status' => $statoRata 
                        ];

                        if ($saldoUsato != 0) {
                            if ($saldoUsato > 0) {
                                $dettagli[] = ['type' => 'info', 'text' => "Include recupero debito pregresso: " . MoneyHelper::format($saldoUsato), 'status' => null];
                                $dettagli[] = ['type' => 'info', 'text' => "Totale richiesto per questa rata: " . MoneyHelper::format($totaleRichiesto), 'status' => null];
                            } else {
                                $dettagli[] = ['type' => 'info', 'text' => "Scontata da credito pregresso: " . MoneyHelper::format(abs($saldoUsato)), 'status' => null];
                                $dettagli[] = ['type' => 'info', 'text' => "Valore originale della spesa: " . MoneyHelper::format($quotaPura), 'status' => null];
                            }
                        }

                        $breakdown = [
                            'type'             => 'emissione',
                            'start'            => MoneyHelper::fromCents($waterfallStart),
                            'cost'             => MoneyHelper::fromCents($quotaPura),
                            'totale_richiesto' => MoneyHelper::fromCents($totaleRichiesto),
                            'saldo_usato'      => $saldoUsato != 0 ? MoneyHelper::fromCents($saldoUsato) : null,
                            'end'              => MoneyHelper::fromCents($waterfallEnd),
                            'immobile'         => $riga->immobile ? $riga->immobile->interno : 'Generico'
                        ];
                    }

                } else {
                    // CASO D: AVERE (Incassi, Compensazioni, e RESTITUZIONE CREDITI DA STORNI)
                    
                    if ($tipoMovimento === 'rettifica') {
                        // FIX CHIRURGICO: Identifica le scritture in AVERE generate da uno Storno
                        $dettagli[] = [
                            'type'   => 'rata',
                            'text'   => "Ripristino credito nel salvadanaio",
                            'status' => 'stornata' 
                        ];
                    } elseif ($tipoMovimento === 'storno_credito') {
                        $dettagli[] = [
                            'type'   => 'rata',
                            'text'   => "Pagamento tramite credito su " . $labelBase,
                            'status' => null 
                        ];
                    } else {
                        $dettagli[] = [
                            'type'   => 'rata',
                            'text'   => "A copertura " . $labelBase,
                            'status' => null 
                        ];
                    }

                    $breakdown = [
                        'type'     => 'incasso',
                        'start'    => MoneyHelper::fromCents($waterfallStart),
                        'cost'     => MoneyHelper::fromCents($importoContabile),
                        'end'      => MoneyHelper::fromCents($waterfallEnd),
                        'immobile' => $riga->immobile ? $riga->immobile->interno : 'Generico'
                    ];
                }
            }

            if ($riga->immobile) {
                $dettagli[] = [
                    'type'   => 'immobile',
                    'text'   => $riga->immobile->nome . ($riga->immobile->interno ? " (Int. {$riga->immobile->interno})" : ""),
                    'status' => null
                ];
            }

            return [
                'id'          => $riga->id,
                // La data dell'operazione, non quella di registrazione (vedi `dataOperazione()`);
                // la registrazione resta come informazione secondaria.
                'data'        => $this->dataOperazione($riga->scrittura)?->format('d/m/Y') ?? '-',
                'data_registrazione' => $riga->scrittura->data_registrazione?->format('d/m/Y'),
                'protocollo'  => $riga->scrittura->numero_protocollo,
                'descrizione' => $riga->scrittura->causale ?: 'Movimento Contabile',
                'gestione'    => $riga->scrittura->gestione ? $riga->scrittura->gestione->nome : null,
                'dettagli'    => $dettagli, 
                'note'        => $riga->note, 
                'tipo_icona'  => $icona, 
                'dare'        => $dare, 
                'avere'       => $avere,
                'saldo'       => $waterfallEnd, 
                'breakdown'   => $breakdown,
                // --- Dati grezzi per la vista aggregata (EstrattoContoAggregatoService) ---
                // La vista aggregata ricompone le righe in movimenti e rifà le frasi sui totali:
                // le servono le chiavi di raggruppamento e i numeri prima della formattazione.
                // Importi in centesimi.
                'scrittura_id'     => $riga->scrittura_id,
                'tipo_riga'        => $riga->tipo_riga,
                'rata_id'          => $riga->rata?->id,
                'rata_label'       => $rataLabel,
                'rata_numero'      => $riga->rata?->numero_rata,
                'rata_scadenza'    => $riga->rata?->data_scadenza?->format('Y-m-d'),
                'stato_rata'       => $statoRata,
                'immobile_id'      => $riga->immobile_id,
                'immobile_label'   => $riga->immobile
                    ? $riga->immobile->nome . ($riga->immobile->interno ? " (Int. {$riga->immobile->interno})" : "")
                    : null,
                'quota_pura'       => (int) $quotaPura,
                'saldo_usato'      => (int) $saldoUsato,
                'totale_richiesto' => (int) $totaleRichiesto,
                'n_righe'          => 1,
            ];
        });

        // Credito disponibile (saldo iniziale a credito + strapagamenti), non
        // riusa $quoteMap perché quella è scoped alle rate presenti in questo
        // esercizio/timeline: qui serve la fotografia completa dell'anagrafica.
        //
        // Lo scarto di perimetro vale anche per il `compensabile` qui sotto, ed è più visibile
        // lì: la frase nomina una RATA, e quella rata può appartenere a un esercizio diverso da
        // quello della pagina, quindi non comparire nella timeline sopra. È voluto — la
        // posizione del condòmino non si interrompe al 31 dicembre — ma va saputo. Il PDF non
        // è coinvolto: `estratto_conto_anagrafica.blade.php` non stampa nessuna di queste
        // chiavi (verificato il 06/08/2026).
        $credito = app(CreditoService::class)->perAnagrafica($condominio->id, $anagrafica->id);

        // COMPILAZIONE STATISTICHE CENTESIMALI FINALI
        $stats = [
            'totale_addebiti'         => MoneyHelper::format($timeline->sum('dare')),
            'totale_versamenti'       => MoneyHelper::format($timeline->sum('avere')),
            'saldo_finale'            => MoneyHelper::format($runningBalance),
            'saldo_raw'               => $runningBalance,
            'saldo_iniziale'          => MoneyHelper::format($saldoInizialeCents),
            'saldo_iniziale_raw'      => $saldoInizialeCents,
            'credito_disponibile'     => $credito['totale_formatted'],
            'credito_disponibile_raw' => $credito['totale_cents'],
            'credito_per_gestione'    => $credito['per_gestione'],
            // Non solo quanto credito c'è, ma quale rata copre e dove si va a usarlo.
            'compensabile'            => $credito['compensabile']['importo_formatted'],
            'compensabile_raw'        => $credito['compensabile']['importo_cents'],
            'compensabile_frase'      => $credito['compensabile']['frase'],
            'compensabile_rata_id'    => $credito['compensabile']['rate_coperte'][0]['rata_id'] ?? null,
        ];

        return [$timeline, $stats, $saldoInizialeCents];
    }
}