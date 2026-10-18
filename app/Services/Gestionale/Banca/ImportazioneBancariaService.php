<?php

namespace App\Services\Gestionale\Banca;

use App\Models\Gestionale\Banca\ImportazioneBancaria;
use App\Models\Gestionale\Banca\MovimentoBancario;
use App\Models\Gestionale\Banca\ProfiloImportazione;
use App\Models\Gestionale\Banca\SaldoEstrattoConto;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\Banca\Lettori\EstrattoLetto;
use App\Services\Gestionale\Banca\Lettori\LettoreCamt;
use App\Services\Gestionale\Banca\Lettori\LettoreOfx;
use App\Services\Gestionale\Banca\Lettori\LettoreTabellare;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Dal file dell'estratto conto ai movimenti bancari in archivio, in due tempi.
 *
 * 1. **Anteprima**: il file si conserva in un'area temporanea privata, si legge con il profilo del
 *    conto (o indovinando le colonne), e per ogni riga si dice che cosa succederà: nuova,
 *    doppione (stesso hash già importato), possibile doppione (stessa data e stesso importo già
 *    importati con un'altra descrizione, tipico di un export in un formato diverso), oppure
 *    «già registrata» a giornale con la scrittura proposta.
 * 2. **Conferma**: si creano i movimenti, si collegano quelli già registrati che l'utente ha
 *    confermato, si salvano i saldi dichiarati e il profilo. Il file temporaneo si cancella: i
 *    movimenti bastano e il file contiene dati personali di terzi.
 */
final class ImportazioneBancariaService
{
    /** Disco privato (`storage/app/private`), mai servito dal web server. */
    public const DISCO = 'local';

    private const CARTELLA = 'banca/tmp';

    public const ESTENSIONI = ['csv', 'txt', 'xlsx', 'xls', 'xml', 'ofx', 'qfx'];

    public function __construct(
        private readonly LettoreTabellare $tabellare,
        private readonly LettoreCamt $camt,
        private readonly LettoreOfx $ofx,
        private readonly ScrittureGiaRegistrate $giaRegistrate,
    ) {}

    /**
     * Conserva il file caricato nell'area temporanea e restituisce il gettone dell'anteprima.
     */
    public function conserva(UploadedFile $file, Cassa $cassa, ?int $userId): string
    {
        $this->pulisciVecchi();

        $estensione = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'csv');

        if (! in_array($estensione, self::ESTENSIONI, true)) {
            throw new RuntimeException('Formato non gestito. Sono accettati: CSV, XLSX, XLS, CAMT (XML), OFX.');
        }

        $token = (string) Str::uuid();
        $disco = Storage::disk(self::DISCO);
        $disco->putFileAs(self::CARTELLA, $file, "{$token}.{$estensione}");
        $disco->put(self::CARTELLA."/{$token}.json", json_encode([
            'cassa_id' => $cassa->id,
            'condominio_id' => $cassa->condominio_id,
            'user_id' => $userId,
            'estensione' => $estensione,
            'nome_file' => mb_substr($file->getClientOriginalName(), 0, 255),
            'hash_file' => hash_file('sha256', $file->getRealPath()),
        ]));

        return $token;
    }

    /**
     * I metadati del file temporaneo, solo se appartiene a quella cassa: un gettone di un altro
     * condominio non si apre.
     *
     * @return array<string, mixed>
     */
    public function meta(string $token, Cassa $cassa): array
    {
        if (! Str::isUuid($token)) {
            throw new RuntimeException('Caricamento non trovato: ricarica il file.');
        }

        $disco = Storage::disk(self::DISCO);
        $percorso = self::CARTELLA."/{$token}.json";

        if (! $disco->exists($percorso)) {
            throw new RuntimeException('Caricamento scaduto o non trovato: ricarica il file.');
        }

        $meta = json_decode((string) $disco->get($percorso), true) ?: [];

        if ((int) ($meta['cassa_id'] ?? 0) !== (int) $cassa->id) {
            throw new RuntimeException('Il file caricato non appartiene a questo conto: ricaricalo.');
        }

        $meta['percorso'] = $disco->path(self::CARTELLA."/{$token}.{$meta['estensione']}");

        return $meta;
    }

    public function scarta(string $token): void
    {
        if (! Str::isUuid($token)) {
            return;
        }

        $disco = Storage::disk(self::DISCO);

        foreach ($disco->files(self::CARTELLA) as $f) {
            if (str_starts_with(basename($f), $token.'.')) {
                $disco->delete($f);
            }
        }
    }

    /** I caricamenti abbandonati da più di un giorno non restano sul disco. */
    private function pulisciVecchi(): void
    {
        $disco = Storage::disk(self::DISCO);

        if (! $disco->exists(self::CARTELLA)) {
            return;
        }

        $limite = now()->subDay()->getTimestamp();

        foreach ($disco->files(self::CARTELLA) as $f) {
            if ($disco->lastModified($f) < $limite) {
                $disco->delete($f);
            }
        }
    }

    /** 'tabellare' | 'camt' | 'ofx', dall'estensione e dal contenuto. */
    public function formato(string $percorso, string $estensione): string
    {
        if (in_array($estensione, ['xlsx', 'xls'], true)) {
            return 'tabellare';
        }

        $inizio = (string) file_get_contents($percorso, false, null, 0, 4096);

        if (stripos($inizio, 'OFXHEADER') !== false || stripos($inizio, '<OFX>') !== false) {
            return 'ofx';
        }

        if (str_contains($inizio, '<?xml') || str_contains($inizio, '<Document')) {
            return 'camt';
        }

        return 'tabellare';
    }

    public function profilo(Cassa $cassa): ?ProfiloImportazione
    {
        return ProfiloImportazione::where('cassa_id', $cassa->id)->where('formato', 'tabellare')->first();
    }

    /**
     * Legge il file con il profilo del conto, o con la configurazione scelta nell'anteprima.
     *
     * @param  array<string, mixed>|null  $configurazione
     */
    public function leggi(Cassa $cassa, array $meta, ?array $configurazione = null): EstrattoLetto
    {
        $ibanConto = $cassa->contoCorrente?->iban;

        return match ($this->formato($meta['percorso'], $meta['estensione'])) {
            'camt' => $this->camt->leggi($meta['percorso']),
            'ofx' => $this->ofx->leggi($meta['percorso']),
            default => $this->tabellare->leggi(
                $meta['percorso'],
                $meta['estensione'],
                $configurazione ?? $this->profilo($cassa)?->configurazione,
                $ibanConto,
            ),
        };
    }

    /**
     * Che cosa succederà a ogni riga, senza scrivere niente.
     *
     * @return array<string, mixed>
     */
    public function anteprima(Cassa $cassa, EstrattoLetto $estratto, array $meta): array
    {
        $righe = $this->conHash($cassa, $estratto->movimenti);
        $hashEsistenti = MovimentoBancario::where('cassa_id', $cassa->id)
            ->whereIn('hash', array_column($righe, 'hash'))
            ->pluck('hash')
            ->flip();

        $proposteUsate = [];
        $risultato = [];

        foreach ($righe as $r) {
            $esito = 'nuovo';
            $scrittura = null;

            if (isset($hashEsistenti[$r['hash']])) {
                $esito = 'duplicato';
            } elseif (MovimentoBancario::where('cassa_id', $cassa->id)
                ->whereDate('data_contabile', $r['data_contabile']->toDateString())
                ->where('importo', $r['importo'])
                ->exists()) {
                $esito = 'possibile_duplicato';
            } else {
                $candidata = $this->giaRegistrate->candidate($cassa, $r['data_contabile'], $r['importo'], $proposteUsate)->first();

                if ($candidata) {
                    $esito = 'gia_registrato';
                    $proposteUsate[] = $candidata->id;
                    $scrittura = [
                        'id' => $candidata->id,
                        'protocollo' => $candidata->numero_protocollo,
                        'causale' => $candidata->causale,
                        'data' => $candidata->data_competenza,
                        'tipo' => $candidata->tipo_movimento,
                    ];
                }
            }

            $risultato[] = [
                'riga' => $r['riga'],
                'hash' => $r['hash'],
                'data_contabile' => $r['data_contabile']->toDateString(),
                'data_valuta' => $r['data_valuta']?->toDateString(),
                'importo' => $r['importo'],
                'divisa' => $r['divisa'],
                'descrizione' => $r['descrizione'],
                'controparte' => $r['controparte'],
                'iban_controparte' => $r['iban_controparte'],
                'esito' => $esito,
                'scrittura' => $scrittura,
            ];
        }

        $conteggi = array_count_values(array_column($risultato, 'esito'));

        return [
            'formato' => $estratto->formato,
            'nome_file' => $meta['nome_file'] ?? null,
            'file_gia_importato' => ImportazioneBancaria::where('cassa_id', $cassa->id)
                ->where('hash_file', $meta['hash_file'] ?? '')
                ->exists(),
            'movimenti' => $risultato,
            'scarti' => $estratto->scarti,
            'conteggi' => [
                'nuovi' => $conteggi['nuovo'] ?? 0,
                'duplicati' => $conteggi['duplicato'] ?? 0,
                'possibili_duplicati' => $conteggi['possibile_duplicato'] ?? 0,
                'gia_registrati' => $conteggi['gia_registrato'] ?? 0,
                'scartati' => count($estratto->scarti),
            ],
            'periodo' => [
                'da' => $estratto->dataDa()?->toDateString(),
                'a' => $estratto->dataA()?->toDateString(),
            ],
            'saldi' => [
                'iniziale' => $estratto->saldoIniziale,
                'data_iniziale' => $estratto->dataSaldoIniziale?->toDateString(),
                'finale' => $estratto->saldoFinale,
                'data_finale' => $estratto->dataSaldoFinale?->toDateString(),
            ],
            'iban_file' => $estratto->ibanConto,
            'iban_conto' => $cassa->contoCorrente?->iban,
            'tabella' => $estratto->tabella,
        ];
    }

    /**
     * Crea i movimenti. Le righe doppione non entrano mai; i possibili doppioni solo se l'utente
     * le ha spuntate; le «già registrate» si collegano alla scrittura se l'utente lo conferma.
     *
     * @param  array{
     *     importa_possibili_duplicati?: list<string>,
     *     collega?: array<string, int|null>,
     *     saldo_iniziale?: int|null, data_saldo_iniziale?: string|null,
     *     saldo_finale?: int|null, data_saldo_finale?: string|null,
     *     salva_profilo?: bool, configurazione?: array<string, mixed>|null,
     * }  $scelte  chiavi per hash
     */
    public function conferma(Cassa $cassa, EstrattoLetto $estratto, array $meta, array $scelte, ?int $userId): ImportazioneBancaria
    {
        $anteprima = $this->anteprima($cassa, $estratto, $meta);
        $importaPossibili = array_flip($scelte['importa_possibili_duplicati'] ?? []);
        $collega = $scelte['collega'] ?? [];

        return DB::transaction(function () use ($cassa, $estratto, $meta, $scelte, $userId, $anteprima, $importaPossibili, $collega) {
            $profilo = null;

            if (($scelte['salva_profilo'] ?? false) && $estratto->tabella !== []) {
                $config = $estratto->tabella['configurazione'];
                unset($config['da_anteprima']);

                $profilo = ProfiloImportazione::updateOrCreate(
                    ['cassa_id' => $cassa->id, 'formato' => 'tabellare'],
                    [
                        'condominio_id' => $cassa->condominio_id,
                        'nome' => 'Lista movimenti '.$cassa->nome,
                        'configurazione' => $config,
                        'created_by' => $userId,
                    ]
                );
            }

            $importazione = ImportazioneBancaria::create([
                'condominio_id' => $cassa->condominio_id,
                'cassa_id' => $cassa->id,
                'profilo_id' => $profilo?->id ?? $this->profilo($cassa)?->id,
                'formato' => $estratto->formato,
                'nome_file' => $meta['nome_file'] ?? null,
                'hash_file' => $meta['hash_file'] ?? null,
                'data_da' => $anteprima['periodo']['da'],
                'data_a' => $anteprima['periodo']['a'],
                'saldo_iniziale' => array_key_exists('saldo_iniziale', $scelte) ? $scelte['saldo_iniziale'] : $estratto->saldoIniziale,
                'saldo_finale' => array_key_exists('saldo_finale', $scelte) ? $scelte['saldo_finale'] : $estratto->saldoFinale,
                'righe_lette' => count($anteprima['movimenti']) + count($estratto->scarti),
                'created_by' => $userId,
            ]);

            $importati = 0;
            $duplicati = 0;
            $collegati = 0;
            $scrittureUsate = [];

            foreach ($anteprima['movimenti'] as $r) {
                if ($r['esito'] === 'duplicato'
                    || ($r['esito'] === 'possibile_duplicato' && ! isset($importaPossibili[$r['hash']]))) {
                    $duplicati++;

                    continue;
                }

                $movimento = MovimentoBancario::create([
                    'condominio_id' => $cassa->condominio_id,
                    'cassa_id' => $cassa->id,
                    'importazione_id' => $importazione->id,
                    'data_contabile' => $r['data_contabile'],
                    'data_valuta' => $r['data_valuta'],
                    'importo' => $r['importo'],
                    'divisa' => $r['divisa'],
                    'descrizione' => $r['descrizione'],
                    'controparte' => $r['controparte'],
                    'iban_controparte' => $r['iban_controparte'],
                    'riferimento' => $this->riferimentoDi($estratto, $r['riga']),
                    'hash' => $r['hash'],
                    'riga_origine' => $r['riga'],
                ]);
                $importati++;

                // Collegamento a una scrittura già registrata: quella proposta, o un'altra scelta
                // dall'utente, purché muova esattamente lo stesso importo sulla cassa.
                $scritturaId = array_key_exists($r['hash'], $collega)
                    ? $collega[$r['hash']]
                    : null;

                if ($scritturaId && ! in_array((int) $scritturaId, $scrittureUsate, true)
                    && $this->collegabile((int) $scritturaId, $movimento, $cassa)) {
                    app(AbbinamentoBancarioService::class)->collega($movimento, (int) $scritturaId, MovimentoBancario::ABBINAMENTO_ESISTENTE, $userId);
                    $scrittureUsate[] = (int) $scritturaId;
                    $collegati++;
                }
            }

            $importazione->update([
                'righe_importate' => $importati,
                'righe_duplicate' => $duplicati,
                'righe_collegate' => $collegati,
            ]);

            $this->salvaSaldo($cassa, $importazione, $importazione->saldo_iniziale,
                $scelte['data_saldo_iniziale'] ?? $estratto->dataSaldoIniziale?->toDateString());
            $this->salvaSaldo($cassa, $importazione, $importazione->saldo_finale,
                $scelte['data_saldo_finale'] ?? $estratto->dataSaldoFinale?->toDateString());

            return $importazione;
        });
    }

    private function collegabile(int $scritturaId, MovimentoBancario $movimento, Cassa $cassa): bool
    {
        $scrittura = ScritturaContabile::where('condominio_id', $cassa->condominio_id)->find($scritturaId);

        return $scrittura !== null
            && $scrittura->stato !== 'annullata'
            && $this->giaRegistrate->nettoSullaCassa($scrittura->id, $cassa) === $movimento->importo
            && ! MovimentoBancario::where('scrittura_contabile_id', $scritturaId)->exists();
    }

    private function salvaSaldo(Cassa $cassa, ImportazioneBancaria $importazione, ?int $saldo, ?string $data): void
    {
        if ($saldo === null || ! $data) {
            return;
        }

        SaldoEstrattoConto::imposta($cassa->condominio_id, $cassa->id, $data, $saldo, 'import', $importazione->id);
    }

    private function riferimentoDi(EstrattoLetto $estratto, ?int $riga): ?string
    {
        foreach ($estratto->movimenti as $m) {
            if ($m['riga'] === $riga) {
                return $m['riferimento'] ?? null;
            }
        }

        return null;
    }

    /**
     * Aggiunge a ogni movimento il suo hash.
     *
     * L'occorrenza conta i movimenti identici **nello stesso file**: due commissioni da 1,00 €
     * con la stessa descrizione lo stesso giorno sono due movimenti veri, e devono avere due hash
     * diversi; ricaricando lo stesso file si ritrovano gli stessi due.
     *
     * @param  list<array<string, mixed>>  $movimenti
     * @return list<array<string, mixed>>
     */
    public function conHash(Cassa $cassa, array $movimenti): array
    {
        $occorrenze = [];

        foreach ($movimenti as &$m) {
            $chiave = self::chiave($m['data_contabile']->toDateString(), (int) $m['importo'], (string) $m['descrizione']);
            $occorrenze[$chiave] = ($occorrenze[$chiave] ?? 0) + 1;
            $m['hash'] = hash('sha256', $cassa->id.'|'.$chiave.'|'.$occorrenze[$chiave]);
        }

        return $movimenti;
    }

    public static function chiave(string $data, int $importo, string $descrizione): string
    {
        return $data.'|'.$importo.'|'.Testo::normalizza($descrizione);
    }
}
