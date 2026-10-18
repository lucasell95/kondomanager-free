<?php

namespace App\Services\Gestionale\Banca;

use App\Models\Gestionale\Banca\MovimentoBancario;
use App\Models\Gestionale\Cassa;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Le scritture già a giornale che potrebbero essere **lo stesso** movimento della banca.
 *
 * È il secondo controllo anti-duplicati, quello che l'hash non può fare: l'incasso registrato a
 * mano dalla maschera incassi il giorno in cui è arrivato il bonifico non ha nessun hash, ma è
 * già in contabilità. Importarlo di nuovo e abbinarlo a un incasso nuovo lo raddoppierebbe.
 *
 * Il criterio: una scrittura non annullata del condominio che sul conto contabile della cassa
 * muove **esattamente** lo stesso importo con lo stesso segno (DARE − AVERE = importo della banca),
 * con data di competenza entro qualche giorno, e non ancora collegata a un altro movimento
 * bancario. La finestra di giorni copre la differenza fra data dell'operazione registrata e data
 * contabile della banca (bonifico disposto venerdì, contabilizzato lunedì).
 */
final class ScrittureGiaRegistrate
{
    public const GIORNI_DI_TOLLERANZA = 5;

    /**
     * @param  list<int>  $escludiScritture  già proposte ad altre righe dello stesso import
     * @return Collection<int, object{id:int, numero_protocollo:?string, causale:?string, data_competenza:string, tipo_movimento:string, netto:int}>
     */
    public function candidate(
        Cassa $cassa,
        CarbonInterface $data,
        int $importo,
        array $escludiScritture = [],
        int $giorni = self::GIORNI_DI_TOLLERANZA,
    ): Collection {
        if (! $cassa->conto_contabile_id) {
            return collect();
        }

        $collegate = MovimentoBancario::where('cassa_id', $cassa->id)
            ->whereNotNull('scrittura_contabile_id')
            ->pluck('scrittura_contabile_id')
            ->all();

        $nettoSql = "SUM(CASE WHEN righe_scritture.tipo_riga = 'dare' THEN righe_scritture.importo ELSE -righe_scritture.importo END)";

        return DB::table('scritture_contabili')
            ->join('righe_scritture', 'righe_scritture.scrittura_id', '=', 'scritture_contabili.id')
            ->where('scritture_contabili.condominio_id', $cassa->condominio_id)
            ->whereNull('scritture_contabili.deleted_at')
            ->where('scritture_contabili.stato', '!=', 'annullata')
            ->where('righe_scritture.conto_contabile_id', $cassa->conto_contabile_id)
            ->whereDate('scritture_contabili.data_competenza', '>=', $data->copy()->subDays($giorni)->toDateString())
            ->whereDate('scritture_contabili.data_competenza', '<=', $data->copy()->addDays($giorni)->toDateString())
            ->whereNotIn('scritture_contabili.id', array_merge($collegate, $escludiScritture))
            ->groupBy(
                'scritture_contabili.id',
                'scritture_contabili.numero_protocollo',
                'scritture_contabili.causale',
                'scritture_contabili.data_competenza',
                'scritture_contabili.tipo_movimento'
            )
            ->havingRaw("{$nettoSql} = ?", [$importo])
            ->select(
                'scritture_contabili.id',
                'scritture_contabili.numero_protocollo',
                'scritture_contabili.causale',
                'scritture_contabili.data_competenza',
                'scritture_contabili.tipo_movimento',
            )
            ->selectRaw("{$nettoSql} as netto")
            ->get()
            ->map(function ($s) use ($data) {
                $s->data_competenza = substr((string) $s->data_competenza, 0, 10);
                $s->netto = (int) $s->netto;
                $s->distanza = (int) abs(round(\Carbon\Carbon::parse($s->data_competenza)->diffInDays($data, false)));

                return $s;
            })
            ->sortBy([['distanza', 'asc'], ['id', 'asc']])
            ->values();
    }

    /**
     * Quanto una scrittura muove sul conto della cassa (DARE − AVERE), in centesimi.
     */
    public function nettoSullaCassa(int $scritturaId, Cassa $cassa): int
    {
        $r = DB::table('righe_scritture')
            ->where('scrittura_id', $scritturaId)
            ->where('conto_contabile_id', $cassa->conto_contabile_id)
            ->selectRaw("SUM(CASE WHEN tipo_riga = 'dare' THEN importo ELSE 0 END) as dare")
            ->selectRaw("SUM(CASE WHEN tipo_riga = 'avere' THEN importo ELSE 0 END) as avere")
            ->first();

        return (int) ($r->dare ?? 0) - (int) ($r->avere ?? 0);
    }
}
