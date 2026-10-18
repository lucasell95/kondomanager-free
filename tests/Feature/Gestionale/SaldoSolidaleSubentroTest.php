<?php

/**
 * Art. 63 c. 4 disp. att. c.c.: il pregresso di un'unità venduta non passa da solo all'acquirente.
 *
 * Chi subentra nei diritti di un condòmino è obbligato in solido con lui solo per i contributi
 * dell'anno in corso e di quello precedente; i debiti più vecchi restano del venditore. Il saldo
 * solidale dell'unità non dice a quali anni risale, quindi il riparto automatico — che prendeva
 * tutti i titolari attivi — metteva l'intero pregresso sull'acquirente, o lo divideva per quote fra
 * venditore e acquirente se il venditore era ancora censito.
 *
 * Il passaggio si riconosce dalle date del rapporto: il venditore ha una `data_fine`, l'acquirente
 * no. In quel caso il riparto automatico si ferma e chiede quello manuale, che resta libero.
 */

use App\Actions\PianoRate\GenerateSaldiAction;
use App\Exceptions\Gestionale\SaldoSolidaleSenzaTitolareException;
use App\Exceptions\Gestionale\SaldoSolidaleSubentroException;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Saldo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @param  array  $titolari  [['chiave' => 'rossi', 'ruolo' => 'proprietario', 'dal' => '2020-01-01', 'al' => null], …]
 */
function subentroScenario(array $titolari, int $saldoCents = 120000): object
{
    static $seq = 0;
    $seq++;

    $condominio = Condominio::create([
        'nome' => "Condominio Subentro {$seq}", 'uuid' => (string) Str::uuid(),
        'indirizzo' => 'Via Roma 1', 'citta' => 'Milano', 'cap' => '20100', 'provincia' => 'MI',
    ]);

    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id, 'nome' => '2026/27',
        'data_inizio' => '2026-06-01', 'data_fine' => '2027-05-31', 'stato' => 'aperto',
    ]);

    $gestione = Gestione::create([
        'condominio_id' => $condominio->id, 'nome' => "Ordinaria {$seq}",
        'data_inizio' => '2026-06-01', 'tipo' => 'ordinaria', 'saldo_applicato' => false,
    ]);
    $gestione->esercizi()->attach($esercizio->id, ['attiva' => true]);

    $immobile = Immobile::forceCreate([
        'condominio_id' => $condominio->id, 'nome' => "Int {$seq}",
        'descrizione' => 'Appartamento', 'interno' => (string) $seq,
    ]);

    $persone = [];
    foreach ($titolari as $t) {
        $seq++;
        $anagrafica = Anagrafica::forceCreate([
            'nome' => ucfirst($t['chiave']),
            'email' => "subentro{$seq}@test.it",
            'indirizzo' => 'Via Verdi 1',
            'codice_fiscale' => 'TSTTST'.str_pad((string) $seq, 10, '0', STR_PAD_LEFT),
        ]);

        DB::table('anagrafica_immobile')->insert([
            'anagrafica_id' => $anagrafica->id, 'immobile_id' => $immobile->id,
            'tipologia' => $t['ruolo'] ?? 'proprietario', 'quota' => 100.0, 'attivo' => true,
            'data_inizio' => $t['dal'], 'data_fine' => $t['al'] ?? null,
        ]);

        $persone[$t['chiave']] = $anagrafica;
    }

    $saldo = Saldo::forceCreate([
        'condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id, 'immobile_id' => $immobile->id,
        'anagrafica_id' => null, 'saldo_iniziale' => $saldoCents,
        'descrizione' => 'Pregresso solidale', 'is_applicato' => false,
    ]);

    $piano = PianoRate::create([
        'condominio_id' => $condominio->id, 'gestione_id' => $gestione->id,
        'nome' => "Piano {$seq}", 'numero_rate' => 2, 'metodo_distribuzione' => 'rata_zero',
        'stato' => 'bozza', 'tipo' => 'ordinario',
    ]);

    return (object) compact('condominio', 'esercizio', 'gestione', 'immobile', 'saldo', 'piano', 'persone');
}

test('con un passaggio di titolarità il pregresso non va da solo all\'acquirente', function () {
    $s = subentroScenario([
        ['chiave' => 'rossi', 'dal' => '2015-03-01', 'al' => '2026-07-15'],
        ['chiave' => 'bianchi', 'dal' => '2026-07-16'],
    ]);

    $genera = fn () => app(GenerateSaldiAction::class)->execute($s->piano, $s->gestione);

    expect($genera)->toThrow(SaldoSolidaleSubentroException::class, 'art. 63 c. 4');

    // È la stessa famiglia del «senza titolare»: chi già la intercetta continua a farlo.
    expect($genera)->toThrow(SaldoSolidaleSenzaTitolareException::class);
});

test('l\'anteprima lo dice, con i nomi e le date del passaggio', function () {
    $s = subentroScenario([
        ['chiave' => 'rossi', 'dal' => '2015-03-01', 'al' => '2026-07-15'],
        ['chiave' => 'bianchi', 'dal' => '2026-07-16'],
    ]);

    $anteprima = app(GenerateSaldiAction::class)->anteprimaSolidale($s->saldo, $s->gestione);

    expect($anteprima['risolvibile'])->toBeFalse()
        ->and($anteprima['subentro'])->toBeTrue()
        ->and($anteprima['motivo'])
            ->toContain('Rossi fino al 15/07/2026')
            ->toContain('Bianchi dal 16/07/2026')
            ->toContain('anno in corso e di quello precedente');
});

test('il riparto manuale resta libero: venditore e acquirente si dividono il pregresso', function () {
    $s = subentroScenario([
        ['chiave' => 'rossi', 'dal' => '2015-03-01', 'al' => '2026-07-15'],
        ['chiave' => 'bianchi', 'dal' => '2026-07-16'],
    ], saldoCents: 120000);

    $d = app(GenerateSaldiAction::class)->execute($s->piano, $s->gestione, [[
        'saldo_id' => $s->saldo->id,
        'ripartizioni' => [
            ['anagrafica_id' => $s->persone['rossi']->id, 'importo' => 80000],
            ['anagrafica_id' => $s->persone['bianchi']->id, 'importo' => 40000],
        ],
    ]]);

    expect($d[$s->persone['rossi']->id][$s->immobile->id]['importo'])->toBe(80000)
        ->and($d[$s->persone['bianchi']->id][$s->immobile->id]['importo'])->toBe(40000);
});

test('senza passaggio di titolarità l\'automatico funziona come prima', function () {
    $s = subentroScenario([
        ['chiave' => 'verdi', 'dal' => '2010-01-01'],
    ]);

    $d = app(GenerateSaldiAction::class)->execute($s->piano, $s->gestione);

    expect($d[$s->persone['verdi']->id][$s->immobile->id]['importo'])->toBe(120000);
});

test('se l\'acquirente non è ancora censito il pregresso resta al venditore', function () {
    $s = subentroScenario([
        ['chiave' => 'rossi', 'dal' => '2015-03-01', 'al' => '2026-07-15'],
    ]);

    $d = app(GenerateSaldiAction::class)->execute($s->piano, $s->gestione);

    expect($d[$s->persone['rossi']->id][$s->immobile->id]['importo'])->toBe(120000);
});

test('l\'inquilino che cambia non è un subentro nei diritti reali', function () {
    $s = subentroScenario([
        ['chiave' => 'verdi', 'dal' => '2010-01-01'],
        ['chiave' => 'neri', 'ruolo' => 'inquilino', 'dal' => '2020-01-01', 'al' => '2026-06-30'],
        ['chiave' => 'gialli', 'ruolo' => 'inquilino', 'dal' => '2026-07-01'],
    ]);

    $d = app(GenerateSaldiAction::class)->execute($s->piano, $s->gestione);

    expect($d[$s->persone['verdi']->id][$s->immobile->id]['importo'])->toBe(120000);
});
