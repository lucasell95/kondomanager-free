<?php

namespace App\Actions\Gestionale\Chiusura;

use App\Exceptions\Gestionale\ChiusuraEsercizioException;
use App\Models\Gestionale\ChiusuraEsercizio;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\RigaScrittura;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Gestione;
use App\Models\Saldo;
use App\Services\Gestionale\SaldoEsercizioService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Annulla una chiusura d'esercizio appena eseguita: riapre il vecchio esercizio ed elimina il
 * nuovo con tutto ciò che la chiusura aveva generato.
 *
 * ## Quando si può
 *
 * Solo finché nel nuovo esercizio **non è successo niente**: nessuna scrittura oltre a quelle di
 * riporto, nessuna fattura, nessuna delega F24, nessun saldo iniziale inserito a mano o già
 * assorbito da un piano, nessun piano rate nella nuova ordinaria. `motiviBlocco()` elenca che
 * cosa lo impedisce, e la pagina lo mostra.
 *
 * ## Perché qui si cancellano scritture
 *
 * Il giornale non si riscrive, e le scritture registrate non si cancellano. Le scritture di
 * riporto sono l'eccezione dichiarata: le ha generate la chiusura stessa, stanno in un esercizio
 * che sta per essere eliminato e che non contiene nient'altro. Stornarle lascerebbe un esercizio
 * vuoto con due scritture che si annullano e che nessuno ha chiesto, e un esercizio con
 * scritture non si elimina (`EsercizioController::destroy`). La traccia resta nella riga di
 * `chiusure_esercizi`, marcata `annullata`, con chi e quando.
 */
class AnnullaChiusuraEsercizioAction
{
    public function __construct(private SaldoEsercizioService $saldoService) {}

    /** @return list<string> motivi per cui l'annullamento non si può fare (vuoto = si può) */
    public function motiviBlocco(ChiusuraEsercizio $chiusura): array
    {
        if (! $chiusura->eEseguita()) {
            return ['La chiusura è già stata annullata.'];
        }

        $nuovo = $chiusura->esercizioNuovo;
        if (! $nuovo) {
            return ['Il nuovo esercizio non esiste più.'];
        }

        $motivi = [];
        $riepilogo = $chiusura->riepilogo ?? [];
        $nostre = $riepilogo['scritture'] ?? [];

        if ($nuovo->stato !== 'aperto') {
            $motivi[] = "Il nuovo esercizio «{$nuovo->nome}» è già chiuso.";
        }

        $successiva = ChiusuraEsercizio::where('esercizio_chiuso_id', $nuovo->id)
            ->where('stato', ChiusuraEsercizio::STATO_ESEGUITA)->exists();
        if ($successiva) {
            $motivi[] = "Anche «{$nuovo->nome}» è stato chiuso: annulla prima quella chiusura.";
        }

        $altre = ScritturaContabile::withTrashed()
            ->where('esercizio_id', $nuovo->id)
            ->whereNotIn('id', $nostre ?: [0])
            ->count();
        if ($altre > 0) {
            $motivi[] = "Nel nuovo esercizio ci sono già {$altre} movimenti registrati (incassi, emissioni, pagamenti…).";
        }

        $fatture = DB::table('fatture_passive')->where('esercizio_id', $nuovo->id)->count();
        if ($fatture > 0) {
            $motivi[] = "Nel nuovo esercizio sono registrate {$fatture} fatture.";
        }

        if (DB::table('deleghe_f24')->where('esercizio_id', $nuovo->id)->exists()) {
            $motivi[] = 'Nel nuovo esercizio ci sono deleghe F24.';
        }

        $manuali = Saldo::where('esercizio_id', $nuovo->id)
            ->where(fn ($q) => $q->whereNull('chiusura_esercizio_id')->orWhere('chiusura_esercizio_id', '!=', $chiusura->id))
            ->count();
        if ($manuali > 0) {
            $motivi[] = "Nel nuovo esercizio ci sono {$manuali} saldi iniziali inseriti a mano.";
        }

        $assorbiti = Saldo::where('chiusura_esercizio_id', $chiusura->id)->whereNotNull('piano_rate_id')->count();
        if ($assorbiti > 0) {
            $motivi[] = "{$assorbiti} saldi riportati sono già stati assorbiti da un piano rate: elimina prima il piano.";
        }

        $piani = DB::table('piani_rate')->whereIn('gestione_id', $riepilogo['gestioni_create'] ?? [0])->count();
        if ($piani > 0) {
            $motivi[] = "Nella nuova gestione ordinaria ci sono {$piani} piani rate.";
        }

        return $motivi;
    }

    public function execute(ChiusuraEsercizio $chiusura, ?int $utenteId = null): void
    {
        DB::transaction(function () use ($chiusura, $utenteId) {
            $chiusura = ChiusuraEsercizio::whereKey($chiusura->id)->lockForUpdate()->firstOrFail();

            $motivi = $this->motiviBlocco($chiusura);
            if ($motivi) {
                throw new ChiusuraEsercizioException('La chiusura non si può più annullare: '.implode(' ', $motivi), $motivi);
            }

            $riepilogo = $chiusura->riepilogo ?? [];
            $nuovo = $chiusura->esercizioNuovo;
            $vecchio = $chiusura->esercizioChiuso;
            $scritture = $riepilogo['scritture'] ?? [];
            $toccate = [];

            // 1. Le quote riportate tornano aperte (il loro stato si ricalcola dalla pivot).
            $quoteIds = DB::table('quota_scrittura')->whereIn('scrittura_contabile_id', $scritture ?: [0])->pluck('rate_quota_id')->unique();
            DB::table('quota_scrittura')->whereIn('scrittura_contabile_id', $scritture ?: [0])->delete();

            $riferimenti = collect($riepilogo['quote_riportate'] ?? [])->keyBy('quota_id');
            foreach (RataQuote::whereIn('id', $quoteIds)->get() as $quota) {
                $quota->ricalcolaStato();
                $quota->update(['riferimento_pagamento' => $riferimenti[$quota->id]['riferimento_prima'] ?? null]);
            }

            // 2. Le scritture di riporto (vedi la nota in testa alla classe).
            RigaScrittura::whereIn('scrittura_id', $scritture ?: [0])->delete();
            ScritturaContabile::withTrashed()->whereIn('id', $scritture ?: [0])->forceDelete();

            // 3. I saldi generati, e quelli del vecchio esercizio che erano stati chiusi.
            foreach (Saldo::where('chiusura_esercizio_id', $chiusura->id)->where('esercizio_id', $nuovo->id)->get() as $saldo) {
                $toccate[$saldo->gestione_id] = true;
                $saldo->delete();
            }
            $bloccati = $riepilogo['saldi_bloccati'] ?? [];
            if ($bloccati) {
                Saldo::whereIn('id', $bloccati)->whereNull('piano_rate_id')
                    ->update(['is_applicato' => false, 'chiusura_esercizio_id' => null]);
                foreach (Saldo::whereIn('id', $bloccati)->pluck('gestione_id') as $g) {
                    $toccate[$g] = true;
                }
            }

            // 4. Le gestioni: staccate le straordinarie, eliminate quelle nate qui, riaccese le spente.
            if ($riepilogo['gestioni_agganciate'] ?? []) {
                $nuovo->gestioni()->detach($riepilogo['gestioni_agganciate']);
            }
            foreach ($riepilogo['gestioni_create'] ?? [] as $id) {
                PianoConto::where('gestione_id', $id)->get()->each->delete();
                Gestione::whereKey($id)->first()?->delete();
                unset($toccate[$id]);
            }
            foreach (array_keys($riepilogo['gestioni_disattivate'] ?? []) as $id) {
                Gestione::whereKey($id)->update(['attiva' => true]);
            }

            // 5. Il nuovo esercizio sparisce, il vecchio si riapre.
            $nuovo->gestioni()->detach();
            $nuovo->delete();
            $vecchio->update(['stato' => 'aperto']);

            $chiusura->update([
                'stato'        => ChiusuraEsercizio::STATO_ANNULLATA,
                'annullata_da' => $utenteId,
                'annullata_il' => now(),
            ]);

            foreach (array_keys($toccate) as $id) {
                if ($g = Gestione::find($id)) {
                    $this->saldoService->allineaFlagGestione($g);
                }
            }

            Log::info('Chiusura esercizio annullata', [
                'chiusura_id' => $chiusura->id, 'esercizio_riaperto' => $vecchio->id, 'user_id' => $utenteId,
            ]);
        });
    }
}
