<?php

namespace App\Services\Gestionale\Banca\Lettori;

use App\Services\Gestionale\Banca\Testo;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Parser minimale di CAMT.053 (estratto conto) e CAMT.052 (lista movimenti infragiornaliera),
 * ISO 20022, in tutte le versioni (.001.02 → .001.13).
 *
 * ## Perché non genkgo/camt
 *
 * La libreria MIT `genkgo/camt` (usata dal data-importer di Firefly III) sarebbe la scelta
 * naturale, ma non è fra le dipendenze del progetto e da qui Composer non raggiunge packagist:
 * aggiungerla significherebbe cambiare `composer.lock` senza poterlo verificare. Per quello che
 * serve a un condominio — data, importo, segno, descrizione, controparte, IBAN, saldi — bastano
 * una ventina di XPath. Il parser lavora con `local-name()`, quindi è indifferente al namespace
 * e alla versione dello schema.
 *
 * ## Sicurezza
 *
 * Il file viene da fuori: si carica con `LIBXML_NONET` (niente risorse di rete) e senza
 * sostituzione delle entità, quindi un XML ostile non può leggere file locali (XXE).
 *
 * ## Cosa NON fa
 *
 * - Non valida il file contro l'XSD.
 * - Le registrazioni in stato «pending» (`Sts` = PDNG) si saltano: non sono ancora contabilizzate.
 * - Una registrazione cumulativa (un `Ntry` con più `TxDtls`, es. una distinta di bonifici) resta
 *   **un** movimento, come sull'estratto: la controparte si legge solo se il dettaglio è unico.
 */
final class LettoreCamt
{
    public function leggi(string $percorso): EstrattoLetto
    {
        $xml = file_get_contents($percorso);

        if ($xml === false || trim($xml) === '') {
            throw new RuntimeException('Il file è vuoto.');
        }

        $dom = new DOMDocument;
        $precedente = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($precedente);

        if (! $ok) {
            throw new RuntimeException('Il file non è un XML leggibile.');
        }

        $xp = new DOMXPath($dom);
        $radice = $this->primo($xp, "//*[local-name()='BkToCstmrStmt' or local-name()='BkToCstmrAcctRpt']");

        if (! $radice) {
            throw new RuntimeException('Non è un estratto conto CAMT.053 o CAMT.052 (manca BkToCstmrStmt / BkToCstmrAcctRpt).');
        }

        $formato = $radice->localName === 'BkToCstmrAcctRpt' ? 'camt052' : 'camt053';
        $estratto = new EstrattoLetto($formato);

        $rendiconti = $xp->query("./*[local-name()='Stmt' or local-name()='Rpt']", $radice);
        $progressivo = 0;

        foreach ($rendiconti as $stmt) {
            /** @var DOMElement $stmt */
            $estratto->ibanConto ??= $this->testo($xp, "./*[local-name()='Acct']/*[local-name()='Id']/*[local-name()='IBAN']", $stmt);

            $this->leggiSaldi($xp, $stmt, $estratto);

            foreach ($xp->query("./*[local-name()='Ntry']", $stmt) as $ntry) {
                $progressivo++;
                $this->leggiRegistrazione($xp, $ntry, $estratto, $progressivo);
            }
        }

        return $estratto;
    }

    private function leggiSaldi(DOMXPath $xp, DOMElement $stmt, EstrattoLetto $estratto): void
    {
        foreach ($xp->query("./*[local-name()='Bal']", $stmt) as $bal) {
            $codice = $this->testo($xp, ".//*[local-name()='Tp']//*[local-name()='Cd']", $bal);
            $importo = $this->importo($xp, $bal);
            $data = Testo::data($this->testo($xp, "./*[local-name()='Dt']/*[local-name()='Dt']", $bal)
                ?? substr((string) $this->testo($xp, "./*[local-name()='Dt']/*[local-name()='DtTm']", $bal), 0, 10));

            if ($importo === null) {
                continue;
            }

            // OPBD/PRCD = saldo di apertura del giorno → è il saldo a fine del giorno prima.
            if (in_array($codice, ['OPBD', 'PRCD'], true) && $estratto->saldoIniziale === null) {
                $estratto->saldoIniziale = $importo;
                $estratto->dataSaldoIniziale = $codice === 'OPBD' ? $data?->subDay() : $data;
            }

            if (in_array($codice, ['CLBD', 'ITBD'], true)) {
                $estratto->saldoFinale = $importo;
                $estratto->dataSaldoFinale = $data;
            }
        }
    }

    private function leggiRegistrazione(DOMXPath $xp, DOMElement $ntry, EstrattoLetto $estratto, int $progressivo): void
    {
        $stato = $this->testo($xp, "./*[local-name()='Sts']/*[local-name()='Cd']", $ntry)
            ?? $this->testo($xp, "./*[local-name()='Sts']", $ntry);

        if ($stato !== null && strtoupper(trim($stato)) === 'PDNG') {
            $estratto->scarti[] = ['riga' => $progressivo, 'motivo' => 'Registrazione non ancora contabilizzata (pending).'];

            return;
        }

        $importo = $this->importo($xp, $ntry);
        $data = Testo::data($this->dataDi($xp, $ntry, 'BookgDt'));

        if ($importo === null || $data === null) {
            $estratto->scarti[] = ['riga' => $progressivo, 'motivo' => 'Registrazione senza importo o senza data contabile.'];

            return;
        }

        $dettagli = $xp->query("./*[local-name()='NtryDtls']/*[local-name()='TxDtls']", $ntry);
        $tx = $dettagli->length === 1 ? $dettagli->item(0) : null;
        $entrata = $importo > 0;

        $parti = array_filter([
            $this->testo($xp, "./*[local-name()='AddtlNtryInf']", $ntry),
            $tx ? $this->testoUnito($xp, "./*[local-name()='RmtInf']/*[local-name()='Ustrd']", $tx) : null,
            $tx ? $this->testo($xp, "./*[local-name()='AddtlTxInf']", $tx) : null,
        ]);

        // La controparte è chi paga se l'importo entra, chi riceve se esce. Dalla versione 08
        // il nome sta sotto `Pty`, prima direttamente sotto Dbtr/Cdtr: si cercano entrambi.
        $ruolo = $entrata ? 'Dbtr' : 'Cdtr';
        $controparte = $tx ? ($this->testo($xp, "./*[local-name()='RltdPties']/*[local-name()='{$ruolo}']/*[local-name()='Nm']", $tx)
            ?? $this->testo($xp, "./*[local-name()='RltdPties']/*[local-name()='{$ruolo}']/*[local-name()='Pty']/*[local-name()='Nm']", $tx)) : null;
        $iban = $tx ? $this->testo($xp, "./*[local-name()='RltdPties']/*[local-name()='{$ruolo}Acct']/*[local-name()='Id']/*[local-name()='IBAN']", $tx) : null;
        $riferimento = ($tx ? ($this->testo($xp, "./*[local-name()='Refs']/*[local-name()='EndToEndId']", $tx)) : null);
        $riferimento = ($riferimento && strtoupper($riferimento) !== 'NOTPROVIDED') ? $riferimento
            : $this->testo($xp, "./*[local-name()='AcctSvcrRef']", $ntry);

        $descrizione = implode(' — ', array_unique(array_map('trim', $parti)));

        $estratto->movimenti[] = [
            'riga' => $progressivo,
            'data_contabile' => $data,
            'data_valuta' => Testo::data($this->dataDi($xp, $ntry, 'ValDt')),
            'importo' => $importo,
            'divisa' => $this->divisa($xp, $ntry),
            'descrizione' => mb_substr($descrizione !== '' ? $descrizione : ($controparte ?? 'Movimento'), 0, 2000),
            'controparte' => $controparte ? mb_substr($controparte, 0, 255) : Testo::estraiControparte($descrizione, $entrata),
            'iban_controparte' => Testo::normalizzaIban($iban) ?? Testo::estraiIban($descrizione, $estratto->ibanConto),
            'riferimento' => $riferimento ? mb_substr($riferimento, 0, 255) : null,
        ];
    }

    /** Importo con segno da `Amt` + `CdtDbtInd` (CRDT = entra, DBIT = esce). */
    private function importo(DOMXPath $xp, DOMElement $nodo): ?int
    {
        $valore = $this->testo($xp, "./*[local-name()='Amt']", $nodo);

        if ($valore === null) {
            return null;
        }

        $centesimi = Testo::importoInCentesimi($valore, '.');

        if ($centesimi === null) {
            return null;
        }

        $segno = strtoupper((string) $this->testo($xp, "./*[local-name()='CdtDbtInd']", $nodo));

        return $segno === 'DBIT' ? -abs($centesimi) : abs($centesimi);
    }

    private function divisa(DOMXPath $xp, DOMElement $ntry): string
    {
        $amt = $this->primo($xp, "./*[local-name()='Amt']", $ntry);
        $ccy = $amt?->getAttribute('Ccy');

        return $ccy && preg_match('/^[A-Z]{3}$/', $ccy) ? $ccy : 'EUR';
    }

    private function dataDi(DOMXPath $xp, DOMElement $ntry, string $elemento): ?string
    {
        return $this->testo($xp, "./*[local-name()='{$elemento}']/*[local-name()='Dt']", $ntry)
            ?? (($dt = $this->testo($xp, "./*[local-name()='{$elemento}']/*[local-name()='DtTm']", $ntry)) ? substr($dt, 0, 10) : null);
    }

    private function primo(DOMXPath $xp, string $query, ?DOMElement $contesto = null): ?DOMElement
    {
        $nodi = $contesto ? $xp->query($query, $contesto) : $xp->query($query);
        $nodo = $nodi !== false ? $nodi->item(0) : null;

        return $nodo instanceof DOMElement ? $nodo : null;
    }

    private function testo(DOMXPath $xp, string $query, DOMElement $contesto): ?string
    {
        $nodo = $this->primo($xp, $query, $contesto);
        $valore = $nodo ? trim($nodo->textContent) : '';

        return $valore !== '' ? $valore : null;
    }

    private function testoUnito(DOMXPath $xp, string $query, DOMElement $contesto): ?string
    {
        $parti = [];

        foreach ($xp->query($query, $contesto) as $nodo) {
            $t = trim($nodo->textContent);

            if ($t !== '') {
                $parti[] = $t;
            }
        }

        return $parti !== [] ? implode(' ', $parti) : null;
    }
}
