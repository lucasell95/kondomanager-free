<?php

namespace App\Services\Gestionale\Banca\Lettori;

use App\Services\Gestionale\Banca\Testo;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Legge la «lista movimenti» esportata dall'home banking in CSV, XLSX o XLS.
 *
 * ## Il problema
 *
 * Nessuna banca esporta nello stesso modo. Quella del condominio (Intesa Sanpaolo) mette alcune
 * righe di intestazione sopra la tabella (conto, periodo, saldo), poi colonne come «Data
 * contabile», «Data valuta», «Descrizione», «Dettagli», «Accrediti», «Addebiti», «Divisa». Altre
 * banche hanno un «Importo» unico con segno, il punto e virgola come separatore e la virgola per
 * i decimali.
 *
 * ## La soluzione: ruoli delle colonne
 *
 * Come il data-importer di Firefly III (`config/csv.php`, `import_roles`): ogni colonna riceve un
 * ruolo (data contabile, importo, accrediti, addebiti, descrizione…). La prima volta i ruoli si
 * indovinano dai nomi delle colonne; l'utente li corregge nell'anteprima e la scelta si salva come
 * profilo del conto. Il profilo ricorda anche i **nomi** delle colonne, così se la banca aggiunge
 * una riga sopra la tabella o sposta una colonna l'intestazione si ritrova lo stesso.
 */
final class LettoreTabellare
{
    /** Ruoli assegnabili a una colonna, con l'etichetta per l'interfaccia. */
    public const RUOLI = [
        'data_contabile' => 'Data contabile',
        'data_valuta' => 'Data valuta',
        'descrizione' => 'Descrizione',
        'dettagli' => 'Dettagli',
        'importo' => 'Importo (con segno)',
        'accrediti' => 'Accrediti (entrate)',
        'addebiti' => 'Addebiti (uscite)',
        'divisa' => 'Divisa',
        'controparte' => 'Controparte',
        'iban_controparte' => 'IBAN controparte',
        'riferimento' => 'Riferimento (CRO/TRN)',
    ];

    /** Nomi di colonna noti, normalizzati con `Testo::normalizza`, per ruolo. */
    private const ALIAS = [
        'data_contabile' => ['data contabile', 'data', 'data operazione', 'data registrazione', 'data contabilizzazione', 'contabilizzazione', 'data movimento', 'booking date', 'data op'],
        'data_valuta' => ['data valuta', 'valuta data', 'value date', 'data val'],
        'descrizione' => ['descrizione', 'operazione', 'causale', 'descrizione operazione', 'tipo operazione', 'description', 'causale abi'],
        'dettagli' => ['dettagli', 'dettaglio', 'descrizione estesa', 'causale estesa', 'note', 'informazioni', 'descrizione aggiuntiva'],
        'importo' => ['importo', 'importo eur', 'importo euro', 'amount', 'importo in euro'],
        'accrediti' => ['accrediti', 'accredito', 'entrate', 'avere', 'credit', 'importo accredito'],
        'addebiti' => ['addebiti', 'addebito', 'uscite', 'dare', 'debit', 'importo addebito'],
        'divisa' => ['divisa', 'currency', 'valuta divisa'],
        'controparte' => ['controparte', 'beneficiario', 'ordinante', 'nome controparte', 'beneficiario ordinante'],
        'iban_controparte' => ['iban', 'iban controparte', 'iban beneficiario', 'iban ordinante'],
        'riferimento' => ['riferimento', 'cro', 'trn', 'id operazione', 'numero operazione'],
    ];

    /** Quante righe in cima si guardano per trovare l'intestazione. */
    private const RIGHE_RICERCA_INTESTAZIONE = 40;

    /** @return array<string, mixed> */
    public static function configurazionePredefinita(): array
    {
        return [
            'riga_intestazione' => null,
            'colonne' => array_fill_keys(array_keys(self::RUOLI), null),
            'nomi_colonne' => [],
            'separatore_decimale' => ',',
            'formato_data' => 'auto',
            'inverti_segno' => false,
        ];
    }

    /**
     * Le righe grezze del file: liste di celle, nell'ordine del file.
     *
     * @return list<list<mixed>>
     */
    public function righe(string $percorso, string $estensione): array
    {
        return $estensione === 'csv' || $estensione === 'txt'
            ? $this->righeCsv($percorso)
            : $this->righeFoglio($percorso);
    }

    /**
     * Legge le righe con la configurazione data (o con quella indovinata se manca).
     *
     * @param  array<string, mixed>|null  $configurazione  mappatura salvata o scelta nell'anteprima
     */
    public function leggi(string $percorso, string $estensione, ?array $configurazione = null, ?string $ibanConto = null): EstrattoLetto
    {
        $righe = $this->righe($percorso, $estensione);

        if ($righe === []) {
            throw new RuntimeException('Il file non contiene righe.');
        }

        $config = $this->risolviConfigurazione($righe, $configurazione);
        $estratto = new EstrattoLetto($estensione === 'txt' ? 'csv' : $estensione);

        $estratto->tabella = [
            'riga_intestazione' => $config['riga_intestazione'],
            'intestazioni' => $config['riga_intestazione'] !== null
                ? array_map(fn ($c) => trim((string) $c), $righe[$config['riga_intestazione']] ?? [])
                : [],
            'righe_anteprima' => array_map(
                fn ($r) => array_map(fn ($c) => is_scalar($c) ? (string) $c : '', $r),
                array_slice($righe, 0, min(count($righe), ($config['riga_intestazione'] ?? 0) + 12))
            ),
            'configurazione' => $config,
            'ruoli' => self::RUOLI,
        ];

        if ($config['riga_intestazione'] === null || $config['colonne']['data_contabile'] === null
            || ($config['colonne']['importo'] === null && $config['colonne']['accrediti'] === null && $config['colonne']['addebiti'] === null)) {
            $estratto->scarti[] = [
                'riga' => null,
                'motivo' => 'Non ho riconosciuto la tabella dei movimenti: indica la riga di intestazione e quali colonne fanno da data e da importo.',
            ];

            return $estratto;
        }

        $col = $config['colonne'];
        $dec = $config['separatore_decimale'] ?: ',';

        foreach ($righe as $i => $riga) {
            if ($i <= $config['riga_intestazione']) {
                continue;
            }

            if ($this->rigaVuota($riga)) {
                continue;
            }

            $numeroRiga = $i + 1;
            $data = Testo::data($this->cella($riga, $col['data_contabile']), $config['formato_data'] ?? 'auto');

            if ($data === null) {
                // Righe di totale o di saldo in fondo alla tabella: si guardano per il saldo e
                // si scartano dicendolo.
                $this->cercaSaldo($riga, $estratto, $dec);
                $estratto->scarti[] = ['riga' => $numeroRiga, 'motivo' => 'Senza una data contabile leggibile: non è un movimento.'];

                continue;
            }

            $importo = $this->importoDellaRiga($riga, $col, $dec);

            if ($importo === null) {
                $estratto->scarti[] = ['riga' => $numeroRiga, 'motivo' => 'Importo mancante o non leggibile.'];

                continue;
            }

            if ($importo === 0) {
                $estratto->scarti[] = ['riga' => $numeroRiga, 'motivo' => 'Importo zero.'];

                continue;
            }

            if (! empty($config['inverti_segno'])) {
                $importo = -$importo;
            }

            $descrizione = trim((string) $this->cella($riga, $col['descrizione']));
            $dettagli = trim((string) $this->cella($riga, $col['dettagli']));

            if ($dettagli !== '' && ! str_contains($descrizione, $dettagli)) {
                $descrizione = trim($descrizione.' — '.$dettagli, ' —');
            }

            $controparte = trim((string) $this->cella($riga, $col['controparte'])) ?: Testo::estraiControparte($descrizione, $importo > 0);
            $iban = Testo::normalizzaIban((string) $this->cella($riga, $col['iban_controparte']))
                ?? Testo::estraiIban($descrizione, $ibanConto);
            $divisa = strtoupper(trim((string) $this->cella($riga, $col['divisa'])));

            $estratto->movimenti[] = [
                'riga' => $numeroRiga,
                'data_contabile' => $data,
                'data_valuta' => Testo::data($this->cella($riga, $col['data_valuta']), $config['formato_data'] ?? 'auto'),
                'importo' => $importo,
                'divisa' => preg_match('/^[A-Z]{3}$/', $divisa) ? $divisa : 'EUR',
                'descrizione' => mb_substr($descrizione, 0, 2000),
                'controparte' => $controparte ? mb_substr($controparte, 0, 255) : null,
                'iban_controparte' => $iban,
                'riferimento' => ($r = trim((string) $this->cella($riga, $col['riferimento']))) !== '' ? mb_substr($r, 0, 255) : null,
            ];
        }

        // Il saldo dichiarato sopra la tabella («Saldo contabile al …»).
        for ($i = 0; $i < $config['riga_intestazione']; $i++) {
            $this->cercaSaldo($righe[$i], $estratto, $dec);
        }

        if ($estratto->saldoFinale !== null && $estratto->dataSaldoFinale === null) {
            $estratto->dataSaldoFinale = $estratto->dataA();
        }

        if ($estratto->saldoIniziale !== null && $estratto->dataSaldoIniziale === null) {
            $estratto->dataSaldoIniziale = $estratto->dataDa()?->subDay();
        }

        return $estratto;
    }

    /**
     * La configurazione da usare: quella data se ritrova la sua intestazione, altrimenti quella
     * indovinata dai nomi delle colonne.
     *
     * @param  list<list<mixed>>  $righe
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>
     */
    public function risolviConfigurazione(array $righe, ?array $data): array
    {
        $config = array_replace(self::configurazionePredefinita(), $data ?? []);
        $config['colonne'] = array_replace(self::configurazionePredefinita()['colonne'], $config['colonne'] ?? []);

        // 1. Profilo con i nomi delle colonne: si cerca la riga che li contiene tutti.
        $nomi = array_filter($config['nomi_colonne'] ?? []);

        if ($nomi !== [] && ($data['da_anteprima'] ?? false) !== true) {
            foreach (array_slice($righe, 0, self::RIGHE_RICERCA_INTESTAZIONE, true) as $i => $riga) {
                $normalizzate = array_map(fn ($c) => Testo::normalizza(is_scalar($c) ? (string) $c : ''), $riga);
                $indici = [];

                foreach ($nomi as $ruolo => $nome) {
                    $pos = array_search(Testo::normalizza($nome), $normalizzate, true);

                    if ($pos === false) {
                        continue 2;
                    }

                    $indici[$ruolo] = $pos;
                }

                $config['riga_intestazione'] = $i;
                $config['colonne'] = array_replace(array_fill_keys(array_keys(self::RUOLI), null), $indici);

                return $config;
            }
        }

        // 2. Riga di intestazione indicata (dall'anteprima): si tengono le colonne indicate.
        if ($config['riga_intestazione'] !== null && array_filter($config['colonne'], fn ($v) => $v !== null) !== []) {
            $config['nomi_colonne'] = $this->nomiDelleColonne($righe, $config);

            return $config;
        }

        // 3. Niente di utilizzabile: si indovina.
        [$rigaIntestazione, $colonne] = $this->indovina($righe, $config['riga_intestazione']);
        $config['riga_intestazione'] = $rigaIntestazione;
        $config['colonne'] = array_replace(array_fill_keys(array_keys(self::RUOLI), null), $colonne);
        $config['nomi_colonne'] = $this->nomiDelleColonne($righe, $config);

        return $config;
    }

    /**
     * La riga d'intestazione più probabile e i ruoli delle sue colonne.
     *
     * Vince la riga con più nomi riconosciuti, purché ci siano una data e un importo (o
     * accrediti/addebiti): la riga «Conto corrente: …» sopra la tabella non ha né l'una né l'altro.
     *
     * @param  list<list<mixed>>  $righe
     * @return array{0: int|null, 1: array<string, int>}
     */
    public function indovina(array $righe, ?int $rigaForzata = null): array
    {
        $migliore = [null, [], 0];
        $candidate = $rigaForzata !== null
            ? [$rigaForzata => $righe[$rigaForzata] ?? []]
            : array_slice($righe, 0, self::RIGHE_RICERCA_INTESTAZIONE, true);

        foreach ($candidate as $i => $riga) {
            $colonne = [];

            foreach ($riga as $j => $cella) {
                $nome = Testo::normalizza(is_scalar($cella) ? (string) $cella : '');

                if ($nome === '') {
                    continue;
                }

                foreach (self::ALIAS as $ruolo => $alias) {
                    if (! isset($colonne[$ruolo]) && in_array($nome, $alias, true)) {
                        $colonne[$ruolo] = $j;

                        continue 2;
                    }
                }

                // «Valuta» da sola è ambigua: in alcuni export è la data valuta, in altri la divisa.
                if ($nome === 'valuta') {
                    $campione = $righe[$i + 1][$j] ?? null;
                    $ruolo = Testo::data($campione) !== null ? 'data_valuta' : 'divisa';
                    $colonne[$ruolo] ??= $j;
                }
            }

            $haData = isset($colonne['data_contabile']) || isset($colonne['data_valuta']);
            $haImporto = isset($colonne['importo']) || isset($colonne['accrediti']) || isset($colonne['addebiti']);

            if ($haData && $haImporto && count($colonne) > $migliore[2]) {
                // Con la sola data valuta, è quella a fare da data contabile.
                if (! isset($colonne['data_contabile'])) {
                    $colonne['data_contabile'] = $colonne['data_valuta'];
                    unset($colonne['data_valuta']);
                }

                $migliore = [$i, $colonne, count($colonne)];
            }
        }

        return [$migliore[0], $migliore[1]];
    }

    /** @return array<string, string> */
    private function nomiDelleColonne(array $righe, array $config): array
    {
        if ($config['riga_intestazione'] === null) {
            return [];
        }

        $intestazione = $righe[$config['riga_intestazione']] ?? [];
        $nomi = [];

        foreach ($config['colonne'] as $ruolo => $indice) {
            if ($indice !== null && isset($intestazione[$indice]) && trim((string) $intestazione[$indice]) !== '') {
                $nomi[$ruolo] = trim((string) $intestazione[$indice]);
            }
        }

        return $nomi;
    }

    private function importoDellaRiga(array $riga, array $col, string $dec): ?int
    {
        if ($col['importo'] !== null) {
            return Testo::importoInCentesimi($this->cella($riga, $col['importo']), $dec);
        }

        $accredito = $col['accrediti'] !== null ? Testo::importoInCentesimi($this->cella($riga, $col['accrediti']), $dec) : null;
        $addebito = $col['addebiti'] !== null ? Testo::importoInCentesimi($this->cella($riga, $col['addebiti']), $dec) : null;

        if ($accredito === null && $addebito === null) {
            return null;
        }

        // L'addebito è un'uscita comunque sia scritto: «150,00» o «-150,00».
        return abs($accredito ?? 0) - abs($addebito ?? 0);
    }

    /** Cerca in una riga fuori tabella un «Saldo … : importo». */
    private function cercaSaldo(array $riga, EstrattoLetto $estratto, string $dec): void
    {
        $celle = array_values(array_map(fn ($c) => is_scalar($c) ? (string) $c : '', $riga));

        foreach ($celle as $k => $testo) {
            $norm = Testo::normalizza($testo);

            if (! str_contains($norm, 'saldo') || str_contains($norm, 'disponibile')) {
                continue;
            }

            $valore = null;

            if (preg_match('/[:=]\s*([-+]?[\d.,]+\s*-?)\s*(eur|€)?\s*$/iu', $testo, $m)) {
                $valore = Testo::importoInCentesimi($m[1], $dec);
            }

            for ($j = $k + 1; $valore === null && $j < count($celle); $j++) {
                if (trim($celle[$j]) !== '') {
                    $valore = Testo::importoInCentesimi($riga[array_keys($riga)[$j]] ?? $celle[$j], $dec);
                }
            }

            if ($valore === null) {
                continue;
            }

            $data = preg_match('/(\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{2,4})/', $testo, $d) ? Testo::data($d[1]) : null;

            if (str_contains($norm, 'iniziale')) {
                $estratto->saldoIniziale = $valore;
                $estratto->dataSaldoIniziale = $data?->subDay();
            } else {
                $estratto->saldoFinale = $valore;
                $estratto->dataSaldoFinale = $data;
            }
        }
    }

    private function cella(array $riga, ?int $indice): mixed
    {
        if ($indice === null) {
            return null;
        }

        $valore = array_values($riga)[$indice] ?? null;

        return is_string($valore) ? trim($valore) : $valore;
    }

    private function rigaVuota(array $riga): bool
    {
        foreach ($riga as $c) {
            if ($c !== null && trim((string) $c) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * CSV letto in proprio e non con PhpSpreadsheet: serve indovinare il separatore (`;` nelle
     * esportazioni italiane) e la codifica (Windows-1252 negli export più vecchi).
     *
     * @return list<list<string>>
     */
    private function righeCsv(string $percorso): array
    {
        $contenuto = file_get_contents($percorso);

        if ($contenuto === false) {
            throw new RuntimeException('Non riesco a leggere il file.');
        }

        // BOM UTF-8 in testa e codifica: quello che non è UTF-8 valido viene da Windows.
        $contenuto = preg_replace('/^\xEF\xBB\xBF/', '', $contenuto) ?? $contenuto;

        if (! mb_check_encoding($contenuto, 'UTF-8')) {
            $contenuto = mb_convert_encoding($contenuto, 'UTF-8', 'Windows-1252');
        }

        $separatore = $this->indovinaSeparatore($contenuto);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contenuto);
        rewind($stream);

        $righe = [];

        while (($riga = fgetcsv($stream, null, $separatore, '"', '')) !== false) {
            if ($riga === [null]) {
                $righe[] = [];

                continue;
            }

            $righe[] = array_map(fn ($c) => (string) $c, $riga);
        }

        fclose($stream);

        return $righe;
    }

    /** Il separatore che divide le righe nello stesso numero di campi, più spesso. */
    private function indovinaSeparatore(string $contenuto): string
    {
        $linee = array_slice(array_filter(preg_split('/\r\n|\r|\n/', $contenuto) ?: [], fn ($l) => trim($l) !== ''), 0, 30);
        $migliore = [';', -1];

        foreach ([';', ',', "\t", '|'] as $sep) {
            $conteggi = array_map(fn ($l) => substr_count($l, $sep), $linee);
            $frequenze = array_count_values(array_filter($conteggi));

            if ($frequenze === []) {
                continue;
            }

            arsort($frequenze);
            $punteggio = reset($frequenze) * 100 + (int) key($frequenze);

            if ($punteggio > $migliore[1]) {
                $migliore = [$sep, $punteggio];
            }
        }

        return $migliore[0];
    }

    /** @return list<list<mixed>> */
    private function righeFoglio(string $percorso): array
    {
        try {
            $reader = IOFactory::createReaderForFile($percorso);
            $reader->setReadDataOnly(true);
            $libro = $reader->load($percorso);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Il file non si apre: potrebbe essere danneggiato, protetto da password o in un formato diverso da quello che l\'estensione dichiara.',
                previous: $e
            );
        }

        $righe = [];

        foreach ($libro->getAllSheets() as $foglio) {
            $righe = array_values($foglio->toArray(null, true, false, false));

            // Il primo foglio con dei dati: le liste movimenti ne hanno uno solo.
            if (array_filter($righe, fn ($r) => ! $this->rigaVuota($r)) !== []) {
                break;
            }
        }

        $libro->disconnectWorksheets();

        return array_map(fn ($r) => array_values($r), $righe);
    }
}
