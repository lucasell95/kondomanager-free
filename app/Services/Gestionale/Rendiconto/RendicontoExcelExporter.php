<?php

namespace App\Services\Gestionale\Rendiconto;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Esportazione del rendiconto in XLSX: un foglio per parte (riepilogo, registro, consuntivo,
 * riparto di ogni gestione, nota, verifiche).
 *
 * Gli importi vanno in euro come numeri veri (non testo), così il foglio si può sommare e
 * controllare. I testi si scrivono sempre come stringa esplicita: una descrizione che comincia
 * con «=» non deve diventare una formula.
 */
class RendicontoExcelExporter
{
    private const FORMATO_EURO = '#,##0.00;[Red]-#,##0.00';

    /** @return string il percorso del file temporaneo generato */
    public function esporta(array $r, array $nota): string
    {
        $libro = new Spreadsheet();
        $libro->getProperties()
            ->setTitle('Rendiconto '.$r['esercizio']['nome'])
            ->setSubject('Rendiconto condominiale art. 1130-bis c.c.')
            ->setCreator('KondoManager');

        $this->riepilogo($libro->getActiveSheet(), $r);
        $this->registro($libro->createSheet(), $r);
        $this->consuntivo($libro->createSheet(), $r);
        foreach ($r['riparto'] as $i => $rip) {
            $this->riparto($libro->createSheet(), $rip, $i + 1);
        }
        $this->nota($libro->createSheet(), $nota);
        $this->verifiche($libro->createSheet(), $r);

        $libro->setActiveSheetIndex(0);

        $base = tempnam(sys_get_temp_dir(), 'rendiconto_');
        $percorso = $base.'.xlsx';
        @unlink($base);
        (new Xlsx($libro))->save($percorso);
        $libro->disconnectWorksheets();

        return $percorso;
    }

    private function riepilogo(Worksheet $s, array $r): void
    {
        $s->setTitle('Riepilogo');
        $rie = $r['riepilogo'];
        $riga = $this->intestazione($s, $r, 'Riepilogo finanziario');

        $riga = $this->tabella($s, $riga, ['Conto', 'Tipo', 'Saldo iniziale', 'Entrate', 'Uscite', 'Saldo finale', 'Estratto conto'],
            array_map(fn ($c) => [$c['nome'], $c['sottotipo_label'] ?? $c['tipo'], $this->eur($c['saldo_iniziale']), $this->eur($c['entrate']), $this->eur($c['uscite']), $this->eur($c['saldo_finale']), $c['estratto_conto'] === null ? null : $this->eur((int) $c['estratto_conto'])], $rie['conti']),
            ['Totale', '', $this->eur($rie['totali']['saldo_iniziale']), $this->eur($rie['totali']['entrate']), $this->eur($rie['totali']['uscite']), $this->eur($rie['totali']['saldo_finale']), null],
            [3, 4, 5, 6, 7]);

        $riga = $this->tabella($s, $riga + 1, ['Categoria', 'Entrate', 'Uscite', 'Netto'],
            array_map(fn ($c) => [$c['nome'], $this->eur($c['entrate']), $this->eur($c['uscite']), $this->eur($c['netto'])], $rie['categorie']),
            null, [2, 3, 4]);

        $riga = $this->tabella($s, $riga + 1, ['Crediti verso condòmini a fine esercizio', 'Rate non pagate', 'Anticipi', 'Saldo'],
            array_map(fn ($c) => [$c['nome'], $this->eur($c['crediti']), $this->eur($c['anticipi']), $this->eur($c['saldo'])], $rie['crediti']['righe']),
            ['Totale', null, null, $this->eur($rie['crediti']['totale'])], [2, 3, 4]);

        $this->tabella($s, $riga + 1, ['Debiti verso fornitori a fine esercizio', 'Documento', 'Data', 'Residuo'],
            array_map(fn ($d) => [$d['fornitore'], $d['numero'], $this->dataIt($d['data']), $this->eur($d['residuo'])], $rie['debiti']['fatture']),
            ['Totale', '', '', $this->eur($rie['debiti']['totale_fatture'])], [4]);

        $this->larghezze($s, [42, 18, 16, 16, 16, 16, 16]);
    }

    private function registro(Worksheet $s, array $r): void
    {
        $s->setTitle('Registro');
        $riga = $this->intestazione($s, $r, 'Registro di contabilità');
        $reg = $r['registro'];

        $this->tabella($s, $riga, ['Data', 'Protocollo', 'Descrizione', 'Gestione', 'Conto', 'Entrate', 'Uscite'],
            array_map(fn ($m) => [$this->dataIt($m['data']), $m['protocollo'], $m['descrizione'].($m['annullata'] ? ' [annullata]' : ''), $m['gestione'], $m['cassa'], $m['entrata'] ? $this->eur($m['entrata']) : null, $m['uscita'] ? $this->eur($m['uscita']) : null], $reg['righe']),
            ['Totale', '', '', '', '', $this->eur($reg['totali']['entrate']), $this->eur($reg['totali']['uscite'])],
            [6, 7]);

        $this->larghezze($s, [12, 16, 60, 22, 22, 14, 14]);
    }

    private function consuntivo(Worksheet $s, array $r): void
    {
        $s->setTitle('Consuntivo');
        $riga = $this->intestazione($s, $r, 'Consuntivo per voce e confronto con il preventivo');

        foreach ($r['consuntivo']['gestioni'] as $g) {
            $s->setCellValueExplicit([1, $riga], 'Gestione: '.$g['nome'], DataType::TYPE_STRING);
            $s->getStyle([1, $riga])->getFont()->setBold(true);
            $riga++;

            $righe = [];
            foreach (array_merge($g['voci'], $g['sopravvenienze']) as $v) {
                $righe[] = [str_repeat('   ', $v['livello']).$v['nome'].($v['tecnico'] ? ' (fuori preventivo)' : ''), $this->eur($v['preventivo']), $this->eur($v['consuntivo']), $this->eur($v['scostamento']), $v['scostamento_perc']];
            }
            foreach ($g['ad_personam'] as $a) {
                $righe[] = ['Spese individuali – '.$a['nome'], null, $this->eur($a['importo']), null, null];
            }
            foreach ($g['senza_voce'] as $a) {
                $righe[] = ['Costi senza voce – '.$a['conto'], null, $this->eur($a['importo']), null, null];
            }
            $t = $g['totali'];
            $riga = $this->tabella($s, $riga, ['Voce', 'Preventivo', 'Consuntivo', 'Scostamento', 'Scost. %'], $righe,
                ['Totale', $this->eur($t['preventivo']), $this->eur($t['consuntivo']), $this->eur($t['scostamento']), $t['scostamento_perc']], [2, 3, 4]);
            $riga++;
        }

        $this->larghezze($s, [50, 16, 16, 16, 10]);
    }

    private function riparto(Worksheet $s, array $rip, int $n): void
    {
        $s->setTitle(mb_substr('Riparto '.$n.' '.preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $rip['gestione']), 0, 31));
        $s->setCellValueExplicit([1, 1], 'Riparto consuntivo e conguagli – '.$rip['gestione'], DataType::TYPE_STRING);
        $s->getStyle([1, 1])->getFont()->setBold(true)->setSize(12);

        $intest = array_merge(['Condòmino', 'Unità'], array_map(fn ($c) => $c['nome'], $rip['colonne']), ['Quota consuntiva', 'Saldo iniziale', 'Già versato', 'Versato', 'Conguaglio']);
        $righe = array_map(function ($r) use ($rip) {
            $unita = implode(', ', array_map(fn ($u) => $u['nome'].($u['ruolo'] ? ' ('.$u['ruolo'].')' : ''), $r['unita']));
            $celle = [$r['nome'], $unita];
            foreach ($rip['colonne'] as $c) {
                $celle[] = $this->eur((int) ($r['per_capitolo'][$c['chiave']] ?? 0));
            }

            return array_merge($celle, [$this->eur($r['quota']), $this->eur($r['saldo_iniziale']), $this->eur($r['contributi']), $this->eur($r['versato']), $this->eur($r['conguaglio'])]);
        }, $rip['righe']);
        $t = $rip['totali'];
        $totale = array_merge(['Totale', ''], array_map(fn ($c) => $this->eur($c['totale']), $rip['colonne']),
            [$this->eur($t['ripartito']), $this->eur($t['saldo_iniziale']), $this->eur($t['contributi']), $this->eur($t['versato']), $this->eur($t['conguaglio'])]);

        $this->tabella($s, 3, $intest, $righe, $totale, range(3, count($intest)));
        $this->larghezze($s, array_merge([30, 30], array_fill(0, count($intest) - 2, 15)));
    }

    private function nota(Worksheet $s, array $nota): void
    {
        $s->setTitle('Nota sintetica');
        $s->setCellValueExplicit([1, 1], 'Nota sintetica esplicativa', DataType::TYPE_STRING);
        $s->getStyle([1, 1])->getFont()->setBold(true)->setSize(12);
        $riga = 3;
        foreach (preg_split('/\R/', (string) $nota['compilata']) as $linea) {
            $s->setCellValueExplicit([1, $riga++], $linea, DataType::TYPE_STRING);
        }
        $s->getColumnDimension('A')->setWidth(140);
    }

    private function verifiche(Worksheet $s, array $r): void
    {
        $s->setTitle('Verifiche');
        $this->tabella($s, 1, ['Verifica', 'Esito', 'Dettaglio'],
            array_map(fn ($v) => [$v['titolo'], strtoupper($v['esito']), $v['dettaglio']], $r['verifiche']), null, []);
        $this->larghezze($s, [55, 10, 120]);
    }

    // ─── Supporto ──────────────────────────────────────────────────────────────────────────

    private function intestazione(Worksheet $s, array $r, string $titolo): int
    {
        $s->setCellValueExplicit([1, 1], $r['condominio']['nome'], DataType::TYPE_STRING);
        $s->setCellValueExplicit([1, 2], $titolo.' – esercizio '.$r['esercizio']['nome'].' (dal '.$this->dataIt($r['esercizio']['data_inizio']).' al '.$this->dataIt($r['esercizio']['data_fine']).')', DataType::TYPE_STRING);
        $s->getStyle([1, 1])->getFont()->setBold(true)->setSize(12);

        return 4;
    }

    /**
     * Scrive una tabella con intestazione e, se c'è, la riga dei totali.
     *
     * @param  list<int>  $colonneEuro  indici (da 1) delle colonne in euro
     * @return int la prima riga libera dopo la tabella
     */
    private function tabella(Worksheet $s, int $riga, array $intestazioni, array $righe, ?array $totale, array $colonneEuro): int
    {
        $ultima = Coordinate::stringFromColumnIndex(count($intestazioni));
        foreach ($intestazioni as $i => $testo) {
            $s->setCellValueExplicit([$i + 1, $riga], (string) $testo, DataType::TYPE_STRING);
        }
        $s->getStyle("A{$riga}:{$ultima}{$riga}")->getFont()->setBold(true);
        $s->getStyle("A{$riga}:{$ultima}{$riga}")->getFill()->setFillType('solid')->getStartColor()->setRGB('E2E8F0');
        $inizio = ++$riga;

        foreach ($righe as $celle) {
            $this->scriviRiga($s, $riga++, $celle);
        }
        if ($totale !== null) {
            $this->scriviRiga($s, $riga, $totale);
            $s->getStyle("A{$riga}:{$ultima}{$riga}")->getFont()->setBold(true);
            $riga++;
        }

        foreach ($colonneEuro as $col) {
            $lettera = Coordinate::stringFromColumnIndex($col);
            if ($riga > $inizio) {
                $s->getStyle("{$lettera}{$inizio}:{$lettera}".($riga - 1))->getNumberFormat()->setFormatCode(self::FORMATO_EURO);
            }
        }

        return $riga;
    }

    private function scriviRiga(Worksheet $s, int $riga, array $celle): void
    {
        foreach (array_values($celle) as $i => $valore) {
            if ($valore === null || $valore === '') continue;
            if (is_int($valore) || is_float($valore)) {
                $s->setCellValueExplicit([$i + 1, $riga], $valore, DataType::TYPE_NUMERIC);
            } else {
                $s->setCellValueExplicit([$i + 1, $riga], (string) $valore, DataType::TYPE_STRING);
            }
        }
    }

    private function larghezze(Worksheet $s, array $larghezze): void
    {
        foreach ($larghezze as $i => $w) {
            $s->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setWidth($w);
        }
    }

    private function eur(int $cents): float
    {
        return round($cents / 100, 2);
    }

    private function dataIt(?string $d): string
    {
        return $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m/Y') : '';
    }
}
