<?php

namespace App\Services\Gestionale;

use App\Enums\StatoPianoRate;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Services\PianoRateQuoteService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * # Prospetto rate unificato: tutte le rate dell'esercizio, per condòmino e per scadenza
 *
 * Nello stesso esercizio convivono più piani rate, uno per gestione (l'ordinaria e una o più
 * straordinarie), spesso con le **stesse scadenze**. L'amministratore vuole comunicare al
 * condòmino un importo solo per ogni scadenza: questo service somma, per ogni anagrafica, le rate
 * di tutti i piani che cadono nella stessa data.
 *
 * ## È una vista di lettura, non una fusione
 *
 * Le gestioni restano separate in contabilità: qui non si scrive niente, non si sposta niente e
 * non si compensa niente. Ogni cella porta con sé il **dettaglio per piano**, così il numero
 * unificato resta sempre riconducibile alle sue parti.
 *
 * ## Una fonte sola: `PianoRateQuoteService::quotePerAnagrafica()`
 *
 * I numeri **non si ricalcolano dalle quote**: si prende, piano per piano, esattamente la prop
 * `quotePerAnagrafica` che `PianoRateController@show` passa alla pagina del piano, e la si somma.
 * Così il prospetto non può divergere dalla somma delle pagine dei singoli piani — se un giorno
 * quella logica cambia (stato della rata, crediti, Rata Zero), cambia qui da sola.
 *
 * Anche le formule di versato, scaduto e credito ricalcano **una per una** quelle della pagina del
 * piano (`PianiRateShow.vue` e `resources/js/lib/gestionale/pianiRate/credito.ts`): vedi i metodi
 * privati qui sotto, ciascuno col riferimento alla sua controparte.
 *
 * ## Quali piani entrano
 *
 * Tutti i piani **attivi** (`attivo = true`) agganciati all'esercizio — lo stesso filtro della
 * lista `PianoRateController@index`, più `attivo` — **sia approvati sia in bozza**. Una bozza ha
 * già le sue quote, e l'amministratore che prepara la comunicazione vuole vederle: per questo ogni
 * piano porta il suo stato, e ogni scadenza dice se è emessa, in bozza o emessa solo in parte.
 *
 * Tutti gli importi sono in **centesimi interi**, come il resto del payload del gestionale.
 */
class ProspettoRateUnificatoService
{
    /** Chiave di colonna per le rate senza data di scadenza (non dovrebbero esistere, ma non si perdono). */
    public const SENZA_DATA = 'senza-data';

    public function __construct(
        private readonly PianoRateQuoteService $quoteService,
    ) {}

    /**
     * I piani rate attivi dell'esercizio, ordinaria prima e straordinarie dopo.
     *
     * Le relazioni caricate sono quelle che `quotePerAnagrafica()` attraversa: senza, il service
     * le caricherebbe una quota alla volta.
     *
     * @return Collection<int, PianoRate>
     */
    public function pianiDellEsercizio(Condominio $condominio, Esercizio $esercizio): Collection
    {
        return PianoRate::with([
                'gestione',
                'rate' => fn ($q) => $q->orderBy('numero_rata'),
                'rate.rateQuote.anagrafica',
                'rate.rateQuote.immobile',
                'rate.rateQuote.rata',
            ])
            ->where('condominio_id', $condominio->id)
            ->where('attivo', true)
            ->whereHas('gestione.esercizi', fn ($q) => $q->where('esercizio_id', $esercizio->id))
            ->get()
            ->sort(function (PianoRate $a, PianoRate $b) {
                $chiave = fn (PianoRate $p) => [
                    $p->gestione?->tipo === 'ordinaria' ? 0 : 1,
                    (int) $p->gestione_id,
                    mb_strtolower((string) $p->nome),
                ];

                return $chiave($a) <=> $chiave($b);
            })
            ->values();
    }

    /**
     * Costruisce il prospetto completo dell'esercizio.
     *
     * @param  Carbon|null  $oggi  La data di riferimento per lo «scaduto» (default: oggi). Serve ai test.
     * @return array{
     *     oggi: string,
     *     piani: list<array<string, mixed>>,
     *     scadenze: list<array<string, mixed>>,
     *     righe: list<array<string, mixed>>,
     *     totali: array<string, mixed>
     * }
     */
    public function calcola(Condominio $condominio, Esercizio $esercizio, ?Carbon $oggi = null): array
    {
        $oggi = ($oggi ?? Carbon::today())->copy()->startOfDay();
        $piani = $this->pianiDellEsercizio($condominio, $esercizio);

        /** @var array<string, array<string, mixed>> $colonne  chiave scadenza => intestazione */
        $colonne = [];
        /** @var array<int, array<string, mixed>> $perAnagrafica  anagrafica_id => anagrafica + pezzi */
        $perAnagrafica = [];
        $elencoPiani = [];

        foreach ($piani as $piano) {
            // Emessa = almeno una quota della rata ha la sua scrittura a giornale. È lo stesso
            // criterio di `ratePure` in PianoRateController@show, letto dalle quote già caricate.
            $emessaPerNumero = $piano->rate->mapWithKeys(fn ($rata) => [
                (int) $rata->numero_rata => $rata->rateQuote->contains(fn ($q) => $q->scrittura_contabile_id !== null),
            ]);

            $totalePiano = 0;

            foreach ($this->quoteService->quotePerAnagrafica($piano) as $riga) {
                $anagraficaId = (int) $riga['anagrafica']['id'];

                $perAnagrafica[$anagraficaId] ??= [
                    'anagrafica' => $riga['anagrafica'],
                    'pezzi'      => [],
                ];

                foreach ($riga['rate'] as $rata) {
                    $chiave = $rata['scadenza'] ?? self::SENZA_DATA;
                    $isEmessa = (bool) ($emessaPerNumero[(int) $rata['numero']] ?? false);
                    $pezzo = $this->pezzo($piano, $rata, $isEmessa);

                    $perAnagrafica[$anagraficaId]['pezzi'][] = ['chiave' => $chiave] + $pezzo;
                    $totalePiano += $pezzo['importo'];

                    $colonne[$chiave] ??= [
                        'chiave'  => $chiave,
                        'data'    => $rata['scadenza'],
                        'scaduta' => $this->eScaduta($chiave, $oggi),
                        'piani'   => [],
                    ];
                    $colonne[$chiave]['piani'][$piano->id . '-' . (int) $rata['numero']] = [
                        'piano_id'    => $piano->id,
                        'piano_nome'  => $piano->nome,
                        'numero_rata' => (int) $rata['numero'],
                        'is_emessa'   => $isEmessa,
                    ];
                }
            }

            $elencoPiani[] = [
                'id'             => $piano->id,
                'nome'           => $piano->nome,
                'tipo'           => $piano->tipo,
                'stato'          => $this->statoPiano($piano),
                'numero_rate'    => (int) $piano->numero_rate,
                'gestione'       => [
                    'id'   => $piano->gestione?->id,
                    'nome' => $piano->gestione?->nome,
                    'tipo' => $piano->gestione?->tipo,
                ],
                'totale'         => $totalePiano,
            ];
        }

        // Colonne in ordine di data; quella «senza data», se c'è, in fondo.
        uksort($colonne, function (string $a, string $b) {
            if ($a === self::SENZA_DATA) return 1;
            if ($b === self::SENZA_DATA) return -1;

            return strcmp($a, $b);
        });
        $chiavi = array_keys($colonne);

        $righe = collect($perAnagrafica)
            ->map(fn (array $dati) => $this->riga($dati, $chiavi, $oggi, $elencoPiani))
            ->sortBy(fn (array $riga) => mb_strtolower((string) $riga['anagrafica']['nome']))
            ->values()
            ->all();

        // Intestazioni di colonna con i totali della scadenza e lo stato di emissione.
        $scadenze = [];
        foreach ($colonne as $chiave => $colonna) {
            $celle = array_values(array_filter(array_map(fn ($r) => $r['celle'][$chiave] ?? null, $righe)));
            $flagEmissione = array_column($colonna['piani'], 'is_emessa');

            $scadenze[] = [
                'chiave'         => $chiave,
                'data'           => $colonna['data'],
                'scaduta'        => $colonna['scaduta'],
                'emissione'      => $this->emissione($flagEmissione),
                'piani'          => array_values($colonna['piani']),
                'importo'        => array_sum(array_column($celle, 'importo')),
                'importo_pagato' => array_sum(array_column($celle, 'importo_pagato')),
                'versato'        => array_sum(array_column($celle, 'versato')),
                'residuo'        => array_sum(array_column($celle, 'residuo')),
                'credito'        => array_sum(array_column($celle, 'credito')),
            ];
        }

        $totale = array_sum(array_column($righe, 'totale'));
        $versato = array_sum(array_column($righe, 'versato'));

        return [
            'oggi'     => $oggi->format('Y-m-d'),
            'piani'    => $elencoPiani,
            'scadenze' => $scadenze,
            'righe'    => $righe,
            'totali'   => [
                'totale'     => $totale,
                'versato'    => $versato,
                'da_saldare' => $totale - $versato,
                'scaduto'    => array_sum(array_column($righe, 'scaduto')),
                'crediti'    => array_sum(array_column($righe, 'crediti')),
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // COSTRUZIONE DELLE RIGHE
    // -------------------------------------------------------------------------

    /**
     * Una rata di un piano per un'anagrafica, con i valori derivati già calcolati.
     *
     * @param  array<string, mixed>  $rata  Una voce di `quotePerAnagrafica()[*]['rate']`.
     * @return array<string, mixed>
     */
    private function pezzo(PianoRate $piano, array $rata, bool $isEmessa): array
    {
        $importo = (int) $rata['importo'];
        $pagato = (int) $rata['importo_pagato'];
        $stato = (string) $rata['stato'];

        return [
            'piano_id'       => $piano->id,
            'piano_nome'     => $piano->nome,
            'piano_tipo'     => $piano->tipo,
            'piano_stato'    => $this->statoPiano($piano),
            'gestione_nome'  => $piano->gestione?->nome,
            'gestione_tipo'  => $piano->gestione?->tipo,
            'numero_rata'    => (int) $rata['numero'],
            'importo'        => $importo,
            'importo_pagato' => $pagato,
            'versato'        => $this->versato($stato, $pagato),
            'residuo'        => $this->aperto($stato, $importo, $pagato),
            'credito'        => $this->creditoDisponibile($importo, $pagato),
            'stato'          => $stato,
            'is_emessa'      => $isEmessa,
            'data_pagamento' => $rata['data_pagamento'] ?? null,
        ];
    }

    /**
     * La riga di un'anagrafica: celle unificate per scadenza, totali, e le sottorighe per piano.
     *
     * @param  array{anagrafica: array<string, mixed>, pezzi: list<array<string, mixed>>}  $dati
     * @param  list<string>  $chiavi
     * @param  list<array<string, mixed>>  $elencoPiani
     * @return array<string, mixed>
     */
    private function riga(array $dati, array $chiavi, Carbon $oggi, array $elencoPiani): array
    {
        $pezzi = collect($dati['pezzi']);
        $celle = $this->celle($pezzi, $chiavi);

        // Sottorighe: le stesse celle viste un piano alla volta, per la riga espandibile e la stampa.
        // Passano anch'esse da `celle()`: un piano può avere due rate nella stessa data (la Rata
        // Zero dei saldi e la prima rata, per esempio), e vanno sommate, non sovrascritte.
        $sottorighe = [];
        foreach ($elencoPiani as $piano) {
            $pezziPiano = $pezzi->where('piano_id', $piano['id'])->values();
            if ($pezziPiano->isEmpty()) {
                continue;
            }

            $celleP = $this->celle($pezziPiano, $chiavi);

            $sottorighe[] = [
                'piano_id'      => $piano['id'],
                'piano_nome'    => $piano['nome'],
                'piano_tipo'    => $piano['tipo'],
                'piano_stato'   => $piano['stato'],
                'gestione_nome' => $piano['gestione']['nome'],
                'gestione_tipo' => $piano['gestione']['tipo'],
                'celle'         => $celleP,
            ] + $this->totaliRiga($celleP, $oggi);
        }

        return [
            'anagrafica' => $dati['anagrafica'],
            'celle'      => $celle,
            'sottorighe' => $sottorighe,
        ] + $this->totaliRiga($celle, $oggi);
    }

    /**
     * Raggruppa i pezzi per scadenza, nell'ordine delle colonne.
     *
     * @param  Collection<int, array<string, mixed>>  $pezzi
     * @param  list<string>  $chiavi
     * @return array<string, array<string, mixed>>
     */
    private function celle(Collection $pezzi, array $chiavi): array
    {
        $celle = [];

        foreach ($chiavi as $chiave) {
            $pezziCella = $pezzi->filter(fn ($p) => $p['chiave'] === $chiave)->values();
            if ($pezziCella->isEmpty()) {
                continue;
            }

            $celle[$chiave] = $this->cella($pezziCella) + [
                'dettaglio' => $pezziCella->map(fn ($p) => collect($p)->except('chiave')->all())->all(),
            ];
        }

        return $celle;
    }

    /**
     * Somma i pezzi di una scadenza e ne ricava stato ed emissione.
     *
     * @param  Collection<int, array<string, mixed>>  $pezzi
     * @return array<string, mixed>
     */
    private function cella(Collection $pezzi): array
    {
        $importo = (int) $pezzi->sum('importo');
        $residuo = (int) $pezzi->sum('residuo');
        $versato = (int) $pezzi->sum('versato');

        return [
            'importo'        => $importo,
            'importo_pagato' => (int) $pezzi->sum('importo_pagato'),
            'versato'        => $versato,
            'residuo'        => $residuo,
            'credito'        => (int) $pezzi->sum('credito'),
            'stato'          => $this->statoCella($pezzi, $importo, $residuo, $versato),
            'emissione'      => $this->emissione($pezzi->pluck('is_emessa')->all()),
            'n_piani'        => $pezzi->pluck('piano_id')->unique()->count(),
        ];
    }

    /**
     * Totale anno, versato, da saldare, scaduto e crediti di un insieme di celle.
     *
     * - **Da saldare** = totale − versato: è il «Saldo» della pagina del piano.
     * - **Scaduto** = residuo delle scadenze già arrivate (≤ oggi): è la colonna «Scadute».
     *
     * @param  array<string, array<string, mixed>>  $celle
     * @return array{totale: int, versato: int, da_saldare: int, scaduto: int, crediti: int}
     */
    private function totaliRiga(array $celle, Carbon $oggi): array
    {
        $totale = 0;
        $versato = 0;
        $scaduto = 0;
        $crediti = 0;

        foreach ($celle as $chiave => $cella) {
            $totale += (int) $cella['importo'];
            $versato += (int) $cella['versato'];
            $crediti += (int) $cella['credito'];

            if ($this->eScaduta((string) $chiave, $oggi)) {
                $scaduto += (int) $cella['residuo'];
            }
        }

        return [
            'totale'     => $totale,
            'versato'    => $versato,
            'da_saldare' => $totale - $versato,
            'scaduto'    => $scaduto,
            'crediti'    => $crediti,
        ];
    }

    // -------------------------------------------------------------------------
    // FORMULE — ricalcano la pagina del piano, una per una
    // -------------------------------------------------------------------------

    /**
     * Quanto è entrato in cassa per la rata.
     *
     * Ricalca `versatoRataCents()` di `resources/js/lib/gestionale/pianiRate/credito.ts`: conta
     * `importo_pagato`, e solo per le rate pagate o pagate in parte (Coda 69).
     */
    private function versato(string $stato, int $pagato): int
    {
        return in_array($stato, ['pagata', 'parzialmente_pagata'], true) ? $pagato : 0;
    }

    /**
     * Quanto resta aperto sulla rata.
     *
     * Ricalca il contributo della rata alla colonna «Scadute» di `PianiRateShow.vue`
     * (`dataWithMap`): niente per le rate pagate, annullate o a credito; la differenza per le
     * parziali; l'importo intero per quelle da pagare.
     *
     * ⚠️ È un residuo **lordo dei crediti**, come nella pagina del piano: un credito di un'altra
     * gestione non chiude una rata di questa, finché nessuno registra la compensazione. Il credito
     * disponibile viaggia a parte, nella sua colonna.
     */
    private function aperto(string $stato, int $importo, int $pagato): int
    {
        return match ($stato) {
            'pagata', 'annullata', 'credito' => 0,
            'parzialmente_pagata' => $importo - $pagato,
            default => $importo,
        };
    }

    /**
     * Il credito disponibile sulla rata.
     *
     * Ricalca `creditoDisponibileCents()` di `credito.ts`, a sua volta copia di
     * `RataQuote::getCreditoDisponibileAttribute()`: importo negativo non ancora consumato, oppure
     * eccedenza versata oltre il dovuto. Mai sotto zero.
     */
    private function creditoDisponibile(int $importo, int $pagato): int
    {
        if ($importo < 0) {
            return max(0, abs($importo) - abs($pagato));
        }

        return max(0, $pagato - $importo);
    }

    /**
     * Lo stato della scadenza unificata.
     *
     * Stessa scala di `quotePerAnagrafica()` (annullata, credito, pagata, parziale, da pagare), ma
     * letta sui totali della scadenza: un credito che supera il debito rende la scadenza «credito»,
     * una scadenza senza residui è «pagata», con residui e qualche versamento è «parziale».
     *
     * @param  Collection<int, array<string, mixed>>  $pezzi
     */
    private function statoCella(Collection $pezzi, int $importo, int $residuo, int $versato): string
    {
        if ($pezzi->every(fn ($p) => $p['stato'] === 'annullata')) return 'annullata';
        if ($importo < 0) return 'credito';
        if ($residuo <= 0 && $importo > 0) return 'pagata';
        if ($residuo > 0 && $versato > 0) return 'parzialmente_pagata';

        return 'da_pagare';
    }

    /**
     * Emessa, in bozza o emessa solo in parte (alcuni piani sì, altri no).
     *
     * @param  list<bool>  $flag
     */
    private function emissione(array $flag): string
    {
        if ($flag === [] || ! in_array(true, $flag, true)) return 'bozza';
        if (! in_array(false, $flag, true)) return 'emessa';

        return 'parziale';
    }

    private function eScaduta(string $chiave, Carbon $oggi): bool
    {
        if ($chiave === self::SENZA_DATA) {
            return false;
        }

        return Carbon::parse($chiave)->startOfDay()->lte($oggi);
    }

    private function statoPiano(PianoRate $piano): string
    {
        return $piano->stato instanceof StatoPianoRate ? $piano->stato->value : (string) $piano->stato;
    }
}
