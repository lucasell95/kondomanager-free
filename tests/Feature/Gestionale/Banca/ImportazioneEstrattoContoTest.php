<?php

/**
 * Banca → Importa: dal file dell'estratto conto ai movimenti bancari.
 *
 * Copre i formati che il condominio usa davvero (lista movimenti XLSX di Intesa Sanpaolo, con
 * righe di intestazione sopra la tabella e accrediti/addebiti separati), il CSV «all'italiana»
 * (punto e virgola, virgola decimale), il CAMT.053 e l'OFX, i doppioni e i movimenti già
 * registrati a giornale.
 */

use App\Actions\Gestionale\Movimenti\StoreIncassoRateAction;
use App\Models\Gestionale\Banca\ImportazioneBancaria;
use App\Models\Gestionale\Banca\MovimentoBancario;
use App\Models\Gestionale\Banca\ProfiloImportazione;
use App\Models\Gestionale\Banca\SaldoEstrattoConto;
use App\Models\Gestionale\ScritturaContabile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require_once __DIR__.'/BancaTestHelpers.php';

beforeEach(function () {
    Storage::fake('local');
});

/** Una «lista movimenti» come la esporta Intesa Sanpaolo: intestazioni sopra la tabella. */
function xlsxListaMovimenti(array $righeMovimenti, array $intestazioneExtra = []): UploadedFile
{
    $libro = new Spreadsheet;
    $libro->getActiveSheet()->fromArray(array_merge(
        [
            ['Lista movimenti'],
            ['Conto corrente', 'Condominio Test'],
            ['Periodo', 'dal 01/02/2026 al 28/02/2026'],
            ['Saldo contabile al 28/02/2026', '1.190,00'],
        ],
        $intestazioneExtra,
        [
            [],
            ['Data contabile', 'Data valuta', 'Descrizione', 'Dettagli', 'Accrediti', 'Addebiti', 'Divisa'],
        ],
        $righeMovimenti,
    ));

    $percorso = tempnam(sys_get_temp_dir(), 'banca').'.xlsx';
    (new Xlsx($libro))->save($percorso);

    return new UploadedFile($percorso, 'lista_movimenti.xlsx', null, null, true);
}

function righeIntesa(): array
{
    return [
        ['05/02/2026', '05/02/2026', 'Bonifico a vostro favore', 'MITT.: ROSSI MARIO BENEF.: CONDOMINIO INFO: RATA 1', '100,00', '', 'EUR'],
        ['10/02/2026', '10/02/2026', 'Commissioni', 'Canone mensile conto', '', '2,50', 'EUR'],
        ['', '', 'Totale', '', '100,00', '2,50', ''],
    ];
}

function anteprimaBanca($test, array $s, array $dati): array
{
    return $test->postJson(
        route('admin.gestionale.banca.importa.anteprima', $s['condominio']),
        ['cassa_id' => $s['cassa']->id] + $dati
    )->assertOk()->json();
}

it('legge la lista movimenti XLSX con le righe di intestazione sopra la tabella', function () {
    $s = scenarioBanca();
    $this->actingAs(adminBanca());

    $json = anteprimaBanca($this, $s, ['file' => xlsxListaMovimenti(righeIntesa())]);
    $a = $json['anteprima'];

    // La tabella è alla sesta riga (indice 5): le quattro righe sopra e quella vuota non contano.
    expect($a['tabella']['riga_intestazione'])->toBe(5)
        ->and($a['movimenti'])->toHaveCount(2)
        ->and($a['movimenti'][0]['importo'])->toBe(10000)
        ->and($a['movimenti'][0]['data_contabile'])->toBe('2026-02-05')
        ->and($a['movimenti'][0]['controparte'])->toBe('ROSSI MARIO')
        ->and($a['movimenti'][0]['descrizione'])->toContain('RATA 1')
        // L'addebito positivo nella colonna «Addebiti» è un'uscita.
        ->and($a['movimenti'][1]['importo'])->toBe(-250)
        ->and($a['saldi']['finale'])->toBe(119000)
        ->and($a['saldi']['data_finale'])->toBe('2026-02-28')
        ->and($a['conteggi']['nuovi'])->toBe(2)
        // La riga «Totale» senza data è scartata, e lo si dice.
        ->and($a['conteggi']['scartati'])->toBe(1);

    $this->post(route('admin.gestionale.banca.importa.conferma', $s['condominio']), [
        'cassa_id' => $s['cassa']->id,
        'token' => $json['token'],
        'salva_profilo' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(MovimentoBancario::where('cassa_id', $s['cassa']->id)->count())->toBe(2)
        ->and(MovimentoBancario::where('importo', -250)->first()->stato)->toBe(MovimentoBancario::STATO_DA_ABBINARE)
        ->and(SaldoEstrattoConto::where('cassa_id', $s['cassa']->id)->value('saldo'))->toBe(119000);

    // Il profilo del conto ricorda la mappatura, anche per nome di colonna.
    $profilo = ProfiloImportazione::where('cassa_id', $s['cassa']->id)->first();
    expect($profilo)->not->toBeNull()
        ->and($profilo->configurazione['colonne']['accrediti'])->toBe(4)
        ->and($profilo->configurazione['nomi_colonne']['data_contabile'])->toBe('Data contabile');

    // Il file temporaneo non resta sul disco.
    expect(Storage::disk('local')->files('banca/tmp'))->toBe([]);
});

it('con il profilo salvato ritrova la tabella anche se la banca aggiunge righe sopra', function () {
    $s = scenarioBanca();
    $this->actingAs(adminBanca());

    $primo = anteprimaBanca($this, $s, ['file' => xlsxListaMovimenti(righeIntesa())]);
    $this->post(route('admin.gestionale.banca.importa.conferma', $s['condominio']), [
        'cassa_id' => $s['cassa']->id, 'token' => $primo['token'], 'salva_profilo' => true,
    ]);

    $altroMese = xlsxListaMovimenti(
        [['03/03/2026', '03/03/2026', 'Bonifico a vostro favore', 'MITT.: BIANCHI ANNA', '100,00', '', 'EUR']],
        [['Filiale', 'Sede'], ['Intestatario', 'Condominio Test']]
    );

    $a = anteprimaBanca($this, $s, ['file' => $altroMese])['anteprima'];

    expect($a['tabella']['riga_intestazione'])->toBe(7)
        ->and($a['movimenti'])->toHaveCount(1)
        ->and($a['movimenti'][0]['importo'])->toBe(10000);
});

it('legge il CSV con punto e virgola e decimali con la virgola', function () {
    $s = scenarioBanca();
    $this->actingAs(adminBanca());

    $csv = "Data;Valuta;Operazione;Importo\n"
        ."02/02/2026;02/02/2026;\"BONIFICO DA BIANCHI ANNA; RATA FEBBRAIO\";1.200,50\n"
        ."03/02/2026;03/02/2026;IMPOSTA DI BOLLO;-8,55\n";

    $a = anteprimaBanca($this, $s, ['file' => UploadedFile::fake()->createWithContent('movimenti.csv', $csv)])['anteprima'];

    expect($a['formato'])->toBe('csv')
        ->and($a['movimenti'])->toHaveCount(2)
        ->and($a['movimenti'][0]['importo'])->toBe(120050)
        ->and($a['movimenti'][0]['descrizione'])->toBe('BONIFICO DA BIANCHI ANNA; RATA FEBBRAIO')
        ->and($a['movimenti'][0]['data_valuta'])->toBe('2026-02-02')
        ->and($a['movimenti'][1]['importo'])->toBe(-855);
});

it('lascia correggere la mappatura delle colonne nell\'anteprima', function () {
    $s = scenarioBanca();
    $this->actingAs(adminBanca());

    // Intestazioni che nessun alias conosce: la tabella non si riconosce da sola.
    $csv = "Giorno;Testo;Euro\n05/02/2026;Bonifico Rossi;100,00\n";
    $primo = anteprimaBanca($this, $s, ['file' => UploadedFile::fake()->createWithContent('strano.csv', $csv)]);

    expect($primo['anteprima']['movimenti'])->toBe([]);

    $corretto = anteprimaBanca($this, $s, [
        'token' => $primo['token'],
        'configurazione' => [
            'riga_intestazione' => 0,
            'colonne' => ['data_contabile' => 0, 'descrizione' => 1, 'importo' => 2],
            'separatore_decimale' => ',',
            'formato_data' => 'd/m/Y',
        ],
    ]);

    expect($corretto['anteprima']['movimenti'])->toHaveCount(1)
        ->and($corretto['anteprima']['movimenti'][0]['importo'])->toBe(10000);
});

it('importa un CAMT.053 con saldi, controparte e IBAN, saltando le registrazioni pending', function () {
    $s = scenarioBanca();
    $this->actingAs(adminBanca());

    $file = new UploadedFile(base_path('tests/Fixtures/banca/camt053_esempio.xml'), 'estratto.xml', 'application/xml', null, true);
    $json = anteprimaBanca($this, $s, ['file' => $file]);
    $a = $json['anteprima'];

    expect($a['formato'])->toBe('camt053')
        ->and($a['movimenti'])->toHaveCount(2)
        ->and($a['movimenti'][0]['importo'])->toBe(10000)
        ->and($a['movimenti'][0]['controparte'])->toBe('Rossi Mario')
        ->and($a['movimenti'][0]['iban_controparte'])->toBe(IBAN_ROSSI)
        ->and($a['movimenti'][1]['importo'])->toBe(-250)
        ->and($a['saldi']['iniziale'])->toBe(100000)
        ->and($a['saldi']['data_iniziale'])->toBe('2025-12-31')
        ->and($a['saldi']['finale'])->toBe(109750)
        ->and($a['iban_file'])->toBe(IBAN_CONTO_CONDOMINIO)
        ->and($a['conteggi']['scartati'])->toBe(1);

    $this->post(route('admin.gestionale.banca.importa.conferma', $s['condominio']), [
        'cassa_id' => $s['cassa']->id, 'token' => $json['token'],
    ])->assertSessionHasNoErrors();

    expect(MovimentoBancario::where('cassa_id', $s['cassa']->id)->where('riferimento', 'E2E-RATA-1')->exists())->toBeTrue()
        ->and(SaldoEstrattoConto::where('cassa_id', $s['cassa']->id)->count())->toBe(2)
        ->and(ImportazioneBancaria::first()->formato)->toBe('camt053');
});

it('importa un OFX', function () {
    $s = scenarioBanca();
    $this->actingAs(adminBanca());

    $ofx = "OFXHEADER:100\nDATA:OFXSGML\n<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS><BANKTRANLIST>"
        .'<STMTTRN><TRNTYPE>CREDIT<DTPOSTED>20260205<TRNAMT>100.00<FITID>A1<NAME>ROSSI MARIO<MEMO>Rata 1'
        .'<STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20260210<TRNAMT>-2.50<FITID>A2<NAME>COMMISSIONI'
        .'</BANKTRANLIST><LEDGERBAL><BALAMT>1190.00<DTASOF>20260228</LEDGERBAL></STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>';

    $a = anteprimaBanca($this, $s, ['file' => UploadedFile::fake()->createWithContent('estratto.ofx', $ofx)])['anteprima'];

    expect($a['formato'])->toBe('ofx')
        ->and(array_column($a['movimenti'], 'importo'))->toBe([10000, -250])
        ->and($a['saldi']['finale'])->toBe(119000);
});

it('non duplica i movimenti reimportando lo stesso periodo, ma tiene i doppioni veri dello stesso file', function () {
    $s = scenarioBanca();
    $this->actingAs(adminBanca());

    // Due commissioni identiche lo stesso giorno: sono due movimenti, non un doppione.
    $csv = "Data contabile;Descrizione;Importo\n"
        ."10/02/2026;COMMISSIONE BONIFICO;-1,00\n"
        ."10/02/2026;COMMISSIONE BONIFICO;-1,00\n"
        ."11/02/2026;BONIFICO DA VERDI;50,00\n";

    $primo = anteprimaBanca($this, $s, ['file' => UploadedFile::fake()->createWithContent('febbraio.csv', $csv)]);
    expect($primo['anteprima']['conteggi']['nuovi'])->toBe(3);

    $this->post(route('admin.gestionale.banca.importa.conferma', $s['condominio']), [
        'cassa_id' => $s['cassa']->id, 'token' => $primo['token'],
    ]);
    expect(MovimentoBancario::count())->toBe(3);

    // Stesso file di nuovo, con un movimento in più: entra solo quello nuovo.
    $csv2 = $csv."12/02/2026;IMPOSTA DI BOLLO;-8,55\n";
    $secondo = anteprimaBanca($this, $s, ['file' => UploadedFile::fake()->createWithContent('febbraio-bis.csv', $csv2)]);

    expect($secondo['anteprima']['conteggi']['duplicati'])->toBe(3)
        ->and($secondo['anteprima']['conteggi']['nuovi'])->toBe(1);

    $this->post(route('admin.gestionale.banca.importa.conferma', $s['condominio']), [
        'cassa_id' => $s['cassa']->id, 'token' => $secondo['token'],
    ]);

    expect(MovimentoBancario::count())->toBe(4)
        ->and(ImportazioneBancaria::latest('id')->first()->righe_duplicate)->toBe(3);
});

it('segnala come possibile doppione lo stesso importo dello stesso giorno con un\'altra descrizione', function () {
    $s = scenarioBanca();
    $this->actingAs(adminBanca());

    movimentoBanca($s, ['data_contabile' => '2026-02-05', 'importo' => 10000, 'descrizione' => 'BONIFICO ROSSI']);

    $csv = "Data contabile;Descrizione;Importo\n05/02/2026;Bonifico a vostro favore Rossi Mario rata 1;100,00\n";
    $json = anteprimaBanca($this, $s, ['file' => UploadedFile::fake()->createWithContent('x.csv', $csv)]);

    expect($json['anteprima']['movimenti'][0]['esito'])->toBe('possibile_duplicato');

    // Senza la spunta non entra.
    $this->post(route('admin.gestionale.banca.importa.conferma', $s['condominio']), [
        'cassa_id' => $s['cassa']->id, 'token' => $json['token'],
    ]);

    expect(MovimentoBancario::count())->toBe(1);
});

it('propone come già registrato il movimento presente a giornale e lo collega invece di duplicarlo', function () {
    $s = scenarioBanca();
    $user = adminBanca();
    $this->actingAs($user);

    // L'incasso registrato a mano dalla maschera incassi, prima dell'import.
    $incasso = app(StoreIncassoRateAction::class)->execute([
        'pagante_id' => $s['rossi']->id,
        'cassa_id' => $s['cassa']->id,
        'gestione_id' => $s['gestione']->id,
        'data_pagamento' => '2026-02-04',
        'importo_totale' => 100.00,
        'descrizione' => 'Rata 1 Rossi',
        'eccedenza' => 0,
        'dettaglio_pagamenti' => [['rata_id' => $s['quote']['Rossi Mario'][1]->id, 'importo' => 100.00]],
    ], $s['condominio'], $s['esercizio']);

    $csv = "Data contabile;Descrizione;Importo\n05/02/2026;BONIFICO A VOSTRO FAVORE ROSSI MARIO;100,00\n";
    $json = anteprimaBanca($this, $s, ['file' => UploadedFile::fake()->createWithContent('feb.csv', $csv)]);
    $riga = $json['anteprima']['movimenti'][0];

    expect($riga['esito'])->toBe('gia_registrato')
        ->and($riga['scrittura']['id'])->toBe($incasso->id);

    $this->post(route('admin.gestionale.banca.importa.conferma', $s['condominio']), [
        'cassa_id' => $s['cassa']->id,
        'token' => $json['token'],
        'collega' => [$riga['hash'] => $incasso->id],
    ])->assertSessionHasNoErrors();

    $movimento = MovimentoBancario::first();

    expect($movimento->stato)->toBe(MovimentoBancario::STATO_ABBINATO)
        ->and($movimento->tipo_abbinamento)->toBe(MovimentoBancario::ABBINAMENTO_ESISTENTE)
        ->and($movimento->scrittura_contabile_id)->toBe($incasso->id)
        ->and($incasso->fresh()->stato)->toBe('riconciliata')
        // Nessun secondo incasso: la quota resta pagata una volta sola.
        ->and(ScritturaContabile::where('tipo_movimento', 'incasso_rata')->count())->toBe(1)
        ->and($s['quote']['Rossi Mario'][1]->fresh()->importo_pagato)->toBe(10000);
});

it('rifiuta il gettone di un file caricato su un altro conto', function () {
    $s = scenarioBanca();
    $altro = scenarioBanca();
    $this->actingAs(adminBanca());

    $json = anteprimaBanca($this, $s, ['file' => UploadedFile::fake()->createWithContent('a.csv', "Data;Descrizione;Importo\n05/02/2026;X;1,00\n")]);

    // Il gettone non si apre da un altro condominio, nemmeno indicando una cassa propria.
    $this->postJson(route('admin.gestionale.banca.importa.anteprima', $altro['condominio']), [
        'cassa_id' => $altro['cassa']->id,
        'token' => $json['token'],
    ])->assertStatus(422);
});

it('le migrazioni della banca sono rieseguibili', function () {
    foreach ([
        '2026_10_19_600001_create_banca_profili_e_importazioni_tables',
        '2026_10_19_600002_create_banca_movimenti_table',
        '2026_10_19_600003_create_banca_riconoscimenti_regole_saldi_tables',
    ] as $nome) {
        $migrazione = require database_path("migrations/{$nome}.php");
        $migrazione->up();
    }

    expect(\Illuminate\Support\Facades\Schema::hasTable('banca_movimenti'))->toBeTrue();
});
