<?php

/**
 * Il motore dei quorum (art. 1136 c.c.) sui casi limite, con sei condòmini.
 *
 * I valori sono in millesimi con cinque decimali, come `quote_tabella.valore`: i casi «esattamente»
 * (500/1000, 1/3, 2/3) si provano accanto al centomillesimo in meno, perché è lì che un
 * arrotondamento sbaglierebbe.
 */

use App\Enums\Assemblee\Convocazione;
use App\Enums\Assemblee\MateriaDelibera;
use App\Services\Assemblee\QuorumService;
use App\Services\Assemblee\TestaAssemblea;
use App\Support\Assemblee\Millesimi;

/** @param list<array{0:string,1:bool,2:?string,3?:bool}> $righe  [millesimi, presente, voto, conflitto] */
function assTeste(array $righe): array
{
    $teste = [];
    foreach ($righe as $i => $r) {
        $teste[] = new TestaAssemblea(
            chiave: (string) ($i + 1),
            etichetta: ['Rossi', 'Bianchi', 'Verdi', 'Neri', 'Gialli', 'Ferrari'][$i] ?? 'Testa '.($i + 1),
            valore: Millesimi::daDecimale($r[0]),
            presente: $r[1],
            voto: $r[2] ?? null,
            conflitto: $r[3] ?? false,
        );
    }

    return $teste;
}

function assMille(): int
{
    return Millesimi::daDecimale('1000');
}

it('converte i millesimi in interi senza perdere decimali', function () {
    expect(Millesimi::daDecimale('166.50000'))->toBe(16650000)
        ->and(Millesimi::daDecimale(166.5))->toBe(16650000)
        ->and(Millesimi::daDecimale(200))->toBe(20000000)
        ->and(Millesimi::daDecimale('0.00001'))->toBe(1)
        ->and(Millesimi::formatta(16650000))->toBe('166,500')
        ->and(Millesimi::formatta(Millesimi::daDecimale('333.33334')))->toBe('333,33334');
});

it('prima convocazione: costituita con 4 teste su 6 ed esattamente 2/3 del valore', function () {
    // 2/3 di 1000 = 666,666…: 666,66667 basta (3 × m ≥ 2 × T), 666,66666 no.
    $si = assTeste([['200', true, null], ['200', true, null], ['166.66667', true, null], ['100', true, null], ['166.66666', false, null], ['166.66667', false, null]]);
    $no = assTeste([['200', true, null], ['200', true, null], ['166.66666', true, null], ['100', true, null], ['166.66667', false, null], ['166.66667', false, null]]);

    $q = new QuorumService;
    expect($q->costitutivo($si, assMille(), Convocazione::PRIMA)['raggiunto'])->toBeTrue()
        ->and($q->costitutivo($no, assMille(), Convocazione::PRIMA)['raggiunto'])->toBeFalse();
});

it('prima convocazione: con metà esatta delle teste non è costituita, anche con molti millesimi', function () {
    // 3 su 6 non è «la maggioranza dei partecipanti»: ne servono 4.
    $teste = assTeste([['300', true, null], ['250', true, null], ['200', true, null], ['100', false, null], ['80', false, null], ['70', false, null]]);

    $esito = (new QuorumService)->costitutivo($teste, assMille(), Convocazione::PRIMA);

    expect($esito['raggiunto'])->toBeFalse()
        ->and($esito['requisiti'][0]['raggiunto'])->toBeFalse()
        ->and($esito['requisiti'][1]['raggiunto'])->toBeTrue()
        ->and($esito['spiegazione'])->toContain('3 × 2 = 6 ≤ 1 × 6 = 6');
});

it('seconda convocazione: costituita con un terzo esatto delle teste e del valore', function () {
    // 2 teste su 6 = 1/3; 333,33334 ≥ 1/3 di 1000, 333,33333 no.
    $si = assTeste([['200', true, null], ['133.33334', true, null], ['166.66666', false, null], ['166.66667', false, null], ['166.66667', false, null], ['166.66666', false, null]]);
    $no = assTeste([['200', true, null], ['133.33333', true, null], ['166.66667', false, null], ['166.66667', false, null], ['166.66667', false, null], ['166.66666', false, null]]);

    $q = new QuorumService;
    expect($q->costitutivo($si, assMille(), Convocazione::SECONDA)['raggiunto'])->toBeTrue()
        ->and($q->costitutivo($no, assMille(), Convocazione::SECONDA)['raggiunto'])->toBeFalse();

    $una = assTeste([['600', true, null], ['80', false, null], ['80', false, null], ['80', false, null], ['80', false, null], ['80', false, null]]);
    expect($q->costitutivo($una, assMille(), Convocazione::SECONDA)['raggiunto'])->toBeFalse();
});

it('prima convocazione ordinaria: esattamente 500/1000 approva, un centomillesimo in meno no', function () {
    $base = fn (string $u4) => assTeste([
        ['180', true, 'favorevole'], ['170', true, 'favorevole'], ['160', true, 'contrario'],
        [$u4, true, 'favorevole'], ['175', true, 'contrario'], ['165', false, null],
    ]);

    $q = new QuorumService;
    $si = $q->deliberativo($base('150'), assMille(), MateriaDelibera::ORDINARIA, Convocazione::PRIMA);
    $no = $q->deliberativo($base('149.99999'), assMille(), MateriaDelibera::ORDINARIA, Convocazione::PRIMA);

    expect($si['esito'])->toBe('approvata')
        ->and($si['favorevoli']['valore_testo'])->toBe('500,000')
        ->and($si['favorevoli']['teste'])->toBe(3)
        ->and($si['intervenuti']['teste'])->toBe(5)
        ->and($no['esito'])->toBe('respinta');
});

it('seconda convocazione: un terzo del valore basta per l\'ordinaria ma non per la nomina dell\'amministratore', function () {
    $teste = assTeste([
        ['200', true, 'favorevole'], ['133.33334', true, 'favorevole'], ['100', true, 'contrario'],
        ['200', false, null], ['200', false, null], ['166.66666', false, null],
    ]);

    $q = new QuorumService;
    expect($q->deliberativo($teste, assMille(), MateriaDelibera::ORDINARIA, Convocazione::SECONDA)['esito'])->toBe('approvata')
        ->and($q->deliberativo($teste, assMille(), MateriaDelibera::NOMINA_AMMINISTRATORE, Convocazione::SECONDA)['esito'])->toBe('respinta');
});

it('innovazioni: servono i due terzi esatti del valore', function () {
    $con = fn (string $v) => assTeste([
        ['300', true, 'favorevole'], ['200', true, 'favorevole'], [$v, true, 'favorevole'],
        ['100', true, 'contrario'], ['100', true, 'contrario'], ['133.33333', false, null],
    ]);
    // 300 + 200 + 166,66667 = 666,66667 su 1000 → 3 × 666,66667 ≥ 2000
    $q = new QuorumService;
    expect($q->deliberativo($con('166.66667'), assMille(), MateriaDelibera::INNOVAZIONI, Convocazione::PRIMA)['esito'])->toBe('approvata')
        ->and($q->deliberativo($con('166.66666'), assMille(), MateriaDelibera::INNOVAZIONI, Convocazione::PRIMA)['esito'])->toBe('respinta');
});

it('gli astenuti contano fra gli intervenuti: tre favorevoli su sei presenti non sono la maggioranza', function () {
    $teste = assTeste([
        ['200', true, 'favorevole'], ['200', true, 'favorevole'], ['200', true, 'favorevole'],
        ['150', true, 'astenuto'], ['150', true, 'astenuto'], ['100', true, null],
    ]);

    $esito = (new QuorumService)->deliberativo($teste, assMille(), MateriaDelibera::ORDINARIA, Convocazione::PRIMA);

    expect($esito['esito'])->toBe('respinta')
        ->and($esito['astenuti']['teste'])->toBe(2)
        ->and($esito['non_espressi']['teste'])->toBe(1)
        ->and($esito['requisiti'][0]['raggiunto'])->toBeFalse();
});

it('individua il condòmino decisivo', function () {
    // Rossi (180) è decisivo: senza di lui i favorevoli scendono a 320.
    $teste = assTeste([
        ['180', true, 'favorevole'], ['170', true, 'favorevole'], ['160', true, 'favorevole'],
        ['150', true, 'contrario'], ['175', true, 'contrario'], ['165', false, null],
    ]);

    $esito = (new QuorumService)->deliberativo($teste, assMille(), MateriaDelibera::NOMINA_AMMINISTRATORE, Convocazione::PRIMA);

    expect($esito['esito'])->toBe('approvata')
        ->and($esito['decisivi'])->toContain('Rossi', 'Bianchi', 'Verdi')
        ->and($esito['spiegazione'])->toContain('Voto decisivo');
});

it('voto in conflitto d\'interessi: si conta, ma la prova di resistenza lo segnala se è determinante', function () {
    $teste = assTeste([
        ['180', true, 'favorevole', true], ['170', true, 'favorevole'], ['160', true, 'favorevole'],
        ['150', true, 'contrario'], ['175', true, 'contrario'], ['165', true, 'contrario'],
    ]);

    $esito = (new QuorumService)->deliberativo($teste, assMille(), MateriaDelibera::ORDINARIA, Convocazione::SECONDA);

    // 3 favorevoli su 6 intervenuti: non è la maggioranza, respinta comunque.
    expect($esito['esito'])->toBe('respinta')
        ->and($esito['conflitto']['determinante'])->toBeFalse();

    // Neri esce prima del voto: 3 favorevoli su 5 intervenuti, e Rossi (in conflitto) diventa decisivo.
    $teste[3] = $teste[3]->con(presente: false);
    $esito = (new QuorumService)->deliberativo($teste, assMille(), MateriaDelibera::ORDINARIA, Convocazione::SECONDA);

    expect($esito['esito'])->toBe('approvata')
        ->and($esito['conflitto']['determinante'])->toBeTrue()
        ->and($esito['conflitto']['esito_senza'])->toBe('respinta')
        ->and($esito['conflitto']['teste'])->toBe(['Rossi'])
        ->and($esito['spiegazione'])->toContain('Prova di resistenza');
});

it('unanimità: basta un assente per non approvare', function () {
    $tutti = assTeste(array_map(fn ($v) => [$v, true, 'favorevole'], ['180', '170', '160', '150', '175', '165']));
    $q = new QuorumService;
    expect($q->deliberativo($tutti, assMille(), MateriaDelibera::UNANIMITA, Convocazione::PRIMA)['esito'])->toBe('approvata');

    $tutti[5] = $tutti[5]->con(presente: false);
    expect($q->deliberativo($tutti, assMille(), MateriaDelibera::UNANIMITA, Convocazione::PRIMA)['esito'])->toBe('respinta');
});

it('destinazione d\'uso: quattro quinti dei partecipanti e del valore', function () {
    $teste = assTeste([
        ['180', true, 'favorevole'], ['170', true, 'favorevole'], ['160', true, 'favorevole'],
        ['150', true, 'favorevole'], ['175', true, 'favorevole'], ['165', true, 'contrario'],
    ]);
    // 5 su 6 teste (5×5=25 ≥ 4×6=24) e 835/1000 (≥ 800)
    expect((new QuorumService)->deliberativo($teste, assMille(), MateriaDelibera::DESTINAZIONE_USO, Convocazione::PRIMA)['esito'])->toBe('approvata');

    $teste[4] = $teste[4]->con(voto: 'contrario');
    expect((new QuorumService)->deliberativo($teste, assMille(), MateriaDelibera::DESTINAZIONE_USO, Convocazione::PRIMA)['esito'])->toBe('respinta');
});

it('un punto informativo non si vota e senza voti espressi l\'esito è «non votata»', function () {
    $teste = assTeste(array_map(fn ($v) => [$v, true, null], ['180', '170', '160', '150', '175', '165']));
    $q = new QuorumService;

    expect($q->deliberativo($teste, assMille(), MateriaDelibera::INFORMATIVA, Convocazione::PRIMA)['esito'])->toBe('senza_votazione')
        ->and($q->deliberativo($teste, assMille(), MateriaDelibera::ORDINARIA, Convocazione::PRIMA)['esito'])->toBe('non_votata');
});
