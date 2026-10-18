<?php

namespace App\Services\Gestionale\Rendiconto;

use App\Enums\TipoMovimentoContabile;
use App\Models\Condominio;
use App\Models\Esercizio;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Registro di contabilità dell'esercizio (art. 1130 n. 7 e art. 1130-bis c.c.): l'elenco
 * cronologico dei movimenti **in entrata e in uscita** di cassa e banca.
 *
 * Si legge dal libro giornale e non da un sottoregistro: sono le righe delle scritture sui conti
 * di liquidità (le casse, i conti bancari e i fondi, che sono partizioni dello stesso conto
 * corrente). Una riga del registro è una coppia (scrittura, conto di liquidità): un giroconto
 * banca → fondo compare quindi due volte, come uscita dalla banca e come entrata nel fondo, e
 * per ciascun conto il saldo torna.
 *
 * Regole:
 * - le scritture `apertura` dell'esercizio non sono entrate: sono il saldo iniziale;
 * - le scritture `chiusura` dell'esercizio (quando esisteranno) non sono uscite: chiudono il
 *   saldo finale, che qui è quello prima della chiusura;
 * - gli storni e le rettifiche restano nel registro con il loro verso (il giornale è append-only)
 *   e si classificano nella categoria del movimento che correggono, così le categorie del
 *   riepilogo finanziario sono al netto degli storni;
 * - le scritture cancellate logicamente (soft delete) non ci sono, come in `SaldoCassaService`.
 *
 * Sola lettura, importi in centesimi.
 */
class RegistroContabilitaService
{
    /** Tipi che non sono movimenti dell'anno ma aprono o chiudono i saldi. */
    public const TIPI_SALDO = ['apertura', 'chiusura'];

    /**
     * I conti di liquidità del condominio: `conto_contabile_id => dati`.
     *
     * Primo criterio le casse (ogni cassa ha il suo mastro, qualunque sia la categoria scritta
     * sul conto); poi i conti di categoria `liquidita` che non sono raggruppamenti di altri conti
     * (per esempio il 1001 Cassa contanti creato col condominio, che non ha una cassa).
     *
     * @return Collection<int, array{conto_id:int, cassa_id:?int, codice:string, nome:string, tipo:string, sottotipo_fondo:?string, vincolo:?string, saldo_colonna:int, attiva:bool}>
     */
    public function contiLiquidita(Condominio $condominio): Collection
    {
        $casse = DB::table('casse as ca')
            ->join('conti_contabili as cc', 'cc.id', '=', 'ca.conto_contabile_id')
            ->where('ca.condominio_id', $condominio->id)
            ->orderBy('cc.codice')
            ->get([
                'ca.id as cassa_id', 'ca.nome', 'ca.tipo', 'ca.sottotipo_fondo', 'ca.vincolo_descrizione',
                'ca.saldo_iniziale', 'ca.attiva', 'cc.id as conto_id', 'cc.codice',
            ]);

        $conti = collect();

        foreach ($casse as $c) {
            $conti->put((int) $c->conto_id, [
                'conto_id'        => (int) $c->conto_id,
                'cassa_id'        => (int) $c->cassa_id,
                'codice'          => (string) $c->codice,
                'nome'            => (string) $c->nome,
                'tipo'            => (string) $c->tipo,
                'sottotipo_fondo' => $c->sottotipo_fondo,
                'vincolo'         => $c->vincolo_descrizione,
                'saldo_colonna'   => (int) $c->saldo_iniziale,
                'attiva'          => (bool) $c->attiva,
            ]);
        }

        $padri = DB::table('conti_contabili')
            ->where('condominio_id', $condominio->id)
            ->whereNotNull('parent_id')
            ->pluck('parent_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $altri = DB::table('conti_contabili')
            ->where('condominio_id', $condominio->id)
            ->where('categoria', 'liquidita')
            ->whereNull('deleted_at')
            ->whereNotIn('id', $conti->keys()->all() ?: [0])
            ->whereNotIn('id', $padri ?: [0])
            ->orderBy('codice')
            ->get(['id', 'codice', 'nome']);

        foreach ($altri as $c) {
            $conti->put((int) $c->id, [
                'conto_id'        => (int) $c->id,
                'cassa_id'        => null,
                'codice'          => (string) $c->codice,
                'nome'            => (string) $c->nome,
                'tipo'            => 'contanti',
                'sottotipo_fondo' => null,
                'vincolo'         => null,
                'saldo_colonna'   => 0,
                'attiva'          => true,
            ]);
        }

        return $conti;
    }

    /**
     * @return array{
     *     righe: list<array<string,mixed>>,
     *     conti: list<array<string,mixed>>,
     *     totali: array{saldo_iniziale:int, entrate:int, uscite:int, saldo_finale:int}
     * }
     */
    public function calcola(Condominio $condominio, Esercizio $esercizio): array
    {
        $conti = $this->contiLiquidita($condominio);
        $idsLiquidita = $conti->keys()->all();

        $gestioni = DB::table('gestioni')->where('condominio_id', $condominio->id)->pluck('nome', 'id');

        $righe = [];
        $perConto = [];
        foreach ($conti as $contoId => $conto) {
            $perConto[$contoId] = ['entrate' => 0, 'uscite' => 0, 'apertura' => 0, 'chiusura' => 0];
        }

        if ($idsLiquidita !== []) {
            $movimenti = DB::table('righe_scritture as rs')
                ->join('scritture_contabili as sc', function ($join) {
                    $join->on('sc.id', '=', 'rs.scrittura_id')->whereNull('sc.deleted_at');
                })
                ->leftJoin('scritture_contabili as sp', 'sp.id', '=', 'sc.scrittura_padre_id')
                ->where('sc.condominio_id', $condominio->id)
                ->where('sc.esercizio_id', $esercizio->id)
                ->whereIn('rs.conto_contabile_id', $idsLiquidita)
                ->orderBy('sc.data_competenza')
                ->orderBy('sc.id')
                ->orderBy('rs.id')
                ->get([
                    'rs.scrittura_id', 'rs.conto_contabile_id', 'rs.tipo_riga', 'rs.importo', 'rs.note',
                    'sc.data_competenza', 'sc.data_registrazione', 'sc.numero_protocollo', 'sc.causale',
                    'sc.descrizione', 'sc.tipo_movimento', 'sc.stato', 'sc.gestione_id',
                    'sp.tipo_movimento as tipo_padre', 'sp.numero_protocollo as protocollo_padre',
                ]);

            // Una riga del registro per (scrittura, conto di liquidità).
            $gruppi = [];
            foreach ($movimenti as $m) {
                $chiave = $m->scrittura_id.'|'.$m->conto_contabile_id;

                if (! isset($gruppi[$chiave])) {
                    $gruppi[$chiave] = ['m' => $m, 'dare' => 0, 'avere' => 0, 'note' => []];
                }

                if ($m->tipo_riga === 'dare') {
                    $gruppi[$chiave]['dare'] += (int) $m->importo;
                } else {
                    $gruppi[$chiave]['avere'] += (int) $m->importo;
                }

                $nota = trim((string) $m->note);
                if ($nota !== '' && ! in_array($nota, $gruppi[$chiave]['note'], true)) {
                    $gruppi[$chiave]['note'][] = $nota;
                }
            }

            foreach ($gruppi as $g) {
                $m = $g['m'];
                $contoId = (int) $m->conto_contabile_id;
                $tipo = (string) $m->tipo_movimento;

                if (in_array($tipo, self::TIPI_SALDO, true)) {
                    $perConto[$contoId][$tipo] += $g['dare'] - $g['avere'];
                    continue;
                }

                $tipoBase = $this->tipoBase($tipo, $m->tipo_padre);
                $gestioneId = $m->gestione_id !== null ? (int) $m->gestione_id : null;
                [$categoria, $etichettaCategoria] = $this->categoria($tipoBase, $gestioneId, $gestioni->get($gestioneId));

                $perConto[$contoId]['entrate'] += $g['dare'];
                $perConto[$contoId]['uscite'] += $g['avere'];

                $causale = trim((string) $m->causale);
                $dettagli = array_values(array_filter($g['note'], fn ($n) => $n !== $causale));
                $descrizione = $causale;
                if ($dettagli !== []) {
                    $descrizione .= ($descrizione !== '' ? ' — ' : '').implode('; ', $dettagli);
                }
                if ($m->protocollo_padre && $tipo !== $tipoBase) {
                    $descrizione .= ' (storno di '.$m->protocollo_padre.')';
                }

                $righe[] = [
                    'scrittura_id'     => (int) $m->scrittura_id,
                    'data'             => substr((string) $m->data_competenza, 0, 10),
                    'protocollo'       => (string) $m->numero_protocollo,
                    'descrizione'      => $descrizione,
                    'tipo'             => $tipo,
                    'tipo_label'       => TipoMovimentoContabile::tryFrom($tipo)?->label() ?? $tipo,
                    'categoria'        => $categoria,
                    'categoria_label'  => $etichettaCategoria,
                    'gestione_id'      => $gestioneId,
                    'gestione'         => $gestioneId !== null ? ($gestioni->get($gestioneId) ?? '—') : '—',
                    'conto_id'         => $contoId,
                    'cassa'            => $conti->get($contoId)['nome'] ?? '',
                    'entrata'          => $g['dare'],
                    'uscita'           => $g['avere'],
                    'annullata'        => $m->stato === 'annullata',
                ];
            }
        }

        $precedenti = $this->movimentiPrecedenti($condominio, $esercizio, $idsLiquidita);

        $riepilogoConti = [];
        $totali = ['saldo_iniziale' => 0, 'entrate' => 0, 'uscite' => 0, 'saldo_finale' => 0];

        foreach ($conti as $contoId => $conto) {
            $iniziale = $conto['saldo_colonna'] + ($precedenti[$contoId] ?? 0) + $perConto[$contoId]['apertura'];
            $entrate = $perConto[$contoId]['entrate'];
            $uscite = $perConto[$contoId]['uscite'];
            $finale = $iniziale + $entrate - $uscite;

            // Un conto senza movimenti e a saldo zero (una cassa mai usata) non va in stampa.
            if ($iniziale === 0 && $entrate === 0 && $uscite === 0 && ! $conto['attiva']) {
                continue;
            }

            $riepilogoConti[] = $conto + [
                'saldo_iniziale' => $iniziale,
                'entrate'        => $entrate,
                'uscite'         => $uscite,
                'saldo_finale'   => $finale,
                'chiusura'       => $perConto[$contoId]['chiusura'],
            ];

            $totali['saldo_iniziale'] += $iniziale;
            $totali['entrate'] += $entrate;
            $totali['uscite'] += $uscite;
            $totali['saldo_finale'] += $finale;
        }

        return [
            'righe'  => $righe,
            'conti'  => $riepilogoConti,
            'totali' => $totali,
        ];
    }

    /**
     * Movimenti netti (DARE − AVERE) dei conti di liquidità negli esercizi iniziati prima di
     * questo: è il saldo con cui il conto entra nell'esercizio, finché non esistono scritture di
     * chiusura e riapertura. Stesso perimetro di `StatoPatrimonialeService`.
     *
     * @param  list<int>  $idsLiquidita
     * @return array<int,int>
     */
    private function movimentiPrecedenti(Condominio $condominio, Esercizio $esercizio, array $idsLiquidita): array
    {
        if ($idsLiquidita === []) {
            return [];
        }

        $eserciziPrecedenti = Esercizio::where('condominio_id', $condominio->id)
            ->where('id', '!=', $esercizio->id)
            ->whereDate('data_inizio', '<', $esercizio->data_inizio)
            ->pluck('id')
            ->all();

        if ($eserciziPrecedenti === []) {
            return [];
        }

        return DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', function ($join) {
                $join->on('sc.id', '=', 'rs.scrittura_id')->whereNull('sc.deleted_at');
            })
            ->whereIn('sc.esercizio_id', $eserciziPrecedenti)
            ->whereIn('rs.conto_contabile_id', $idsLiquidita)
            ->groupBy('rs.conto_contabile_id')
            ->selectRaw('rs.conto_contabile_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE 0 END), 0) as dare")
            ->selectRaw("COALESCE(SUM(CASE WHEN rs.tipo_riga = 'avere' THEN rs.importo ELSE 0 END), 0) as avere")
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->conto_contabile_id => (int) $r->dare - (int) $r->avere])
            ->all();
    }

    /** Il tipo del movimento che uno storno o una rettifica correggono. */
    public function tipoBase(string $tipo, ?string $tipoPadre): string
    {
        return match ($tipo) {
            'storno_pagamento_fornitore'   => 'pagamento_fornitore',
            'storno_regolazione_immediata' => 'regolazione_immediata',
            'storno_pagamento_f24'         => 'pagamento_f24',
            'storno_giroconto'             => 'giroconto',
            'rettifica'                    => ($tipoPadre && $tipoPadre !== 'rettifica') ? $this->tipoBase($tipoPadre, null) : 'rettifica',
            default                        => $tipo,
        };
    }

    /**
     * Categoria del riepilogo finanziario. Versamenti, pagamenti e spese dirette sono distinti
     * per gestione: ordinaria e straordinarie non si mescolano.
     *
     * @return array{0:string, 1:string}
     */
    private function categoria(string $tipoBase, ?int $gestioneId, ?string $nomeGestione): array
    {
        $suffissoGestione = $nomeGestione ? ' – '.$nomeGestione : ' – senza gestione';
        $g = $gestioneId ?? 0;

        return match ($tipoBase) {
            'incasso_rata'          => ["versamenti:{$g}", 'Versamenti dei condòmini'.$suffissoGestione],
            'incasso_diverso',
            'rimborso_assicurativo' => ['altre_entrate', 'Altre entrate (rimborsi, incassi diversi)'],
            'accantonamento'        => ['accantonamenti', 'Contributi pregressi portati in cassa'],
            'pagamento_fornitore'   => ["fornitori:{$g}", 'Pagamenti ai fornitori'.$suffissoGestione],
            'regolazione_immediata' => ["spese_dirette:{$g}", 'Spese pagate direttamente'.$suffissoGestione],
            'pagamento_f24'         => ['f24', "Versamento ritenute d'acconto (F24)"],
            'rimborso_condomino'    => ['rimborsi_condomini', 'Rimborsi ai condòmini'],
            'giroconto'             => ['giroconti', 'Giroconti fra casse e fondi (movimenti interni)'],
            'rettifica'             => ['rettifiche', 'Rettifiche e riallineamenti'],
            default                 => ["altro:{$tipoBase}", TipoMovimentoContabile::tryFrom($tipoBase)?->label() ?? $tipoBase],
        };
    }
}
