<?php

namespace App\Services\Gestionale\Fiscale;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Il prospetto del sostituto in forma di tabelle, per il foglio di calcolo o per il confronto con
 * i dati del commercialista.
 *
 * Quattro tabelle: percipienti (i punti della CU), pagamenti (il dettaglio da cui nascono),
 * quadratura (operate contro versate per mese e codice tributo) e quadro ST. In XLSX sono quattro
 * fogli; in CSV se ne sceglie una.
 *
 * Gli importi escono in **euro** (non in centesimi): nel CSV con la virgola decimale e il punto e
 * virgola come separatore, che è ciò che Excel in italiano apre senza importazione guidata; nel
 * XLSX come numeri veri, formattati.
 */
class EsportazioneProspettoService
{
    public const SEZIONI = ['percipienti', 'pagamenti', 'quadratura', 'st'];

    /**
     * Le tabelle del prospetto: intestazioni e righe con importi in centesimi.
     *
     * @return array<string,array{titolo:string, intestazioni:array<int,string>, importi:array<int,int>, righe:array<int,array<int,mixed>>}>
     */
    public function tabelle(array $prospetto): array
    {
        $data = fn (?string $d) => $d ? \Carbon\Carbon::parse($d)->format('d/m/Y') : '';

        $percipienti = [];
        $pagamenti = [];

        foreach ($prospetto['percipienti'] as $p) {
            $a = $p['anagrafica'];
            $percipienti[] = [
                $a['codice_fiscale'], $a['persona_fisica'] ? ($a['cognome'] ?? '') : $a['denominazione'],
                $a['persona_fisica'] ? ($a['nome'] ?? '') : '', $a['sesso'] ?? '', $data($a['data_nascita']),
                $a['comune_nascita'] ?? '', $a['provincia_nascita'] ?? '',
                $a['domicilio']['comune'] ?? '', $a['domicilio']['provincia'] ?? '',
                $p['punti']['1'], $p['punti']['4'], $p['punti']['6'] ?? '', $p['punti']['7'], $p['punti']['8'],
                $p['punti']['9'], $p['punti']['10'], $p['ritenute_versate'], $p['differenza'],
                implode(' | ', $p['anomalie']),
            ];

            foreach ($p['pagamenti'] as $r) {
                $pagamenti[] = [
                    $a['codice_fiscale'], $a['denominazione'], $data($r['data_pagamento']), $p['causale'],
                    implode(', ', array_map(fn ($f) => $f['numero'].' del '.$data($f['data']), $r['fatture'])),
                    $r['lordo_con_iva'], $r['compenso'], $r['non_soggette_totale'],
                    implode(', ', array_keys($r['non_soggette'])), $r['imponibile'], $r['ritenuta'],
                    $r['codice_tributo'], $data($r['scadenza_legale']), $r['versato'], $data($r['data_versamento']),
                    $r['stato_versamento'], $r['giorni_ritardo'],
                    implode(', ', array_filter(array_map(fn ($v) => $v['protocollo'], $r['versamenti']))),
                ];
            }
        }

        $quadratura = array_map(fn ($c) => [
            $c['periodo'], $c['codice_tributo'], $c['operate'], $c['versate'], $c['differenza'],
        ], $prospetto['quadratura']['righe']);

        $st = array_map(fn ($r) => [
            $r['periodo'], $r['codice_tributo'], $r['ritenute_operate'], $r['interessi'],
            $r['ravvedimento'] ? 'X' : '', $r['importo_versato'], $data($r['data_versamento']),
            implode(', ', $r['deleghe']),
        ], $prospetto['st']);

        return [
            'percipienti' => [
                'titolo' => 'Percipienti (CU)',
                'intestazioni' => ['Codice fiscale', 'Cognome / denominazione', 'Nome', 'Sesso', 'Data nascita', 'Comune nascita', 'Prov. nascita',
                    'Comune domicilio', 'Prov. domicilio', 'Causale (p.1)', 'Ammontare lordo (p.4)', 'Codice non soggette (p.6)',
                    'Altre somme non soggette (p.7)', 'Imponibile (p.8)', 'Ritenute a titolo d\'acconto (p.9)',
                    'Ritenute a titolo d\'imposta (p.10)', 'Ritenute versate', 'Differenza operate-versate', 'Anomalie'],
                'importi' => [10, 12, 13, 14, 15, 16, 17],
                'righe' => $percipienti,
            ],
            'pagamenti' => [
                'titolo' => 'Pagamenti',
                'intestazioni' => ['Codice fiscale', 'Percipiente', 'Data pagamento', 'Causale', 'Fatture', 'Lordo con IVA', 'Compenso netto IVA',
                    'Somme non soggette', 'Codici', 'Imponibile', 'Ritenuta', 'Codice tributo', 'Scadenza di legge', 'Versato',
                    'Data versamento', 'Stato', 'Giorni di ritardo', 'Deleghe F24'],
                'importi' => [5, 6, 7, 9, 10, 13],
                'righe' => $pagamenti,
            ],
            'quadratura' => [
                'titolo' => 'Quadratura',
                'intestazioni' => ['Periodo', 'Codice tributo', 'Ritenute operate', 'Ritenute versate', 'Differenza'],
                'importi' => [2, 3, 4],
                'righe' => $quadratura,
            ],
            'st' => [
                'titolo' => 'Quadro ST',
                'intestazioni' => ['Periodo di riferimento', 'Codice tributo', 'Ritenute operate', 'Interessi', 'Ravvedimento',
                    'Importo versato', 'Data versamento', 'Deleghe'],
                'importi' => [2, 3, 5],
                'righe' => $st,
            ],
        ];
    }

    /** Il CSV di una sezione, con BOM UTF-8 per Excel. */
    public function csv(array $prospetto, string $sezione): string
    {
        $tabella = $this->tabelle($prospetto)[$sezione];
        $h = fopen('php://temp', 'r+');
        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, $tabella['intestazioni'], ';', '"', '');

        foreach ($tabella['righe'] as $riga) {
            foreach ($tabella['importi'] as $i) {
                $riga[$i] = number_format(((int) $riga[$i]) / 100, 2, ',', '');
            }
            fputcsv($h, array_map(fn ($v) => $v === null ? '' : (string) $v, $riga), ';', '"', '');
        }

        rewind($h);
        $csv = stream_get_contents($h);
        fclose($h);

        return $csv;
    }

    /** Il file XLSX con tutte le sezioni, come stringa binaria. */
    public function xlsx(array $prospetto): string
    {
        $libro = new Spreadsheet();
        $libro->removeSheetByIndex(0);

        foreach ($this->tabelle($prospetto) as $tabella) {
            $foglio = $libro->createSheet();
            $foglio->setTitle(mb_substr($tabella['titolo'], 0, 31));
            $foglio->fromArray($tabella['intestazioni'], null, 'A1');
            $foglio->getStyle('1:1')->getFont()->setBold(true);

            $r = 2;
            foreach ($tabella['righe'] as $riga) {
                foreach ($tabella['importi'] as $i) {
                    $riga[$i] = round(((int) $riga[$i]) / 100, 2);
                }
                $foglio->fromArray(array_map(fn ($v) => $v ?? '', $riga), null, 'A'.$r, true);
                $r++;
            }

            foreach ($tabella['importi'] as $i) {
                $colonna = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
                $foglio->getStyle("{$colonna}2:{$colonna}".max(2, $r))
                    ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
            }

            foreach (range(1, count($tabella['intestazioni'])) as $c) {
                $foglio->getColumnDimensionByColumn($c)->setAutoSize(true);
            }
        }

        $libro->setActiveSheetIndex(0);

        $h = fopen('php://temp', 'r+');
        (new Xlsx($libro))->save($h);
        rewind($h);
        $binario = stream_get_contents($h);
        fclose($h);

        return $binario;
    }
}
