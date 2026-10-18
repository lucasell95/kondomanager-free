<?php

namespace App\Actions\Gestionale\Movimenti;

use App\Enums\Fiscale\NaturaPercipiente;
use App\Enums\Fiscale\StatoDelegaF24;
use App\Enums\Fiscale\TipoRitenuta;
use App\Enums\StatoPagamentoFornitore;
use App\Models\Condominio;
use App\Models\Gestionale\DelegaF24;
use App\Models\Gestionale\PagamentoFornitore;
use App\Models\Gestionale\RigaF24;
use App\Services\Gestionale\PlafondRitenuteService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Costruisce le deleghe F24 in bozza a partire dalle ritenute già operate e non ancora
 * versate.
 *
 * L'ordine dei passi non è arbitrario:
 *
 *  1. **Dai pagamenti**, non dalle fatture. È il pagamento il fatto generatore della
 *     ritenuta (art. 25-ter, *«all'atto del pagamento»*), quindi è la sua data a decidere
 *     il mese di riferimento in F24 e la scadenza del versamento. Che in contabilità il
 *     conto 2202 si accenda ancora alla registrazione della fattura è un'altra questione,
 *     ed è la Fase 2 del design: non cambia quale sia la data fiscalmente giusta.
 *  2. **Esclude ciò che è già in una delega**, tramite la pivot: rigenerare non deve mai
 *     produrre un secondo versamento delle stesse ritenute.
 *  3. **Raggruppa con `PlafondRitenuteService`**, che applica le soglie e le date di legge.
 *  4. **Spezza in righe** per codice tributo × mese × anno, e **splitta la delega** oltre
 *     le sei righe che il tracciato ammette.
 *
 * Le deleghe nascono in `bozza`: nessun effetto contabile finché non si conferma il
 * versamento. Rigenerare cancella le bozze precedenti e le ricostruisce — è il motivo per
 * cui il plafond è una funzione pura e non un contatore: si ricalcola da capo senza paura.
 */
class GeneraDelegheF24Action
{
    /**
     * Il tracciato F24 ammette sei righe di sezione Erario per modello.
     *
     * Pubblica da quando esiste la stampa: lo stesso sei vive anche in
     * `ModelloF24Service::RIGHE_SEZIONE_ERARIO`, perché di là è il numero di righe *stampate*
     * sul foglio e di qua è il punto in cui la delega si spezza. Sono due fatti distinti che
     * devono coincidere, e `ModelloF24StampaTest` li tiene agganciati: se divergessero, una
     * delega da sette righe perderebbe la settima **senza dirlo a nessuno**.
     */
    public const MAX_RIGHE_PER_DELEGA = 6;

    public function __construct(private PlafondRitenuteService $plafond) {}

    /**
     * Ricostruisce le bozze del condominio.
     *
     * `$esercizioId` è solo l'esercizio a cui **appoggiare** le deleghe nuove (la colonna è
     * obbligatoria, e la conferma del versamento scrive lì la sua scrittura): non filtra più le
     * ritenute. Vedi `ritenuteDaVersare()` per il perché.
     *
     * @return \Illuminate\Support\Collection<int,DelegaF24>
     */
    public function esegui(Condominio $condominio, int $esercizioId): \Illuminate\Support\Collection
    {
        return DB::transaction(function () use ($condominio, $esercizioId) {

            // Le bozze si rifanno da zero: non sono documenti, sono una proposta.
            //
            // ⚠️ **Tutte le bozze del condominio, non solo quelle dell'esercizio corrente.** Una
            // bozza nata quando l'esercizio corrente era un altro tiene «occupati» i suoi
            // pagamenti (vedi `$giaVersati` sotto): se restasse in piedi, le sue ritenute non
            // rientrerebbero nel cumulo e la soglia dei 500 € si conterebbe su due mucchi
            // separati — che è esattamente il difetto che questa correzione chiude.
            DelegaF24::where('condominio_id', $condominio->id)
                ->where('stato', StatoDelegaF24::BOZZA->value)
                ->each(fn (DelegaF24 $d) => $d->righe()->delete() && $d->delete());

            $ritenute = $this->ritenuteDaVersare($condominio);

            if ($ritenute->isEmpty()) {
                return collect();
            }

            $gruppi = $this->plafond->raggruppaPerScadenza($ritenute->all());

            $create = collect();

            foreach ($gruppi as $gruppo) {
                foreach ($this->spezzaInDeleghe($gruppo) as $righeDelega) {
                    $create->push($this->creaDelega($condominio, $esercizioId, $gruppo, $righeDelega));
                }
            }

            return $create;
        });
    }

    /**
     * Le ritenute operate e non ancora coperte da una delega viva.
     *
     * Un pagamento stornato non ha più ritenuta da versare: lo storno l'ha annullata.
     *
     * È **pubblica** perché serve a due chiamanti: qui, per costruire le deleghe, e allo
     * scadenzario, per sapere se il ricalcolo è necessario — cioè per rispondere alla
     * domanda «c'è qualcosa che non ho ancora messo in nessuna delega?» senza duplicare
     * la definizione di «scoperta», che è la parte delicata.
     *
     * ## Su TUTTI gli esercizi, non su quello corrente
     *
     * Fino al 18/10/2026 qui c'era un filtro sull'esercizio della scrittura del pagamento, e il
     * chiamante passava l'**ultimo esercizio aperto**. Era sbagliato: l'obbligo di versamento è
     * del condominio come **sostituto d'imposta**, e segue la **data del pagamento** (soglia dei
     * 500 € e finestre 16/6, 16/12, 16/1 dell'art. 25-ter c. 2-bis DPR 600/1973), non
     * l'esercizio condominiale in cui il pagamento è stato registrato. Con un esercizio
     * 01/06–31/05 per mesi convivono due esercizi aperti — il vecchio in attesa del rendiconto e
     * il nuovo — e le ritenute registrate sul vecchio sparivano dallo scadenzario: versate tardi
     * (cumulo spezzato in due, soglia non raggiunta) o mai. Lo stesso valeva per le ritenute non
     * ancora versate di un esercizio già chiuso.
     *
     * Il parametro `$esercizioId` resta nella firma solo per compatibilità con i chiamanti
     * esistenti ed è **ignorato**.
     *
     * @return \Illuminate\Support\Collection<int,array<string,mixed>>
     */
    public function ritenuteDaVersare(Condominio $condominio, ?int $esercizioId = null): \Illuminate\Support\Collection
    {
        // Solo le deleghe ANCORA IN PIEDI bloccano un pagamento. Dopo uno storno le
        // ritenute tornano da versare, e devono rientrare nel calcolo: escluderle
        // guardando solo la pivot le farebbe sparire per sempre dal plafond.
        return $this->ritenuteNonCoperte($condominio, [
            StatoDelegaF24::STORNATA,
            StatoDelegaF24::ANNULLATA,
        ]);
    }

    /**
     * La scadenza di versamento di ogni ritenuta non ancora versata, pagamento per pagamento,
     * **esattamente come la calcola lo scadenzario F24**.
     *
     * Serve al promemoria dell'Inbox (`PromemoriaRitenuteService`), che prima calcolava per
     * conto suo «il 16 del mese successivo» a ogni pagamento e ignorava le soglie: per due
     * ritenute 4% da 6,00 € di luglio diceva di versare il 17/08 con due F24, mentre lo
     * scadenziario proponeva una sola delega da 12,00 € entro il 16/12 (collaudo 1, E1). Qui
     * non c'è una seconda regola: si usa lo stesso `PlafondRitenuteService` sullo stesso
     * insieme di ritenute che userebbe «Aggiorna scadenze».
     *
     * - Ritenute in una delega **confermata**: righe congelate, vale la sua scadenza.
     * - Tutte le altre non versate (in bozza o non ancora in nessuna delega): raggruppate come
     *   le raggrupperebbe il ricalcolo, che rifà da zero le bozze. Un pagamento nuovo che fa
     *   superare la soglia anticipa quindi anche la scadenza di quelli precedenti.
     * - Ritenute in una delega **versata**: non compaiono, non c'è più niente da versare.
     *
     * Non scrive niente.
     *
     * @return array<int,array{scadenza: CarbonImmutable, codice_tributo: string, importo: int, motivo: string, delega_id: int|null}>
     */
    public function scadenzePerPagamento(Condominio $condominio): array
    {
        $esito = [];

        $confermate = DelegaF24::where('condominio_id', $condominio->id)
            ->where('stato', StatoDelegaF24::CONFERMATA->value)
            ->with('righe.pagamenti')
            ->get();

        foreach ($confermate as $delega) {
            foreach ($delega->righe as $riga) {
                foreach ($riga->pagamenti as $pagamento) {
                    $esito[$pagamento->id] = [
                        'scadenza' => CarbonImmutable::parse($delega->data_scadenza)->startOfDay(),
                        'codice_tributo' => (string) $riga->codice_tributo,
                        'importo' => (int) $pagamento->pivot->importo,
                        'motivo' => (string) $delega->motivo_codice,
                        'delega_id' => $delega->id,
                    ];
                }
            }
        }

        // Le bozze NON bloccano: il ricalcolo le cancella e le rifà, quindi le loro ritenute
        // vanno contate insieme a quelle ancora scoperte, come farà lui.
        $ritenute = $this->ritenuteNonCoperte($condominio, [
            StatoDelegaF24::STORNATA,
            StatoDelegaF24::ANNULLATA,
            StatoDelegaF24::BOZZA,
        ]);

        foreach ($this->plafond->raggruppaPerScadenza($ritenute->all()) as $gruppo) {
            foreach ($gruppo['ritenute'] as $ritenuta) {
                $pagamento = $ritenuta['pagamento'];
                $tipo = $ritenuta['tipo_ritenuta'] instanceof TipoRitenuta
                    ? $ritenuta['tipo_ritenuta']
                    : TipoRitenuta::from($ritenuta['tipo_ritenuta']);

                $esito[$pagamento->id] = [
                    'scadenza' => $gruppo['scadenza'],
                    'codice_tributo' => $tipo->codiceTributo($this->naturaPercipiente($pagamento)),
                    'importo' => (int) $ritenuta['importo'],
                    'motivo' => (string) $gruppo['motivo'],
                    'delega_id' => null,
                ];
            }
        }

        return $esito;
    }

    /**
     * Le ritenute operate che non stanno in una delega in uno stato diverso da quelli indicati.
     *
     * @param  array<int,StatoDelegaF24>  $statiCheNonBloccano
     * @return \Illuminate\Support\Collection<int,array<string,mixed>>
     */
    private function ritenuteNonCoperte(Condominio $condominio, array $statiCheNonBloccano): \Illuminate\Support\Collection
    {
        $giaVersati = DB::table('riga_f24_pagamento')
            ->join('righe_f24', 'righe_f24.id', '=', 'riga_f24_pagamento.riga_f24_id')
            ->join('deleghe_f24', 'deleghe_f24.id', '=', 'righe_f24.delega_f24_id')
            ->whereNotIn('deleghe_f24.stato', array_map(fn (StatoDelegaF24 $s) => $s->value, $statiCheNonBloccano))
            ->pluck('riga_f24_pagamento.pagamento_fornitore_id')
            ->all();

        return PagamentoFornitore::query()
            ->with('fornitore')
            ->where('condominio_id', $condominio->id)
            // Nessun filtro per esercizio: vedi il docblock di ritenuteDaVersare(). Resta il vincolo che il pagamento
            // abbia una scrittura, come prima (il filtro per esercizio lo imponeva di sponda).
            ->whereHas('scrittura')
            ->where('importo_ritenuta', '>', 0)
            ->whereNull('pagamento_padre_id')
            ->where('stato', '!=', StatoPagamentoFornitore::STORNATO->value)
            ->whereNotIn('id', $giaVersati)
            ->orderBy('data_pagamento')
            ->get()
            ->map(fn (PagamentoFornitore $p) => [
                'data_pagamento' => $p->data_pagamento,
                'importo' => (int) $p->importo_ritenuta,
                'tipo_ritenuta' => $this->tipoRitenuta($p),
                'pagamento' => $p,
            ]);
    }

    /**
     * Il regime di ritenuta di un pagamento, dall'anagrafica del fornitore.
     *
     * Il fallback su `APPALTO_4` non è una scorciatoia: è il regime della stragrande
     * maggioranza dei fornitori di un condominio, ed è quello che la 1.9 applicava
     * implicitamente con `perc_ritenuta = 4`. Le anagrafiche precedenti alla Fase 1 hanno
     * `tipo_ritenuta` vuoto, e rifiutarsi di generare la delega le renderebbe invisibili —
     * peggio che classificarle nel regime che quasi certamente è il loro. La percentuale
     * registrata, quando c'è, ha comunque la precedenza sul default.
     */
    public function tipoRitenuta(PagamentoFornitore $pagamento): TipoRitenuta
    {
        // Lo SCATTO al momento del pagamento ha la precedenza sull'anagrafica viva: il
        // regime che conta è quello applicato allora. Correggere oggi l'anagrafica di un
        // fornitore non deve riclassificare versamenti già fatti — un F24 presentato non
        // si riscrive. Disponibile dallo `schema_version` 2 dello snapshot.
        $snapshot = $pagamento->fornitore_snapshot ?? [];

        if (! empty($snapshot['tipo_ritenuta'])) {
            return TipoRitenuta::from($snapshot['tipo_ritenuta']);
        }

        if (isset($snapshot['perc_ritenuta'])) {
            return ((int) $snapshot['perc_ritenuta']) === 20
                ? TipoRitenuta::LAVORO_AUTONOMO_20
                : TipoRitenuta::APPALTO_4;
        }

        // Pagamenti registrati prima dello snapshot v2: si ricade sull'anagrafica.
        $fornitore = $pagamento->fornitore;

        if ($fornitore?->tipo_ritenuta) {
            return $fornitore->tipo_ritenuta instanceof TipoRitenuta
                ? $fornitore->tipo_ritenuta
                : TipoRitenuta::from($fornitore->tipo_ritenuta);
        }

        // Il default su APPALTO_4 non è una scorciatoia: è il regime della stragrande
        // maggioranza dei fornitori di un condominio, ed è quello che la 1.9 applicava
        // implicitamente con `perc_ritenuta = 4`. Escludere le anagrafiche non ancora
        // classificate le renderebbe invisibili al versamento — peggio che collocarle nel
        // regime che quasi certamente è il loro.
        return ((int) ($fornitore->perc_ritenuta ?? 4)) === 20
            ? TipoRitenuta::LAVORO_AUTONOMO_20
            : TipoRitenuta::APPALTO_4;
    }

    /**
     * Le righe di un gruppo, spezzate in blocchi da sei.
     *
     * Una riga è la combinazione codice tributo × mese × anno: due ritenute dello stesso
     * tributo operate nello stesso mese si sommano, di mesi diversi no. Oltre le sei righe
     * si emettono più modelli per la stessa scadenza, come l'Agenzia consente.
     *
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function spezzaInDeleghe(array $gruppo): array
    {
        $perRiga = [];

        foreach ($gruppo['ritenute'] as $ritenuta) {
            $pagamento = $ritenuta['pagamento'];
            $tipo = $ritenuta['tipo_ritenuta'] instanceof TipoRitenuta
                ? $ritenuta['tipo_ritenuta']
                : TipoRitenuta::from($ritenuta['tipo_ritenuta']);

            $natura = $this->naturaPercipiente($pagamento);
            $data = $pagamento->data_pagamento;

            $chiave = sprintf('%s-%02d-%d', $tipo->codiceTributo($natura), $data->month, $data->year);

            $perRiga[$chiave] ??= [
                'codice_tributo' => $tipo->codiceTributo($natura),
                'rateazione_mese_rif' => sprintf('00%02d', $data->month),
                'anno_riferimento' => (string) $data->year,
                'importo_debito' => 0,
                'pagamenti' => [],
            ];

            $perRiga[$chiave]['importo_debito'] += (int) $ritenuta['importo'];
            $perRiga[$chiave]['pagamenti'][] = ['id' => $pagamento->id, 'importo' => (int) $ritenuta['importo']];
        }

        ksort($perRiga);

        return array_chunk(array_values($perRiga), self::MAX_RIGHE_PER_DELEGA);
    }

    /** IRPEF o IRES: decide 1019 contro 1020, e non si deduce dal tipo di spesa. */
    private function naturaPercipiente(PagamentoFornitore $pagamento): NaturaPercipiente
    {
        return $this->naturaDichiarata($pagamento) ?? NaturaPercipiente::PERSONA_FISICA_IRPEF;
    }

    /**
     * La natura del percipiente come l'utente l'ha indicata, o `null` se non l'ha indicata.
     *
     * In ordine: lo scatto al momento del pagamento, l'anagrafica di oggi, e infine il codice
     * tributo «legacy» scritto a mano sull'anagrafica (1019/1020), che `RitenutaService` tratta
     * già come override esplicito. Correggere l'anagrafica e ricalcolare le scadenze aggiorna
     * quindi le **bozze**; le deleghe confermate o versate non vengono mai rigenerate.
     *
     * Quando non c'è niente, il generatore ripiega ancora su 1019 — la delega deve pur avere un
     * codice — ma `codiciDaVerificare()` lo segnala: per una società di capitali il codice giusto
     * è 1020, e un 1019 presentato al posto suo è un versamento imputato al tributo sbagliato.
     */
    public function naturaDichiarata(PagamentoFornitore $pagamento): ?NaturaPercipiente
    {
        $snapshot = $pagamento->fornitore_snapshot ?? [];

        if (! empty($snapshot['natura_percipiente'])) {
            return NaturaPercipiente::from($snapshot['natura_percipiente']);
        }

        $fornitore = $pagamento->fornitore;
        $valore = $fornitore?->natura_percipiente;

        if ($valore instanceof NaturaPercipiente) {
            return $valore;
        }

        if ($valore) {
            return NaturaPercipiente::from($valore);
        }

        return match ((string) ($fornitore?->codice_tributo ?? '')) {
            '1020' => NaturaPercipiente::SOGGETTO_IRES,
            '1019' => NaturaPercipiente::PERSONA_FISICA_IRPEF,
            default => null,
        };
    }

    /**
     * Le righe di una delega ancora da versare il cui codice 1019/1020 è stato **assunto** e non
     * deciso dall'utente, perché sull'anagrafica del fornitore manca la natura del percipiente.
     *
     * Non cambia niente: serve a dirlo dove la delega si guarda, prima di presentarla.
     *
     * @return array<int,array{fornitore_id: int|null, fornitore: string, codice_tributo: string, probabile_ires: bool}>
     */
    public function codiciDaVerificare(DelegaF24 $delega): array
    {
        if (! in_array($delega->stato, [StatoDelegaF24::BOZZA, StatoDelegaF24::CONFERMATA], true)) {
            return [];
        }

        $delega->loadMissing('righe.pagamenti.fornitore');
        $avvisi = [];

        foreach ($delega->righe as $riga) {
            // Solo gli appalti hanno l'alternativa 1019/1020: 1040 e 1001 non dipendono dalla natura.
            if (! in_array($riga->codice_tributo, ['1019', '1020'], true)) {
                continue;
            }

            foreach ($riga->pagamenti as $pagamento) {
                if ($this->naturaDichiarata($pagamento) !== null) {
                    continue;
                }

                $nome = $pagamento->fornitore_snapshot['ragione_sociale']
                    ?? $pagamento->fornitore?->ragione_sociale
                    ?? "Fornitore #{$pagamento->fornitore_id}";

                $avvisi[$pagamento->fornitore_id ?? $nome] = [
                    'fornitore_id' => $pagamento->fornitore_id,
                    'fornitore' => $nome,
                    'codice_tributo' => $riga->codice_tributo,
                    // Solo un indizio per il messaggio, mai usato per scegliere il codice.
                    'probabile_ires' => self::sembraSocietaDiCapitali($nome),
                ];
            }
        }

        return array_values($avvisi);
    }

    /** La ragione sociale porta la forma di una società di capitali (S.r.l., S.p.A., ...)? */
    public static function sembraSocietaDiCapitali(?string $ragioneSociale): bool
    {
        $testo = strtolower(preg_replace('/[^a-z]/i', '', (string) $ragioneSociale));

        foreach (['srl', 'srls', 'spa', 'sapa', 'scarl', 'scrl', 'socoop', 'societacooperativa'] as $forma) {
            if (str_ends_with($testo, $forma)) {
                return true;
            }
        }

        return false;
    }

    private function creaDelega(Condominio $condominio, int $esercizioId, array $gruppo, array $righe): DelegaF24
    {
        $totale = array_sum(array_column($righe, 'importo_debito'));

        $delega = DelegaF24::create([
            'uuid' => (string) Str::uuid(),
            'condominio_id' => $condominio->id,
            'esercizio_id' => $esercizioId,
            'stato' => StatoDelegaF24::BOZZA,
            'plafond' => $gruppo['plafond'],
            'data_scadenza' => $gruppo['scadenza']->toDateString(),
            'cf_contribuente' => $condominio->codice_fiscale,
            'denominazione_contribuente' => $condominio->nome,
            'totale_debito' => $totale,
            'saldo' => $totale,
            'motivo_codice' => $gruppo['motivo'],
        ]);

        foreach ($righe as $i => $riga) {
            $rigaF24 = RigaF24::create([
                'delega_f24_id' => $delega->id,
                'ordine' => $i + 1,
                'sezione' => 'erario',
                'codice_tributo' => $riga['codice_tributo'],
                'rateazione_mese_rif' => $riga['rateazione_mese_rif'],
                'anno_riferimento' => $riga['anno_riferimento'],
                'importo_debito' => $riga['importo_debito'],
            ]);

            foreach ($riga['pagamenti'] as $p) {
                $rigaF24->pagamenti()->attach($p['id'], ['importo' => $p['importo']]);
            }
        }

        return $delega->load('righe');
    }
}
