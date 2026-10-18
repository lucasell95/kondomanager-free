<?php

namespace App\Services\Gestionale\Banca\Lettori;

use App\Services\Gestionale\Banca\Testo;
use RuntimeException;

/**
 * Parser minimale di OFX/QFX (Open Financial Exchange), sia la variante SGML 1.x (tag non chiusi)
 * sia l'XML 2.x. Legge `STMTTRN` (data, importo con segno, nome, memo, FITID) e `LEDGERBAL`.
 *
 * Poche banche italiane lo offrono; resta utile per chi esporta da app di aggregazione.
 */
final class LettoreOfx
{
    public function leggi(string $percorso): EstrattoLetto
    {
        $testo = file_get_contents($percorso);

        if ($testo === false || ! preg_match('/<OFX>/i', $testo)) {
            throw new RuntimeException('Non è un file OFX (manca il tag <OFX>).');
        }

        if (! mb_check_encoding($testo, 'UTF-8')) {
            $testo = mb_convert_encoding($testo, 'UTF-8', 'Windows-1252');
        }

        $estratto = new EstrattoLetto('ofx');
        $estratto->ibanConto = Testo::normalizzaIban($this->campo($testo, 'ACCTID'));

        preg_match_all('/<STMTTRN>(.*?)(?:<\/STMTTRN>|(?=<STMTTRN>)|(?=<\/BANKTRANLIST>))/is', $testo, $blocchi);

        foreach ($blocchi[1] as $i => $blocco) {
            $importo = Testo::importoInCentesimi($this->campo($blocco, 'TRNAMT'), '.');
            $data = Testo::data(substr((string) $this->campo($blocco, 'DTPOSTED'), 0, 8));

            if ($importo === null || $data === null) {
                $estratto->scarti[] = ['riga' => $i + 1, 'motivo' => 'Transazione senza importo o senza data.'];

                continue;
            }

            $nome = $this->campo($blocco, 'NAME');
            $memo = $this->campo($blocco, 'MEMO');
            $descrizione = implode(' — ', array_filter([$nome, $memo]));

            $estratto->movimenti[] = [
                'riga' => $i + 1,
                'data_contabile' => $data,
                'data_valuta' => Testo::data(substr((string) $this->campo($blocco, 'DTUSER'), 0, 8)),
                'importo' => $importo,
                'divisa' => 'EUR',
                'descrizione' => mb_substr($descrizione !== '' ? $descrizione : 'Movimento', 0, 2000),
                'controparte' => $nome ? mb_substr($nome, 0, 255) : null,
                'iban_controparte' => Testo::estraiIban($descrizione, $estratto->ibanConto),
                'riferimento' => $this->campo($blocco, 'FITID'),
            ];
        }

        if (preg_match('/<LEDGERBAL>(.*?)(?:<\/LEDGERBAL>|<AVAILBAL>|<\/STMTRS>)/is', $testo, $m)) {
            $estratto->saldoFinale = Testo::importoInCentesimi($this->campo($m[1], 'BALAMT'), '.');
            $estratto->dataSaldoFinale = Testo::data(substr((string) $this->campo($m[1], 'DTASOF'), 0, 8));
        }

        return $estratto;
    }

    /** Il valore di un tag: `<TAG>valore` (SGML) o `<TAG>valore</TAG>` (XML). */
    private function campo(string $testo, string $tag): ?string
    {
        if (preg_match('/<'.$tag.'>([^<\r\n]*)/i', $testo, $m)) {
            $v = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));

            return $v !== '' ? $v : null;
        }

        return null;
    }
}
