<?php

namespace App\Services\Gestionale;

use App\Helpers\MoneyHelper;
use Illuminate\Support\Collection;

/**
 * # Estratto conto: una riga per movimento invece che una per unità immobiliare
 *
 * La timeline di `EstrattoContoAnagraficaController::buildLedger()` ha una riga per ogni **riga
 * contabile**, e le righe contabili sono per unità immobiliare: l'emissione di una rata a chi ha
 * appartamento, cantina e garage sono tre righe; un incasso ripartito su più quote ne fa sette.
 * Per il condòmino è un movimento solo.
 *
 * Questo service ricompone le righe in movimenti:
 *
 * - **addebiti** (dare): una riga per scrittura + rata. «Emissione rata n.1» è una riga con la
 *   somma di tutte le unità, lo stato della rata riassunto, il recupero di debito pregresso
 *   sommato e l'elenco delle unità coinvolte;
 * - **pagamenti** (avere): una riga per scrittura. Un incasso è una riga col totale incassato,
 *   anche quando copre più rate: le rate coperte restano elencate nel dettaglio.
 *
 * ## Cosa non cambia, per costruzione
 *
 * Si somma e basta: ogni riga espansa finisce in **un** gruppo, quindi totale addebiti, totale
 * versato e saldo finale sono identici fra le due viste. Il saldo progressivo viene ricalcolato
 * sui gruppi, partendo dallo stesso saldo iniziale, nell'ordine in cui il primo elemento di
 * ciascun gruppo compare nella timeline espansa.
 *
 * Lavora sulla timeline già costruita (array), senza toccare il database: è la stessa per la
 * pagina e per la stampa, e si prova senza scenario contabile.
 */
class EstrattoContoAggregatoService
{
    public const VISTA_AGGREGATA = 'aggregata';
    public const VISTA_UNITA = 'unita';

    /** La vista richiesta, con ripiego sull'aggregata per qualunque valore sconosciuto. */
    public static function vistaValida(?string $vista): string
    {
        return $vista === self::VISTA_UNITA ? self::VISTA_UNITA : self::VISTA_AGGREGATA;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $timeline  La timeline espansa (una riga per unità).
     * @param  int  $saldoInizialeCents  Lo stesso saldo iniziale da cui parte la timeline espansa.
     * @return Collection<int, array<string, mixed>>
     */
    public function aggrega(Collection $timeline, int $saldoInizialeCents): Collection
    {
        // Gruppi nell'ordine della prima apparizione: l'ordine della timeline espansa è già quello
        // voluto (scrittura, poi dare prima di avere), e va rispettato.
        $gruppi = [];
        foreach ($timeline as $riga) {
            $gruppi[$this->chiave($riga)][] = $riga;
        }

        $saldo = $saldoInizialeCents;
        $aggregata = [];

        foreach ($gruppi as $chiave => $righe) {
            $dare = array_sum(array_column($righe, 'dare'));
            $avere = array_sum(array_column($righe, 'avere'));

            $inizio = $saldo;
            $saldo += $dare - $avere;

            $aggregata[] = $this->riga((string) $chiave, $righe, $dare, $avere, $inizio, $saldo);
        }

        return collect($aggregata);
    }

    /**
     * Scrittura + verso; per gli addebiti anche la rata.
     *
     * @param  array<string, mixed>  $riga
     */
    private function chiave(array $riga): string
    {
        $tipoRiga = $riga['tipo_riga'] ?? ($riga['dare'] > 0 ? 'dare' : 'avere');
        $rata = $tipoRiga === 'dare' ? ($riga['rata_id'] ?? '-') : '*';

        return ($riga['scrittura_id'] ?? 'r' . $riga['id']) . '|' . $tipoRiga . '|' . $rata;
    }

    /**
     * Compone la riga aggregata con la stessa forma di quella espansa, più `n_righe` e `righe_ids`.
     *
     * @param  list<array<string, mixed>>  $righe
     * @return array<string, mixed>
     */
    private function riga(string $chiave, array $righe, int $dare, int $avere, int $inizio, int $fine): array
    {
        $prima = $righe[0];
        $tipo = $prima['breakdown']['type'] ?? null;

        $saldoUsato = array_sum(array_map(fn ($r) => (int) ($r['saldo_usato'] ?? 0), $righe));
        $totaleRichiesto = array_sum(array_map(fn ($r) => (int) ($r['totale_richiesto'] ?? 0), $righe));
        $unita = $this->unita($righe);

        $dettagli = $tipo === 'emissione'
            ? $this->dettagliEmissione($righe, $saldoUsato, $totaleRichiesto)
            : $this->dettagliUnici($righe);

        if ($unita !== []) {
            $dettagli[] = [
                'type'   => 'immobile',
                'text'   => (count($unita) > 1 ? 'Unità: ' : '') . implode(', ', $unita),
                'status' => null,
            ];
        }

        $breakdown = null;
        if ($tipo !== null) {
            $breakdown = [
                'type'     => $tipo,
                'start'    => MoneyHelper::fromCents($inizio),
                'cost'     => MoneyHelper::fromCents($tipo === 'incasso' ? $avere : $dare),
                'end'      => MoneyHelper::fromCents($fine),
                'immobile' => count($righe) === 1 ? ($prima['breakdown']['immobile'] ?? 'Generico') : 'Generico',
                'unita'    => $unita,
            ];

            if ($tipo === 'emissione') {
                $breakdown['totale_richiesto'] = MoneyHelper::fromCents($totaleRichiesto);
                $breakdown['saldo_usato'] = $saldoUsato != 0 ? MoneyHelper::fromCents($saldoUsato) : null;
            }
        }

        $note = array_values(array_unique(array_filter(array_column($righe, 'note'))));

        return array_merge($prima, [
            'id'               => 'agg-' . $chiave,
            'note'             => $note === [] ? null : implode(' · ', $note),
            'dettagli'         => $dettagli,
            'dare'             => $dare,
            'avere'            => $avere,
            'saldo'            => $fine,
            'breakdown'        => $breakdown,
            'saldo_usato'      => $saldoUsato,
            'totale_richiesto' => $totaleRichiesto,
            'stato_rata'       => $this->statoRiassunto(array_column($righe, 'stato_rata')),
            'immobile_id'      => null,
            'immobile_label'   => implode(', ', $unita),
            'n_righe'          => count($righe),
            'righe_ids'        => array_column($righe, 'id'),
        ]);
    }

    /**
     * Le righe di dettaglio di un'emissione, rifatte sui totali del gruppo: la stessa frase della
     * vista per unità («Include recupero debito pregresso…»), ma con le somme di tutte le unità.
     *
     * @param  list<array<string, mixed>>  $righe
     * @return list<array<string, mixed>>
     */
    private function dettagliEmissione(array $righe, int $saldoUsato, int $totaleRichiesto): array
    {
        $etichetta = $righe[0]['rata_label'] ?? 'Rata';
        if ($saldoUsato > 0) {
            $etichetta .= ' + Recupero debito';
        } elseif ($saldoUsato < 0) {
            $etichetta .= ' (Sconto da credito)';
        }

        $dettagli = [[
            'type'   => 'rata',
            'text'   => $etichetta,
            'status' => $this->statoRiassunto(array_column($righe, 'stato_rata')),
        ]];

        if ($saldoUsato > 0) {
            $dettagli[] = ['type' => 'info', 'text' => 'Include recupero debito pregresso: ' . MoneyHelper::format($saldoUsato), 'status' => null];
            $dettagli[] = ['type' => 'info', 'text' => 'Totale richiesto per questa rata: ' . MoneyHelper::format($totaleRichiesto), 'status' => null];
        } elseif ($saldoUsato < 0) {
            $quotaPura = array_sum(array_map(fn ($r) => (int) ($r['quota_pura'] ?? 0), $righe));
            $dettagli[] = ['type' => 'info', 'text' => 'Scontata da credito pregresso: ' . MoneyHelper::format(abs($saldoUsato)), 'status' => null];
            $dettagli[] = ['type' => 'info', 'text' => 'Valore originale della spesa: ' . MoneyHelper::format($quotaPura), 'status' => null];
        }

        return $dettagli;
    }

    /**
     * Le righe di dettaglio di tutte le righe del gruppo, senza ripetizioni e senza le unità
     * (che si elencano una volta sola, in fondo). Per un incasso su più rate restano le frasi
     * «A copertura Rata n.1…», «A copertura Rata n.2…».
     *
     * @param  list<array<string, mixed>>  $righe
     * @return list<array<string, mixed>>
     */
    private function dettagliUnici(array $righe): array
    {
        $visti = [];
        $dettagli = [];

        foreach ($righe as $riga) {
            foreach ($riga['dettagli'] ?? [] as $d) {
                if (($d['type'] ?? null) === 'immobile') {
                    continue;
                }
                $k = ($d['type'] ?? '') . '|' . ($d['text'] ?? '') . '|' . ($d['status'] ?? '');
                if (! isset($visti[$k])) {
                    $visti[$k] = true;
                    $dettagli[] = $d;
                }
            }
        }

        return $dettagli;
    }

    /**
     * Le unità coinvolte, una volta ciascuna, nell'ordine in cui compaiono.
     *
     * @param  list<array<string, mixed>>  $righe
     * @return list<string>
     */
    private function unita(array $righe): array
    {
        $etichette = array_filter(array_map(fn ($r) => $r['immobile_label'] ?? null, $righe));

        return array_values(array_unique($etichette));
    }

    /**
     * Lo stato della rata sull'insieme delle unità: uguale se tutte concordano, «pagata» se ogni
     * unità è chiusa (pagata o coperta da credito), «parziale» se qualcosa è entrato e qualcosa no.
     *
     * @param  list<string|null>  $stati
     */
    private function statoRiassunto(array $stati): ?string
    {
        $stati = array_values(array_unique(array_filter($stati)));

        if ($stati === []) return null;
        if (count($stati) === 1) return $stati[0];
        if (array_diff($stati, ['pagata', 'credito']) === []) return 'pagata';
        if (array_intersect($stati, ['pagata', 'parzialmente_pagata', 'credito']) !== []) return 'parzialmente_pagata';

        return 'da_pagare';
    }
}
