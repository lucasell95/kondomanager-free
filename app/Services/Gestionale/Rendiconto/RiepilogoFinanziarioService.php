<?php

namespace App\Services\Gestionale\Rendiconto;

use App\Enums\TipoAllocazioneFattura;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Services\Gestionale\StatoPatrimonialeService;
use Illuminate\Support\Facades\DB;

/**
 * Riepilogo finanziario del rendiconto (art. 1130-bis c.c.): saldi iniziali e finali di casse,
 * banca e fondi, entrate e uscite per categoria, crediti verso i condòmini e debiti verso i
 * fornitori a fine esercizio, situazione patrimoniale.
 *
 * Il saldo finale di ogni conto di liquidità esce dal registro (iniziale + entrate − uscite) e
 * viene confrontato con il saldo contabile calcolato da `StatoPatrimonialeService`, cioè per
 * un'altra strada, e con il saldo dell'estratto conto se l'utente l'ha indicato.
 *
 * Sola lettura, importi in centesimi.
 */
class RiepilogoFinanziarioService
{
    private const SOTTOTIPI_FONDO = [
        'generico'         => 'Fondo generico',
        'vincolato_lavori' => 'Fondo speciale lavori straordinari (art. 1135 n. 4 c.c.)',
        'tfr'              => 'Fondo TFR',
        'morosita'         => 'Fondo morosità',
    ];

    public function __construct(private readonly StatoPatrimonialeService $statoPatrimoniale) {}

    /**
     * @param  array  $registro  il risultato di `RegistroContabilitaService::calcola()`
     * @param  array<int,int|null>  $estrattiConto  `conto_contabile_id => saldo da estratto conto`
     */
    public function calcola(Condominio $condominio, Esercizio $esercizio, array $registro, array $estrattiConto = []): array
    {
        $sp = $this->statoPatrimoniale->calcola($condominio, $esercizio);
        $saldiSp = [];
        foreach (array_merge($sp['attivo']['voci'], $sp['passivo']['voci']) as $voce) {
            $saldiSp[(int) $voce['id']] = (int) $voce['saldo'];
        }

        // ─── Casse, banca e fondi ──────────────────────────────────────────────────────────
        $conti = [];
        foreach ($registro['conti'] as $conto) {
            // Il saldo contabile per l'altra strada: giornale cumulativo fino a fine esercizio
            // (stato patrimoniale) più l'eventuale saldo di apertura non ancora a giornale, al
            // netto delle scritture di chiusura dell'esercizio, che il registro esclude.
            $contabile = ($saldiSp[$conto['conto_id']] ?? 0) + $conto['saldo_colonna'] - $conto['chiusura'];
            $estratto = $estrattiConto[$conto['conto_id']] ?? null;

            $conti[] = $conto + [
                'sottotipo_label'  => $conto['tipo'] === 'fondo' ? (self::SOTTOTIPI_FONDO[$conto['sottotipo_fondo'] ?? 'generico'] ?? 'Fondo') : null,
                'saldo_contabile'  => $contabile,
                'estratto_conto'   => $estratto,
                'differenza_estratto' => $estratto !== null ? $conto['saldo_finale'] - (int) $estratto : null,
            ];
        }

        // ─── Entrate e uscite per categoria ────────────────────────────────────────────────
        $categorie = [];
        foreach ($registro['righe'] as $riga) {
            $k = $riga['categoria'];
            if (! isset($categorie[$k])) {
                $categorie[$k] = ['chiave' => $k, 'nome' => $riga['categoria_label'], 'entrate' => 0, 'uscite' => 0];
            }
            $categorie[$k]['entrate'] += $riga['entrata'];
            $categorie[$k]['uscite'] += $riga['uscita'];
        }
        foreach ($categorie as $k => $c) {
            $categorie[$k]['netto'] = $c['entrate'] - $c['uscite'];
            $categorie[$k]['interna'] = $k === 'giroconti';
        }
        $categorie = array_values($categorie);
        usort($categorie, function ($a, $b) {
            // Prima le entrate nette, poi le uscite, i movimenti interni in fondo.
            $peso = fn ($c) => $c['interna'] ? 2 : ($c['netto'] >= 0 ? 0 : 1);
            return [$peso($a), $a['nome']] <=> [$peso($b), $b['nome']];
        });

        // ─── Fondi ─────────────────────────────────────────────────────────────────────────
        $fondi = array_values(array_filter($conti, fn ($c) => $c['tipo'] === 'fondo'));
        $fondoSpeciale = array_sum(array_map(
            fn ($c) => $c['saldo_finale'],
            array_filter($fondi, fn ($c) => ($c['sottotipo_fondo'] ?? null) === 'vincolato_lavori')
        ));

        // I fondi in senso contabile (passivo): fondo passate gestioni, gestione rate, riserve.
        $fondiPassivi = array_values(array_map(
            fn ($v) => ['codice' => $v['codice'], 'nome' => $v['nome'], 'saldo' => (int) $v['saldo']],
            array_filter($sp['passivo']['voci'], fn ($v) => ($v['categoria'] ?? null) === 'fondi' && (int) $v['saldo'] !== 0)
        ));

        return [
            'conti'           => $conti,
            'totali'          => $registro['totali'],
            'categorie'       => $categorie,
            'fondi'           => $fondi,
            'fondo_speciale'  => $fondoSpeciale,
            'fondi_passivi'   => $fondiPassivi,
            'crediti'         => $this->crediti($condominio, $esercizio),
            'debiti'          => $this->debiti($condominio, $esercizio, $saldiSp),
            'stato_patrimoniale' => [
                'attivo'   => $this->vociSp($sp['attivo']),
                'passivo'  => $this->vociSp($sp['passivo']),
                'costi'    => $sp['costi'],
                'ricavi'   => $sp['ricavi'],
                'risultato_esercizio' => $sp['risultato_esercizio'],
                'risultato_esercizi_precedenti' => $sp['risultato_esercizi_precedenti'],
                'liquidita_non_contabilizzata' => $sp['liquidita_non_contabilizzata'],
                'sbilancio' => $sp['sbilancio'],
                'quadra'    => $sp['quadra'],
            ],
        ];
    }

    private function vociSp(array $sezione): array
    {
        return [
            'totale' => (int) $sezione['totale'],
            'voci'   => array_values(array_map(
                fn ($v) => ['codice' => $v['codice'], 'nome' => $v['nome'], 'categoria' => $v['categoria'], 'saldo' => (int) $v['saldo']],
                array_filter($sezione['voci'], fn ($v) => (int) $v['saldo'] !== 0)
            )),
        ];
    }

    /** Gli esercizi la cui contabilità è dentro la fotografia di fine esercizio. */
    private function eserciziInclusi(Condominio $condominio, Esercizio $esercizio): array
    {
        return Esercizio::where('condominio_id', $condominio->id)
            ->whereDate('data_inizio', '<=', $esercizio->data_inizio)
            ->pluck('id')
            ->push($esercizio->id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Crediti verso i condòmini a fine esercizio: Crediti v/condòmini meno Anticipi da condòmini,
     * per anagrafica, cumulativi come lo stato patrimoniale. Le anticipazioni ad personam ancora
     * prive di anagrafica sono a parte.
     */
    private function crediti(Condominio $condominio, Esercizio $esercizio): array
    {
        $righe = DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', function ($join) {
                $join->on('sc.id', '=', 'rs.scrittura_id')->whereNull('sc.deleted_at');
            })
            ->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')
            ->where('sc.condominio_id', $condominio->id)
            ->whereIn('sc.esercizio_id', $this->eserciziInclusi($condominio, $esercizio))
            ->whereIn('cc.ruolo', ['crediti_condomini', 'anticipi_condomini'])
            ->groupBy('rs.anagrafica_id', 'cc.ruolo')
            ->selectRaw('rs.anagrafica_id, cc.ruolo')
            ->selectRaw("COALESCE(SUM(CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE 0 END), 0) as dare")
            ->selectRaw("COALESCE(SUM(CASE WHEN rs.tipo_riga = 'avere' THEN rs.importo ELSE 0 END), 0) as avere")
            ->get();

        $perAnagrafica = [];
        $anticipazioniAdPersonam = 0;

        foreach ($righe as $r) {
            $saldo = (int) $r->dare - (int) $r->avere;   // positivo = il condòmino deve

            if ($r->anagrafica_id === null) {
                $anticipazioniAdPersonam += $saldo;
                continue;
            }

            $aid = (int) $r->anagrafica_id;
            $perAnagrafica[$aid] ??= ['anagrafica_id' => $aid, 'nome' => '', 'crediti' => 0, 'anticipi' => 0, 'saldo' => 0];

            if ($r->ruolo === 'anticipi_condomini') {
                $perAnagrafica[$aid]['anticipi'] += -$saldo;
            } else {
                $perAnagrafica[$aid]['crediti'] += $saldo;
            }
            $perAnagrafica[$aid]['saldo'] += $saldo;
        }

        $nomi = DB::table('anagrafiche')->whereIn('id', array_keys($perAnagrafica) ?: [0])->pluck('nome', 'id');
        foreach ($perAnagrafica as $aid => $riga) {
            $perAnagrafica[$aid]['nome'] = (string) ($nomi[$aid] ?? "Anagrafica {$aid}");
        }

        $elenco = array_values(array_filter($perAnagrafica, fn ($r) => $r['saldo'] !== 0 || $r['crediti'] !== 0));
        usort($elenco, fn ($a, $b) => strcasecmp($a['nome'], $b['nome']));

        return [
            'righe'           => $elenco,
            'totale_a_debito' => array_sum(array_map(fn ($r) => max(0, $r['saldo']), $elenco)),
            'totale_a_credito' => array_sum(array_map(fn ($r) => min(0, $r['saldo']), $elenco)),
            'totale'          => array_sum(array_column($elenco, 'saldo')),
            'condomini_a_debito' => count(array_filter($elenco, fn ($r) => $r['saldo'] > 0)),
            'anticipazioni_ad_personam' => $anticipazioniAdPersonam,
        ];
    }

    /**
     * Debiti verso i fornitori a fine esercizio: le fatture registrate fino a fine esercizio con
     * un residuo a quella data (pagamenti e compensazioni datati entro la fine dell'esercizio),
     * più i saldi dei mastri dei debiti verso fornitori ed erario.
     *
     * @param  array<int,int>  $saldiSp
     */
    private function debiti(Condominio $condominio, Esercizio $esercizio, array $saldiSp): array
    {
        $fine = $esercizio->data_fine?->toDateString() ?? now()->toDateString();

        $fatture = DB::table('fatture_passive as fp')
            ->join('fornitori as f', 'f.id', '=', 'fp.fornitore_id')
            ->where('fp.condominio_id', $condominio->id)
            ->whereIn('fp.esercizio_id', $this->eserciziInclusi($condominio, $esercizio))
            ->where('fp.tipo_documento', 'fattura')
            ->where('fp.stato_approvazione', '!=', 'contestata')
            ->where('fp.stato_pagamento', '!=', 'stornata')
            ->whereDate('fp.data_documento', '<=', $fine)
            ->get(['fp.id', 'fp.numero_documento', 'fp.data_documento', 'fp.netto_a_pagare', 'fp.data_scadenza', 'f.ragione_sociale']);

        $allocato = DB::table('fattura_scrittura as fs')
            ->join('scritture_contabili as sc', function ($join) {
                $join->on('sc.id', '=', 'fs.scrittura_contabile_id')->whereNull('sc.deleted_at');
            })
            ->whereIn('fs.fattura_passiva_id', $fatture->pluck('id')->all() ?: [0])
            ->whereIn('fs.tipo', TipoAllocazioneFattura::perCalcoloSaldo())
            ->whereDate('sc.data_competenza', '<=', $fine)
            ->groupBy('fs.fattura_passiva_id')
            ->selectRaw('fs.fattura_passiva_id, COALESCE(SUM(fs.importo_allocato), 0) as totale')
            ->pluck('totale', 'fattura_passiva_id');

        $righe = [];
        foreach ($fatture as $f) {
            $residuo = abs((int) $f->netto_a_pagare) - (int) ($allocato[$f->id] ?? 0);
            if ($residuo <= 0) continue;

            $righe[] = [
                'fattura_id' => (int) $f->id,
                'fornitore'  => (string) $f->ragione_sociale,
                'numero'     => (string) $f->numero_documento,
                'data'       => substr((string) $f->data_documento, 0, 10),
                'scadenza'   => $f->data_scadenza ? substr((string) $f->data_scadenza, 0, 10) : null,
                'residuo'    => $residuo,
            ];
        }
        usort($righe, fn ($a, $b) => [$a['fornitore'], $a['data']] <=> [$b['fornitore'], $b['data']]);

        $mastro = fn (string $ruolo) => (int) collect(DB::table('conti_contabili')
            ->where('condominio_id', $condominio->id)
            ->where('ruolo', $ruolo)
            ->pluck('id'))
            ->sum(fn ($id) => $saldiSp[(int) $id] ?? 0);

        return [
            'fatture'           => $righe,
            'totale_fatture'    => array_sum(array_column($righe, 'residuo')),
            'mastro_fornitori'  => $mastro('debiti_fornitori'),
            'ritenute_da_versare' => $mastro('debiti_erario_ritenute'),
        ];
    }
}
