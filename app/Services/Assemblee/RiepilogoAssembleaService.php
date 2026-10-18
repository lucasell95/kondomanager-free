<?php

namespace App\Services\Assemblee;

use App\Enums\Assemblee\Convocazione;
use App\Enums\Assemblee\EspressioneVoto;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\PuntoOdg;
use App\Support\Assemblee\Millesimi;

/**
 * Mette insieme tutto ciò che la scheda dell'assemblea e il verbale mostrano: quorum costitutivo,
 * teste ed esito di ogni punto, stato delle convocazioni, assenti e termini di impugnazione.
 *
 * Una sola fonte per la pagina e per il PDF: il verbale stampato non può dire una cosa diversa da
 * quella che l'amministratore ha visto a video.
 */
class RiepilogoAssembleaService
{
    public function __construct(
        private TesteAssembleaService $teste,
        private QuorumService $quorum,
        private ControlliConvocazioneService $controlli,
        private ImpugnazioneService $impugnazione,
        private FotografiaUnitaService $fotografia,
    ) {}

    public function decimali(Assemblea $assemblea): int
    {
        return (int) ($this->fotografia->tabellaDi($assemblea)?->numero_decimali ?? 3);
    }

    public function costitutivo(Assemblea $assemblea, ?Convocazione $convocazione = null): array
    {
        ['teste' => $teste, 'totale' => $totale] = $this->teste->testeApertura($assemblea);
        $esito = $this->quorum->costitutivo($teste, $totale, $convocazione ?? $assemblea->convocazioneEffettiva(), $this->decimali($assemblea));
        $esito['teste'] = array_map(fn (TestaAssemblea $t) => $t->toArray() + [
            'valore_testo' => Millesimi::formatta($t->valore, $this->decimali($assemblea)),
        ], $teste);

        return $esito;
    }

    /** @param list<array{chiave_testa:string,presente:bool,voto:?string,conflitto:bool}>|null $sovrascrivi */
    public function punto(Assemblea $assemblea, PuntoOdg $punto, ?array $sovrascrivi = null): array
    {
        ['teste' => $teste, 'totale' => $totale] = $this->teste->testePunto($assemblea, $punto, $sovrascrivi);
        $decimali = $this->decimali($assemblea);
        $esito = $this->quorum->deliberativo($teste, $totale, $punto->materia, $assemblea->convocazioneEffettiva(), $decimali);

        return [
            'teste' => array_map(fn (TestaAssemblea $t) => $t->toArray() + [
                'valore_testo' => Millesimi::formatta($t->valore, $decimali),
            ], $teste),
            'totale' => $totale,
            'totale_testo' => Millesimi::formatta($totale, $decimali),
            'esito' => $esito,
        ];
    }

    /**
     * Le persone assenti all'apertura, con la comunicazione del verbale e il termine di impugnazione.
     */
    public function assenti(Assemblea $assemblea): array
    {
        $presenze = $this->teste->presenze($assemblea);
        $destinatari = $assemblea->destinatari()->get()->groupBy('anagrafica_id');

        $risultato = [];
        foreach ($this->teste->persone($assemblea) as $persona) {
            $p = $presenze->get($persona['anagrafica_id']);
            if ($p && $p->modo->intervenuto()) {
                continue;
            }

            $dest = $destinatari->get($persona['anagrafica_id'])?->first();
            $ricevuto = $dest?->verbale_data_ricezione;

            $risultato[] = $persona + [
                'destinatario_id' => $dest?->id,
                'verbale_mezzo' => $dest?->verbale_mezzo?->value,
                'verbale_data_invio' => $dest?->verbale_data_invio?->toDateString(),
                'verbale_data_ricezione' => $ricevuto?->toDateString(),
                'verbale_riferimento' => $dest?->verbale_riferimento,
                'scadenza_impugnazione' => $ricevuto ? $this->impugnazione->scadenza($ricevuto)->toDateString() : null,
            ];
        }

        return $risultato;
    }

    /** Termine per dissenzienti e astenuti: dalla data della delibera. */
    public function scadenzaPresenti(Assemblea $assemblea): ?string
    {
        $data = $assemblea->dataSvolgimento();

        return $data ? $this->impugnazione->scadenza($data)->toDateString() : null;
    }

    public function destinatari(Assemblea $assemblea): array
    {
        return $assemblea->destinatari()->get()->map(fn ($d) => [
            'id' => $d->id,
            'anagrafica_id' => $d->anagrafica_id,
            'nominativo' => $d->nominativo,
            'ruolo' => $d->ruolo,
            'mezzo' => $d->mezzo?->value,
            'recapito' => $d->recapito,
            'data_invio' => $d->data_invio?->toDateString(),
            'data_ricezione' => $d->data_ricezione?->toDateString(),
            'riferimento' => $d->riferimento,
            'note' => $d->note,
            'stato' => $this->controlli->statoRicezione($d, $assemblea->prima_convocazione_at),
        ])->values()->all();
    }

    /** Tutto il necessario per il verbale. */
    public function perVerbale(Assemblea $assemblea): array
    {
        $assemblea->loadMissing(['punti', 'dichiarazioni', 'condominio']);
        $costitutivo = $this->costitutivo($assemblea);

        $punti = $assemblea->punti->map(function (PuntoOdg $p) use ($assemblea) {
            $dati = $this->punto($assemblea, $p);

            return [
                'punto' => $p,
                'esito' => $dati['esito'],
                'teste' => $dati['teste'],
                'dichiarazioni' => $assemblea->dichiarazioni->where('assemblea_punto_id', $p->id)->values(),
            ];
        });

        $presenze = $this->teste->presenze($assemblea);
        $persone = collect($this->teste->persone($assemblea))->map(function ($persona) use ($presenze) {
            $p = $presenze->get($persona['anagrafica_id']);

            return $persona + [
                'modo' => $p?->modo?->value ?? 'assente',
                'delegato' => $p?->nomeDelegato(),
                'ora_ingresso' => $p?->ora_ingresso,
                'ora_uscita' => $p?->ora_uscita,
            ];
        });

        return [
            'costitutivo' => $costitutivo,
            'punti' => $punti,
            'persone' => $persone,
            'dichiarazioni_generali' => $assemblea->dichiarazioni->whereNull('assemblea_punto_id')->values(),
            'destinatari' => $this->destinatari($assemblea),
            'decimali' => $this->decimali($assemblea),
        ];
    }

    /** Il riassunto testuale dei voti di un punto, come va nel verbale. */
    public static function sintesiVoti(array $esito): array
    {
        $righe = [];
        foreach ([
            EspressioneVoto::FAVOREVOLE->value => 'favorevoli',
            EspressioneVoto::CONTRARIO->value => 'contrari',
            EspressioneVoto::ASTENUTO->value => 'astenuti',
        ] as $voto => $chiave) {
            $c = $esito[$chiave] ?? null;
            if (! $c) {
                continue;
            }
            $righe[$voto] = $c;
        }

        return $righe;
    }
}
