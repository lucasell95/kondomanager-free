<?php

namespace App\Services\Gestionale\Banca;

use App\Models\Gestionale\Banca\MovimentoBancario;
use App\Models\Gestionale\Banca\SaldoEstrattoConto;
use App\Models\Gestionale\Cassa;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Riconciliazione di un conto in un periodo: il saldo della banca contro il saldo contabile, e
 * l'elenco di ciò che spiega la differenza.
 *
 * ## L'identità su cui si regge
 *
 *     (saldo banca − saldo contabile) a fine periodo
 *   = (saldo banca − saldo contabile) a inizio periodo
 *   + Σ movimenti della banca del periodo non abbinati a una scrittura del periodo
 *   − Σ scritture del periodo sul conto non abbinate a un movimento del periodo
 *   + Σ (importo banca − importo a giornale) delle coppie abbinate con importi diversi
 *
 * Se resta un **residuo non spiegato**, mancano movimenti nell'import (un giorno non esportato) o
 * uno dei saldi dichiarati è sbagliato: è la cosa da guardare per prima.
 *
 * ## Il saldo contabile
 *
 * Dal giornale per data di **competenza** (la data dell'operazione, che è quella confrontabile con
 * la data contabile della banca), sul conto contabile della cassa, più il saldo iniziale della
 * cassa non ancora portato a giornale — la stessa somma di `SaldoCassaService`, solo fermata a
 * una data. Le scritture annullate restano: lo storno vive nella sua rettifica, e insieme fanno
 * zero, come in `SaldoCassaService`.
 *
 * Con `includi_fondi` si aggiungono i fondi, che nel gestionale sono partizioni contabili dello
 * stesso conto corrente: il saldo che la banca vede è banca + fondi, e i giroconti fra loro si
 * annullano.
 */
final class RiconciliazioneBancariaService
{
    /**
     * @return array<string, mixed>
     */
    public function calcola(
        Cassa $cassa,
        CarbonImmutable $da,
        CarbonImmutable $a,
        ?int $saldoBancaIniziale,
        ?int $saldoBancaFinale,
        bool $includiFondi = false,
    ): array {
        $conti = $this->contiDelConto($cassa, $includiFondi);
        $saldoIniziale = $saldoBancaIniziale ?? $this->saldoDichiarato($cassa, $da->subDay());
        $saldoFinale = $saldoBancaFinale ?? $this->saldoDichiarato($cassa, $a);

        $contabileIniziale = $this->saldoContabile($cassa, $conti, $includiFondi, $da->subDay());
        $contabileFinale = $this->saldoContabile($cassa, $conti, $includiFondi, $a);

        $movimenti = MovimentoBancario::where('cassa_id', $cassa->id)
            ->whereDate('data_contabile', '>=', $da->toDateString())
            ->whereDate('data_contabile', '<=', $a->toDateString())
            ->orderBy('data_contabile')
            ->orderBy('id')
            ->get();

        $scritture = $this->scrittureDelPeriodo($conti, $cassa->condominio_id, $da, $a);
        $scritturePerId = $scritture->keyBy('id');

        $differenze = [];

        // Banca senza giornale nel periodo: non abbinati, ignorati, o abbinati a una scrittura
        // con data fuori dal periodo.
        foreach ($movimenti as $m) {
            if ($m->scrittura_contabile_id && $scritturePerId->has($m->scrittura_contabile_id)) {
                continue;
            }

            $differenze[] = [
                'tipo' => match (true) {
                    $m->stato === MovimentoBancario::STATO_IGNORATO => 'ignorato',
                    $m->scrittura_contabile_id !== null => 'scrittura_fuori_periodo',
                    default => 'solo_banca',
                },
                'data' => $m->data_contabile->toDateString(),
                'descrizione' => $m->descrizione,
                'movimento_id' => $m->id,
                'scrittura_id' => $m->scrittura_contabile_id,
                'protocollo' => null,
                'importo_banca' => $m->importo,
                'importo_contabile' => 0,
                'effetto' => $m->importo,
            ];
        }

        // Giornale del periodo: senza movimento della banca, oppure con importi diversi. Una
        // scrittura collegata a più movimenti (bonifico e commissioni registrati insieme) si
        // confronta con la loro somma.
        $collegatePeriodo = $movimenti->whereNotNull('scrittura_contabile_id')->groupBy('scrittura_contabile_id');

        foreach ($scritture as $s) {
            $coperta = $collegatePeriodo->get($s->id);

            if ($coperta) {
                $banca = (int) $coperta->sum('importo');

                if ($banca !== $s->netto) {
                    $differenze[] = [
                        'tipo' => 'importo_diverso',
                        'data' => $s->data,
                        'descrizione' => $s->causale,
                        'movimento_id' => $coperta->first()->id,
                        'scrittura_id' => $s->id,
                        'protocollo' => $s->numero_protocollo,
                        'importo_banca' => $banca,
                        'importo_contabile' => $s->netto,
                        'effetto' => $banca - $s->netto,
                    ];
                }

                continue;
            }

            $differenze[] = [
                'tipo' => MovimentoBancario::where('scrittura_contabile_id', $s->id)->exists() ? 'movimento_fuori_periodo' : 'solo_giornale',
                'data' => $s->data,
                'descrizione' => $s->causale,
                'movimento_id' => null,
                'scrittura_id' => $s->id,
                'protocollo' => $s->numero_protocollo,
                'importo_banca' => 0,
                'importo_contabile' => $s->netto,
                'effetto' => -$s->netto,
            ];
        }

        usort($differenze, fn ($x, $y) => [$x['data'], $x['scrittura_id'] ?? 0] <=> [$y['data'], $y['scrittura_id'] ?? 0]);

        $diffIniziale = $saldoIniziale !== null ? $saldoIniziale - $contabileIniziale : null;
        $diffFinale = $saldoFinale !== null ? $saldoFinale - $contabileFinale : null;
        $spiegata = array_sum(array_column($differenze, 'effetto'));
        $sommaBanca = (int) $movimenti->sum('importo');

        return [
            'periodo' => ['da' => $da->toDateString(), 'a' => $a->toDateString()],
            'includi_fondi' => $includiFondi,
            'saldo_banca_iniziale' => $saldoIniziale,
            'saldo_banca_finale' => $saldoFinale,
            // Saldo iniziale + movimenti importati: se diverge dal finale dichiarato, l'import è incompleto.
            'saldo_banca_calcolato' => $saldoIniziale !== null ? $saldoIniziale + $sommaBanca : null,
            'saldo_contabile_iniziale' => $contabileIniziale,
            'saldo_contabile_finale' => $contabileFinale,
            'differenza_iniziale' => $diffIniziale,
            'differenza_finale' => $diffFinale,
            'differenza_spiegata' => $spiegata,
            'residuo_non_spiegato' => ($diffIniziale !== null && $diffFinale !== null) ? $diffFinale - $diffIniziale - $spiegata : null,
            'movimenti_banca' => [
                'totale' => $movimenti->count(),
                'abbinati' => $movimenti->where('stato', MovimentoBancario::STATO_ABBINATO)->count(),
                'da_abbinare' => $movimenti->where('stato', MovimentoBancario::STATO_DA_ABBINARE)->count(),
                'ignorati' => $movimenti->where('stato', MovimentoBancario::STATO_IGNORATO)->count(),
                'somma' => $sommaBanca,
            ],
            'differenze' => $differenze,
        ];
    }

    /** Il saldo della banca a fine giornata, se dichiarato da un import o inserito a mano. */
    public function saldoDichiarato(Cassa $cassa, CarbonImmutable $data): ?int
    {
        $saldo = SaldoEstrattoConto::where('cassa_id', $cassa->id)
            ->whereDate('data', $data->toDateString())
            ->value('saldo');

        return $saldo !== null ? (int) $saldo : null;
    }

    /** @return list<int> i conti contabili che compongono il conto corrente */
    private function contiDelConto(Cassa $cassa, bool $includiFondi): array
    {
        $conti = [(int) $cassa->conto_contabile_id];

        if ($includiFondi) {
            $conti = array_merge($conti, Cassa::where('condominio_id', $cassa->condominio_id)
                ->where('tipo', 'fondo')
                ->whereNotNull('conto_contabile_id')
                ->pluck('conto_contabile_id')
                ->map(fn ($id) => (int) $id)
                ->all());
        }

        return array_values(array_unique(array_filter($conti)));
    }

    private function saldoContabile(Cassa $cassa, array $conti, bool $includiFondi, CarbonImmutable $al): int
    {
        // Il saldo iniziale delle casse non ancora portato a giornale (`RegistraAperturaCassaAction`
        // lo azzera quando lo registra): stessa regola di SaldoCassaService.
        $iniziale = (int) Cassa::where('condominio_id', $cassa->condominio_id)
            ->whereIn('conto_contabile_id', $conti)
            ->when(! $includiFondi, fn ($q) => $q->whereKey($cassa->id))
            ->sum('saldo_iniziale');

        $r = DB::table('righe_scritture')
            ->join('scritture_contabili', 'righe_scritture.scrittura_id', '=', 'scritture_contabili.id')
            ->where('scritture_contabili.condominio_id', $cassa->condominio_id)
            ->whereIn('righe_scritture.conto_contabile_id', $conti)
            ->whereNull('scritture_contabili.deleted_at')
            ->whereDate('scritture_contabili.data_competenza', '<=', $al->toDateString())
            ->selectRaw("SUM(CASE WHEN righe_scritture.tipo_riga = 'dare' THEN righe_scritture.importo ELSE 0 END) as dare")
            ->selectRaw("SUM(CASE WHEN righe_scritture.tipo_riga = 'avere' THEN righe_scritture.importo ELSE 0 END) as avere")
            ->first();

        return $iniziale + (int) ($r->dare ?? 0) - (int) ($r->avere ?? 0);
    }

    /**
     * Le scritture del periodo con il loro effetto netto sui conti; quelle a effetto zero
     * (giroconti fra banca e fondi, quando i fondi sono inclusi) non c'entrano con la banca.
     *
     * @return Collection<int, object>
     */
    private function scrittureDelPeriodo(array $conti, int $condominioId, CarbonImmutable $da, CarbonImmutable $a): Collection
    {
        $nettoSql = "SUM(CASE WHEN righe_scritture.tipo_riga = 'dare' THEN righe_scritture.importo ELSE -righe_scritture.importo END)";

        return DB::table('scritture_contabili')
            ->join('righe_scritture', 'righe_scritture.scrittura_id', '=', 'scritture_contabili.id')
            ->where('scritture_contabili.condominio_id', $condominioId)
            ->whereNull('scritture_contabili.deleted_at')
            ->whereIn('righe_scritture.conto_contabile_id', $conti)
            ->whereDate('scritture_contabili.data_competenza', '>=', $da->toDateString())
            ->whereDate('scritture_contabili.data_competenza', '<=', $a->toDateString())
            ->groupBy('scritture_contabili.id', 'scritture_contabili.numero_protocollo', 'scritture_contabili.causale', 'scritture_contabili.data_competenza')
            ->select('scritture_contabili.id', 'scritture_contabili.numero_protocollo', 'scritture_contabili.causale', 'scritture_contabili.data_competenza')
            ->selectRaw("{$nettoSql} as netto")
            ->orderBy('scritture_contabili.data_competenza')
            ->get()
            ->map(function ($s) {
                $s->netto = (int) $s->netto;
                $s->data = substr((string) $s->data_competenza, 0, 10);

                return $s;
            })
            ->filter(fn ($s) => $s->netto !== 0)
            ->values();
    }
}
