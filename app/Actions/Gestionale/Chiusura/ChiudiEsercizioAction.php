<?php

namespace App\Actions\Gestionale\Chiusura;

use App\Exceptions\Gestionale\ChiusuraEsercizioException;
use App\Models\Esercizio;
use App\Models\Gestionale\ChiusuraEsercizio;
use App\Models\Gestionale\ChiusuraEsercizioResiduo;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\ContoTabellaMillesimale;
use App\Models\Gestionale\ContoTabellaRipartizione;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Gestione;
use App\Models\Saldo;
use App\Services\Gestionale\Chiusura\ControlliChiusuraService;
use App\Services\Gestionale\Chiusura\NomiEsercizio;
use App\Services\Gestionale\Chiusura\PianoRiportoService;
use App\Services\Gestionale\Chiusura\ResiduiEsercizioService;
use App\Services\Gestionale\DoubleEntryValidator;
use App\Services\Gestionale\SaldoEsercizioService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Chiude un esercizio e apre il successivo, in una sola transazione.
 *
 * ## Il modello: niente conta due volte
 *
 * Il programma legge i dati in due modi, e la chiusura li deve tenere d'accordo:
 *
 *  - l'**estratto conto** è per esercizio: saldo iniziale dell'esercizio (tabella `saldi`) più i
 *    movimenti dell'esercizio sui conti del condòmino;
 *  - lo **stato patrimoniale** è cumulativo: i conti patrimoniali sommano le scritture di tutti
 *    gli esercizi fino a quello richiesto.
 *
 * Quindi il residuo di fine esercizio deve stare nel nuovo esercizio **una volta nella tabella
 * `saldi`** (lo legge l'estratto conto) e **mai una seconda volta nel giornale** (lo legge lo
 * stato patrimoniale, che ce l'ha già dalle scritture vecchie).
 *
 * Il punto delicato è il saldo riportato che un nuovo piano assorbe: alla sua emissione il
 * programma scrive DARE Crediti v/condòmini / AVERE Fondo passate gestioni per la parte di
 * riporto (`EmissioneRateController`), cioè rimette a giornale un credito che c'è già. Per questo,
 * quando le quote vecchie si riportano, la chiusura scrive nel nuovo esercizio una **scrittura
 * di riporto** (`tipo_movimento = chiusura`, protocollo `CHI-…`):
 *
 *     DARE  2301 Fondo passate gestioni          (totale riportato)
 *     AVERE 1101 Crediti v/condòmini             (per quota: anagrafica, unità, rata)
 *
 * che chiude le vecchie quote (pivot `quota_scrittura`: risultano pagate «per riporto», con il
 * riferimento) e che l'emissione del nuovo piano bilancia: 2301 torna a zero, 1101 resta uguale
 * al debito vero. L'estratto conto ignora le scritture `chiusura` (il loro effetto è il saldo
 * iniziale). Il vecchio esercizio non viene toccato: il suo stato patrimoniale a fine esercizio
 * mostra ancora i crediti verso i condòmini, come deve per il rendiconto.
 *
 * Non si scrivono chiusure dei conti economici: lo stato patrimoniale per esercizio porta già il
 * risultato degli esercizi precedenti come voce a sé (`risultato_esercizi_precedenti`).
 *
 * ## Che cosa fa, nell'ordine
 *
 * 1. riesegue i controlli bloccanti;
 * 2. chiude il vecchio esercizio e crea il successivo (date contigue, serie di protocollo);
 * 3. crea la nuova gestione ordinaria (con la copia del piano dei conti, se richiesta) e
 *    riaggancia le straordinarie che proseguono; spegne quelle che non proseguono;
 * 4. scrive le scritture di riporto e chiude le quote riportate;
 * 5. crea i saldi iniziali del nuovo esercizio (liberi per il riportato, bloccati per il
 *    mantenuto) e registra la fotografia dei residui.
 */
class ChiudiEsercizioAction
{
    public function __construct(
        private ControlliChiusuraService $controlli,
        private ResiduiEsercizioService $residui,
        private PianoRiportoService $piano,
        private SaldoEsercizioService $saldoService,
    ) {}

    /**
     * @param  array{
     *     nome?: string, descrizione?: string, data_fine?: string,
     *     numerazione_per_esercizio?: bool, copia_piano_conti?: bool,
     *     gestioni?: array<int|string, array{modalita?: string, riapri?: bool}>
     * }  $opzioni
     */
    public function execute(Esercizio $esercizio, array $opzioni, ?int $utenteId = null): ChiusuraEsercizio
    {
        return DB::transaction(function () use ($esercizio, $opzioni, $utenteId) {

            $esercizio = Esercizio::whereKey($esercizio->id)->lockForUpdate()->firstOrFail();

            $errori = $this->controlli->errori($esercizio);
            if ($errori) {
                throw new ChiusuraEsercizioException(
                    'La chiusura non si può eseguire: '.collect($errori)->pluck('titolo')->implode('; ').'.',
                    collect($errori)->pluck('dettaglio')->all()
                );
            }

            $residui = $this->residui->calcola($esercizio);
            $gestioni = $this->residui->gestioniCoinvolte($esercizio, $residui)->keyBy('id');
            $scelte = $this->scelte($gestioni, $opzioni['gestioni'] ?? []);

            // ── 1. Il vecchio si chiude, il nuovo si apre ─────────────────────────────────────
            $inizio = $esercizio->data_fine->copy()->addDay()->startOfDay();
            $fine = ! empty($opzioni['data_fine'])
                ? Carbon::parse($opzioni['data_fine'])->startOfDay()
                : $inizio->copy()->addYear()->subDay();

            if ($fine->lt($inizio)) {
                throw new ChiusuraEsercizioException('La data di fine del nuovo esercizio è precedente all\'inizio.');
            }

            $esercizio->update(['stato' => 'chiuso']);

            $nuovo = Esercizio::create([
                'condominio_id'    => $esercizio->condominio_id,
                'nome'             => trim($opzioni['nome'] ?? '') ?: NomiEsercizio::nomeEsercizio($inizio, $fine),
                'descrizione'      => trim($opzioni['descrizione'] ?? '') ?: NomiEsercizio::descrizione($inizio, $fine),
                'data_inizio'      => $inizio->toDateString(),
                'data_fine'        => $fine->toDateString(),
                'stato'            => 'aperto',
                'serie_protocollo' => ($opzioni['numerazione_per_esercizio'] ?? true) ? NomiEsercizio::serie($inizio, $fine) : null,
                'note'             => "Aperto dalla chiusura dell'esercizio «{$esercizio->nome}».",
            ]);

            $chiusura = ChiusuraEsercizio::create([
                'condominio_id'       => $esercizio->condominio_id,
                'esercizio_chiuso_id' => $esercizio->id,
                'esercizio_nuovo_id'  => $nuovo->id,
                'stato'               => ChiusuraEsercizio::STATO_ESEGUITA,
                'opzioni'             => $opzioni + ['gestioni_normalizzate' => $scelte],
                'eseguita_da'         => $utenteId,
                'eseguita_il'         => now(),
            ]);

            $riepilogo = [
                'gestioni_create'      => [],
                'gestioni_agganciate'  => [],
                'gestioni_disattivate' => [],
                'piani_conti_creati'   => [],
                'scritture'            => [],
                'quote_riportate'      => [],
                'saldi_creati'         => [],
                'saldi_bloccati'       => [],
                'totali'               => ['residuo' => 0, 'riportato' => 0, 'mantenuto' => 0],
            ];

            // ── 2. Le gestioni del nuovo esercizio ────────────────────────────────────────────
            $destinazioni = $this->preparaGestioni($esercizio, $nuovo, $gestioni, $scelte, $opzioni, $riepilogo);

            // ── 3. Riporti e saldi iniziali ──────────────────────────────────────────────────
            $this->riporta($esercizio, $nuovo, $chiusura, $residui, $gestioni, $scelte, $destinazioni, $riepilogo);

            $chiusura->update(['riepilogo' => $riepilogo]);

            Log::info('Chiusura esercizio eseguita', [
                'chiusura_id' => $chiusura->id, 'esercizio_chiuso' => $esercizio->id, 'esercizio_nuovo' => $nuovo->id,
                'totali' => $riepilogo['totali'], 'user_id' => $utenteId,
            ]);

            return $chiusura->fresh();
        });
    }

    /**
     * Le scelte per gestione, normalizzate e verificate.
     *
     * @return array<int, array{modalita: string, riapri: bool, destinazione: ?string}>
     */
    private function scelte(Collection $gestioni, array $richieste): array
    {
        $scelte = [];

        foreach ($gestioni as $id => $g) {
            $r = $richieste[$id] ?? $richieste[(string) $id] ?? [];
            $ordinaria = $g['tipo'] === 'ordinaria';

            // Default: l'ordinaria si riporta nel nuovo piano; una straordinaria dell'esercizio
            // prosegue con le sue rate (riagganciata, quote aperte).
            $riapri = $ordinaria ? true : (bool) ($r['riapri'] ?? $g['nell_esercizio']);
            $modalita = $r['modalita'] ?? ($ordinaria ? PianoRiportoService::RIPORTA : PianoRiportoService::MANTIENI);

            if (! in_array($modalita, [PianoRiportoService::RIPORTA, PianoRiportoService::MANTIENI], true)) {
                throw new ChiusuraEsercizioException("Modalità non valida per la gestione «{$g['nome']}».");
            }

            $destinazione = $this->piano->destinazione($g['tipo'], $riapri);

            if ($modalita === PianoRiportoService::RIPORTA && $destinazione === null) {
                throw new ChiusuraEsercizioException(
                    "La gestione «{$g['nome']}» non prosegue nel nuovo esercizio: il residuo non si può riportare in un "
                    .'nuovo piano. Riaggancia la gestione oppure lascia le quote aperte («mantieni»).'
                );
            }

            if ($modalita === PianoRiportoService::RIPORTA && $g['quote_non_emesse'] > 0) {
                throw new ChiusuraEsercizioException(
                    "La gestione «{$g['nome']}» ha {$g['quote_non_emesse']} quote non ancora emesse: riportare il residuo "
                    .'le lascerebbe orfane. Emetti le rate (o elimina il piano), oppure scegli «mantieni».'
                );
            }

            $scelte[$id] = ['modalita' => $modalita, 'riapri' => $riapri, 'destinazione' => $destinazione];
        }

        return $scelte;
    }

    /**
     * Crea la nuova ordinaria, riaggancia le straordinarie che proseguono e spegne le gestioni
     * che finiscono qui.
     *
     * @return array<int, ?int>  gestione di provenienza => gestione di destinazione (null = nessuna)
     */
    private function preparaGestioni(Esercizio $vecchio, Esercizio $nuovo, Collection $gestioni, array $scelte, array $opzioni, array &$riepilogo): array
    {
        $copiaPiano = (bool) ($opzioni['copia_piano_conti'] ?? true);
        $nuoveOrdinarie = [];

        // Una nuova ordinaria per ogni ordinaria dell'esercizio (di norma una sola).
        foreach ($gestioni->where('tipo', 'ordinaria')->where('nell_esercizio', true) as $g) {
            $vecchia = Gestione::findOrFail($g['id']);
            $nuova = $this->creaGestione($vecchia, $vecchio, $nuovo);
            $nuoveOrdinarie[$vecchia->id] = $nuova->id;
            $riepilogo['gestioni_create'][] = $nuova->id;

            if ($copiaPiano && $vecchia->pianoConto) {
                $riepilogo['piani_conti_creati'][] = $this->copiaPianoConti($vecchia->pianoConto, $nuova, $vecchio, $nuovo)->id;
            }
        }

        // Senza un'ordinaria nell'esercizio se ne crea comunque una: il nuovo esercizio non può
        // nascere senza gestioni (gli incassi senza rata non saprebbero dove andare).
        if (empty($nuoveOrdinarie)) {
            $nuova = Gestione::create([
                'condominio_id' => $vecchio->condominio_id,
                'nome'          => 'Gestione ordinaria '.NomiEsercizio::serie($nuovo->data_inizio, $nuovo->data_fine),
                'tipo'          => 'ordinaria',
                'attiva'        => true,
                'data_inizio'   => $nuovo->data_inizio,
                'data_fine'     => $nuovo->data_fine,
            ]);
            $nuovo->gestioni()->attach($nuova->id, ['attiva' => true, 'data_inizio' => $nuovo->data_inizio, 'data_fine' => $nuovo->data_fine]);
            $riepilogo['gestioni_create'][] = $nuova->id;
        }

        $ordinariaPredefinita = $nuoveOrdinarie ? reset($nuoveOrdinarie) : end($riepilogo['gestioni_create']);
        $destinazioni = [];

        foreach ($gestioni as $id => $g) {
            $scelta = $scelte[$id];
            $gestione = Gestione::findOrFail($id);

            if ($g['tipo'] === 'ordinaria') {
                $destinazioni[$id] = $nuoveOrdinarie[$id] ?? $ordinariaPredefinita;
            } elseif ($scelta['riapri']) {
                if (! $nuovo->gestioni()->where('gestioni.id', $id)->exists()) {
                    $nuovo->gestioni()->attach($id, ['attiva' => true, 'data_inizio' => $nuovo->data_inizio, 'data_fine' => $nuovo->data_fine]);
                    $riepilogo['gestioni_agganciate'][] = $id;
                }
                $destinazioni[$id] = $id;
                continue;
            } else {
                $destinazioni[$id] = null;
            }

            // Le gestioni che finiscono qui (la vecchia ordinaria, le straordinarie concluse) si
            // spengono: i moduli che propongono le gestioni «attive» non le offrono più.
            if ($gestione->attiva) {
                $riepilogo['gestioni_disattivate'][$id] = true;
                $gestione->update(['attiva' => false]);
            }
        }

        return $destinazioni;
    }

    private function creaGestione(Gestione $vecchia, Esercizio $vecchio, Esercizio $nuovo): Gestione
    {
        $nuova = Gestione::create([
            'condominio_id' => $vecchia->condominio_id,
            'nome'          => NomiEsercizio::rinomina($vecchia->nome, $vecchio->data_inizio, $vecchio->data_fine, $nuovo->data_inizio, $nuovo->data_fine),
            'descrizione'   => $vecchia->descrizione,
            'tipo'          => $vecchia->tipo,
            'attiva'        => true,
            'data_inizio'   => $nuovo->data_inizio,
            'data_fine'     => $nuovo->data_fine,
            'note'          => "Creata dalla chiusura dell'esercizio «{$vecchio->nome}» (prosegue «{$vecchia->nome}»).",
        ]);

        $nuovo->gestioni()->attach($nuova->id, ['attiva' => true, 'data_inizio' => $nuovo->data_inizio, 'data_fine' => $nuovo->data_fine]);

        return $nuova;
    }

    /**
     * Copia capitoli e voci (con tabelle, coefficienti, ripartizioni e importi di preventivo)
     * come base del preventivo del nuovo esercizio. Le voci tecniche (sopravvenienze generate
     * al volo) restano fuori: nascono da sole quando servono.
     */
    private function copiaPianoConti(PianoConto $origine, Gestione $gestione, Esercizio $vecchio, Esercizio $nuovo): PianoConto
    {
        $copia = PianoConto::create([
            'gestione_id'   => $gestione->id,
            'condominio_id' => $origine->condominio_id,
            'nome'          => NomiEsercizio::rinomina($origine->nome, $vecchio->data_inizio, $vecchio->data_fine, $nuovo->data_inizio, $nuovo->data_fine),
            'descrizione'   => $origine->descrizione,
            'note'          => $origine->note,
        ]);

        $conti = Conto::where('piano_conto_id', $origine->id)
            ->where(fn ($q) => $q->whereNull('is_tecnico')->orWhere('is_tecnico', false))
            ->orderByRaw('CASE WHEN parent_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('id')
            ->get();

        $mappa = [];
        $daCopiare = $conti->keyBy('id');

        // I padri prima dei figli, anche su più livelli (dati anteriori alla 1.9.2 ne hanno tre).
        while ($daCopiare->isNotEmpty()) {
            $avanzato = false;

            foreach ($daCopiare as $id => $conto) {
                if ($conto->parent_id && ! isset($mappa[$conto->parent_id]) && $daCopiare->has($conto->parent_id)) {
                    continue;
                }

                $nuovoConto = $conto->replicate(['piano_conto_id', 'parent_id']);
                $nuovoConto->piano_conto_id = $copia->id;
                $nuovoConto->parent_id = $conto->parent_id ? ($mappa[$conto->parent_id] ?? null) : null;
                $nuovoConto->save();
                $mappa[$id] = $nuovoConto->id;

                foreach (ContoTabellaMillesimale::where('conto_id', $id)->get() as $legame) {
                    $nuovoLegame = ContoTabellaMillesimale::create([
                        'conto_id'     => $nuovoConto->id,
                        'tabella_id'   => $legame->tabella_id,
                        'coefficiente' => $legame->coefficiente,
                    ]);

                    foreach (ContoTabellaRipartizione::where('conto_tabella_millesimale_id', $legame->id)->get() as $rip) {
                        ContoTabellaRipartizione::create([
                            'conto_tabella_millesimale_id' => $nuovoLegame->id,
                            'soggetto'                     => $rip->soggetto,
                            'percentuale'                  => $rip->percentuale,
                        ]);
                    }
                }

                $daCopiare->forget($id);
                $avanzato = true;
            }

            if (! $avanzato) {
                break; // un ciclo nei padri: non dovrebbe esistere, e non si gira all'infinito
            }
        }

        return $copia;
    }

    private function riporta(Esercizio $vecchio, Esercizio $nuovo, ChiusuraEsercizio $chiusura, Collection $residui,
        Collection $gestioni, array $scelte, array $destinazioni, array &$riepilogo): void
    {
        $condominioId = (int) $vecchio->condominio_id;
        $conti = ContoContabile::where('condominio_id', $condominioId)
            ->whereIn('ruolo', ['crediti_condomini', 'passate_gestioni'])
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('ruolo');

        $toccate = [];

        foreach ($residui->groupBy('gestione_id') as $gestioneId => $righe) {
            $gestioneId = (int) $gestioneId;
            $scelta = $scelte[$gestioneId] ?? ['modalita' => PianoRiportoService::MANTIENI, 'riapri' => false, 'destinazione' => null];
            $destinazioneId = $destinazioni[$gestioneId] ?? null;
            $nomeGestione = $gestioni[$gestioneId]['nome'] ?? "#{$gestioneId}";

            $righeRiporto = [];   // [conto_id, tipo_riga, importo, anagrafica, immobile, rata, nota]
            $quoteDaChiudere = [];
            $parti = [];

            foreach ($righe as $chiave => $r) {
                $p = $this->piano->ripartisci($r, $scelta['modalita'], $scelta['destinazione']);
                $parti[$chiave] = $p;

                if ($p['riporta_quote']) {
                    foreach ($r['quote_debito'] as $q) {
                        $righeRiporto[] = [$conti['crediti_condomini']->id ?? null, 'avere', $q['importo'], $r['anagrafica_id'], $r['immobile_id'], $q['rata_id'], 'Quota riportata al nuovo esercizio'];
                        $quoteDaChiudere[] = $q;
                    }
                }

                if ($p['riporta_extra']) {
                    foreach ($r['extra'] as $contoId => $netto) {
                        if ($netto === 0) {
                            continue;
                        }
                        $righeRiporto[] = [$contoId, $netto > 0 ? 'avere' : 'dare', abs($netto), $r['anagrafica_id'], $r['immobile_id'], null, 'Movimento senza rata riportato al nuovo esercizio'];
                    }
                }
            }

            // ── La scrittura di riporto della gestione ──────────────────────────────────────
            $scrittura = null;
            if ($righeRiporto) {
                if (! isset($conti['crediti_condomini'], $conti['passate_gestioni'])) {
                    throw new ChiusuraEsercizioException('Mancano i conti «Crediti v/condòmini» o «Fondo passate gestioni»: il riporto non si può scrivere.');
                }

                $scrittura = ScritturaContabile::create([
                    'condominio_id'      => $condominioId,
                    'esercizio_id'       => $nuovo->id,
                    'gestione_id'        => $destinazioneId ?? $gestioneId,
                    'data_registrazione' => now(),
                    'data_competenza'    => $nuovo->data_inizio,
                    'causale'            => "Riporto residui «{$vecchio->nome}» → «{$nuovo->nome}» — {$nomeGestione}",
                    'tipo_movimento'     => 'chiusura',
                    'stato'              => 'registrata',
                    'created_by'         => $chiusura->eseguita_da,
                    'note'               => "Chiusura d'esercizio n. {$chiusura->id}: le quote si chiudono qui e il loro importo è "
                        ."saldo iniziale del nuovo esercizio. L'estratto conto non mostra questa scrittura: il suo effetto è il saldo iniziale.",
                ]);

                $totale = 0;
                foreach ($righeRiporto as [$contoId, $tipo, $importo, $anagraficaId, $immobileId, $rataId, $nota]) {
                    $scrittura->righe()->create([
                        'conto_contabile_id' => $contoId,
                        'anagrafica_id'      => $anagraficaId,
                        'immobile_id'        => $immobileId,
                        'rata_id'            => $rataId,
                        'tipo_riga'          => $tipo,
                        'importo'            => $importo,
                        'note'               => $nota,
                    ]);
                    $totale += $tipo === 'avere' ? $importo : -$importo;
                }

                if ($totale !== 0) {
                    $scrittura->righe()->create([
                        'conto_contabile_id' => $conti['passate_gestioni']->id,
                        'tipo_riga'          => $totale > 0 ? 'dare' : 'avere',
                        'importo'            => abs($totale),
                        'note'               => 'Contropartita del riporto: la riprende l\'emissione del piano che assorbe il saldo iniziale',
                    ]);
                }

                DoubleEntryValidator::validateOrFail($scrittura->id);
                $riepilogo['scritture'][] = $scrittura->id;

                $riferimento = "Riportata in «{$nuovo->nome}» ({$scrittura->numero_protocollo})";
                foreach ($quoteDaChiudere as $q) {
                    $quota = RataQuote::lockForUpdate()->findOrFail($q['quota_id']);
                    $riepilogo['quote_riportate'][] = [
                        'quota_id' => $quota->id, 'scrittura_id' => $scrittura->id, 'importo' => $q['importo'],
                        'riferimento_prima' => $quota->riferimento_pagamento,
                    ];

                    $quota->pagamenti()->attach($scrittura->id, [
                        'importo_pagato' => $q['importo'],
                        'data_pagamento' => $nuovo->data_inizio,
                    ]);
                    $quota->ricalcolaStato();
                    $quota->update(['riferimento_pagamento' => mb_substr($riferimento, 0, 255)]);
                }
            }

            // ── I saldi iniziali del nuovo esercizio e la fotografia ───────────────────────
            foreach ($righe as $chiave => $r) {
                $p = $parti[$chiave];
                $destinazioneSaldo = $destinazioneId ?? $gestioneId;

                if ($p['riportato'] !== 0) {
                    $saldo = Saldo::create([
                        'esercizio_id'          => $nuovo->id,
                        'condominio_id'         => $condominioId,
                        'anagrafica_id'         => $r['anagrafica_id'],
                        'immobile_id'           => $r['immobile_id'],
                        'gestione_id'           => $destinazioneSaldo,
                        'saldo_iniziale'        => $p['riportato'],
                        'origine'               => 'automatico',
                        'is_applicato'          => false,
                        'descrizione'           => "Residuo «{$vecchio->nome}» riportato ({$nomeGestione})",
                        'chiusura_esercizio_id' => $chiusura->id,
                    ]);
                    $riepilogo['saldi_creati'][] = $saldo->id;
                    $toccate[$destinazioneSaldo] = true;
                }

                if ($p['mantenuto'] !== 0) {
                    // Bloccato: nessun piano lo assorbe. Il debito (o il credito) resta sulle quote
                    // originarie e lì si incassa o si compensa; questo saldo serve solo a far
                    // partire l'estratto conto del nuovo esercizio dal numero giusto.
                    $saldo = Saldo::create([
                        'esercizio_id'          => $nuovo->id,
                        'condominio_id'         => $condominioId,
                        'anagrafica_id'         => $r['anagrafica_id'],
                        'immobile_id'           => $r['immobile_id'],
                        'gestione_id'           => $gestioneId,
                        'saldo_iniziale'        => $p['mantenuto'],
                        'origine'               => 'automatico',
                        'is_applicato'          => true,
                        'descrizione'           => ($p['mantenuto'] > 0
                            ? "Residuo «{$vecchio->nome}» da incassare sulle rate originarie"
                            : "Credito «{$vecchio->nome}» compensabile dalle quote originarie")." ({$nomeGestione})",
                        'chiusura_esercizio_id' => $chiusura->id,
                    ]);
                    $riepilogo['saldi_creati'][] = $saldo->id;
                    $toccate[$gestioneId] = true;
                }

                if ($p['riporta_saldi_liberi'] && $r['saldi_liberi']) {
                    // Passati alla nuova gestione: nel vecchio wallet non devono più poter essere
                    // assorbiti, o il condòmino li pagherebbe due volte.
                    // Marcati con la chiusura: `Saldo::eDaChiusura()` impedisce di sbloccarli a mano.
                    Saldo::whereIn('id', $r['saldi_liberi'])->whereNull('piano_rate_id')
                        ->update(['is_applicato' => true, 'chiusura_esercizio_id' => $chiusura->id]);
                    array_push($riepilogo['saldi_bloccati'], ...$r['saldi_liberi']);
                    $toccate[$gestioneId] = true;
                }

                ChiusuraEsercizioResiduo::create([
                    'chiusura_esercizio_id'    => $chiusura->id,
                    'anagrafica_id'            => $r['anagrafica_id'],
                    'immobile_id'              => $r['immobile_id'],
                    'gestione_id'              => $gestioneId ?: null,
                    'gestione_destinazione_id' => $destinazioneId,
                    'modalita'                 => $scelta['modalita'],
                    'saldo_iniziale'           => $r['saldo_iniziale'],
                    'residuo'                  => $r['residuo'],
                    'riportato'                => $p['riportato'],
                    'mantenuto'                => $p['mantenuto'],
                    'conguaglio'               => $r['conguaglio'],
                ]);

                if ($r['anagrafica_id']) {
                    $riepilogo['totali']['residuo'] += $r['residuo'];
                    $riepilogo['totali']['riportato'] += $p['riportato'];
                    $riepilogo['totali']['mantenuto'] += $p['mantenuto'];
                }
            }
        }

        // Il flag di gestione `saldo_applicato` è un derivato dei saldi bloccati: va riallineato.
        foreach (array_keys($toccate) as $id) {
            if ($g = Gestione::find($id)) {
                $this->saldoService->allineaFlagGestione($g);
            }
        }
    }
}
