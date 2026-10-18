<?php

/**
 * Deleghe (art. 67 disp. att. c.c.) e diritto di voto con usufrutto.
 */

use App\Enums\Assemblee\StatoAssemblea;
use App\Services\Assemblee\TesteAssembleaService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/AssembleeTestHelpers.php';

beforeEach(function () {
    $this->admin = assAdmin();
});

it('blocca la delega all\'amministratore anche quando è un condòmino', function () {
    $dati = assCondominio();
    $a = $dati['anagrafiche'];
    $assemblea = assAssemblea($dati['condominio'], [
        'amministratore_anagrafica_id' => $a['Rossi Mario']->id,
        'amministratore_nome' => 'Rossi Mario',
    ]);

    // per anagrafica
    $this->actingAs($this->admin)->put(assRotta('presenze.update', $assemblea), [
        'anagrafica_id' => $a['Bianchi Anna']->id, 'modo' => 'delegato',
        'delegato_anagrafica_id' => $a['Rossi Mario']->id, 'delega_scritta' => true,
    ])->assertSessionHasErrors('delegato');

    // per nome scritto a mano
    $this->actingAs($this->admin)->put(assRotta('presenze.update', $assemblea), [
        'anagrafica_id' => $a['Verdi Luca']->id, 'modo' => 'delegato',
        'delegato_nome' => '  rossi   MARIO ', 'delega_scritta' => true,
    ])->assertSessionHasErrors('delegato');

    expect($assemblea->presenze()->where('modo', 'delegato')->count())->toBe(0);

    // a un altro condòmino sì
    $this->actingAs($this->admin)->put(assRotta('presenze.update', $assemblea), [
        'anagrafica_id' => $a['Bianchi Anna']->id, 'modo' => 'delegato',
        'delegato_anagrafica_id' => $a['Neri Paola']->id, 'delega_scritta' => true,
    ])->assertSessionHasNoErrors();

    // e conta come intervenuto, rappresentato dal delegato
    ['teste' => $teste] = app(TesteAssembleaService::class)->testeApertura($assemblea);
    $bianchi = collect($teste)->first(fn ($t) => in_array($a['Bianchi Anna']->id, $t->anagraficaIds));
    expect($bianchi->presente)->toBeTrue()
        ->and($bianchi->rappresentante)->toBe('per delega a Neri Paola');
});

it('pretende la delega scritta e non accetta la delega a sé stessi', function () {
    $dati = assCondominio();
    $a = $dati['anagrafiche'];
    $assemblea = assAssemblea($dati['condominio']);

    $this->actingAs($this->admin)->put(assRotta('presenze.update', $assemblea), [
        'anagrafica_id' => $a['Bianchi Anna']->id, 'modo' => 'delegato',
        'delegato_nome' => 'Esposito Gino', 'delega_scritta' => false,
    ])->assertSessionHasErrors('delegato');

    $this->actingAs($this->admin)->put(assRotta('presenze.update', $assemblea), [
        'anagrafica_id' => $a['Bianchi Anna']->id, 'modo' => 'delegato',
        'delegato_anagrafica_id' => $a['Bianchi Anna']->id,
    ])->assertSessionHasErrors('delegato');
});

it('oltre venti condòmini un delegato non rappresenta più di un quinto: la sesta delega su 25 è rifiutata', function () {
    $millesimi = [];
    foreach (range(1, 25) as $i) {
        $millesimi['Condomino Rossi '.$i] = '40';
    }
    $dati = assCondominio($millesimi);
    $persone = array_values($dati['anagrafiche']);
    $assemblea = assAssemblea($dati['condominio']);

    // 5 deleghe a Bianchi (esterno): 5 su 25 = un quinto esatto, 200 su 1000 — ammesse
    foreach (range(0, 4) as $i) {
        $this->actingAs($this->admin)->put(assRotta('presenze.update', $assemblea), [
            'anagrafica_id' => $persone[$i]->id, 'modo' => 'delegato', 'delegato_nome' => 'Bianchi Anna', 'delega_scritta' => true,
        ])->assertSessionHasNoErrors();
    }

    // la sesta supera il quinto
    $this->actingAs($this->admin)->put(assRotta('presenze.update', $assemblea), [
        'anagrafica_id' => $persone[5]->id, 'modo' => 'delegato', 'delegato_nome' => 'Bianchi Anna', 'delega_scritta' => true,
    ])->assertSessionHasErrors('delegato');

    // a un altro delegato va bene
    $this->actingAs($this->admin)->put(assRotta('presenze.update', $assemblea), [
        'anagrafica_id' => $persone[5]->id, 'modo' => 'delegato', 'delegato_nome' => 'Verdi Luca', 'delega_scritta' => true,
    ])->assertSessionHasNoErrors();

    expect($assemblea->presenze()->where('modo', 'delegato')->count())->toBe(6);
});

it('fino a venti condòmini il limite del quinto non si applica', function () {
    $dati = assCondominio();
    $persone = array_values($dati['anagrafiche']);
    $assemblea = assAssemblea($dati['condominio']);

    foreach (range(0, 4) as $i) {
        $this->actingAs($this->admin)->put(assRotta('presenze.update', $assemblea), [
            'anagrafica_id' => $persone[$i]->id, 'modo' => 'delegato', 'delegato_nome' => 'Bianchi Anna', 'delega_scritta' => true,
        ])->assertSessionHasNoErrors();
    }

    expect($assemblea->presenze()->where('modo', 'delegato')->count())->toBe(5);
});

it('le presenze non si registrano in bozza né per chi non è titolare', function () {
    $dati = assCondominio();
    $a = $dati['anagrafiche'];
    $bozza = assAssemblea($dati['condominio'], ['stato' => StatoAssemblea::BOZZA]);

    $this->actingAs($this->admin)->put(assRotta('presenze.update', $bozza), [
        'anagrafica_id' => $a['Rossi Mario']->id, 'modo' => 'presente',
    ])->assertSessionHasErrors('stato');

    $estraneo = assNuovaAnagrafica('Esposito Gino', 99);
    $convocata = assAssemblea($dati['condominio']);
    $this->actingAs($this->admin)->put(assRotta('presenze.update', $convocata), [
        'anagrafica_id' => $estraneo->id, 'modo' => 'presente',
    ])->assertSessionHasErrors('anagrafica_id');
});

it('con usufrutto vota l\'usufruttuario sull\'ordinaria e il nudo proprietario sulla straordinaria', function () {
    $dati = assCondominio(['Rossi Mario' => '600', 'Bianchi Anna' => '400']);
    $immobile = $dati['immobili']['Rossi Mario'];
    $usufruttuario = assNuovaAnagrafica('Verdi Luca', 7);
    $dati['condominio']->anagrafiche()->attach($usufruttuario->id);

    DB::table('anagrafica_immobile')->where('immobile_id', $immobile->id)->update(['tipologia' => 'nuda_proprietario']);
    DB::table('anagrafica_immobile')->insert([
        'immobile_id' => $immobile->id, 'anagrafica_id' => $usufruttuario->id, 'tipologia' => 'usufruttuario',
        'quota' => 100, 'data_inizio' => '2020-01-01', 'attivo' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $assemblea = assAssemblea($dati['condominio']);
    $ordinaria = $assemblea->punti()->create(['titolo' => 'Preventivo', 'materia' => 'rendiconto_preventivo', 'ordine' => 1]);
    $lavori = $assemblea->punti()->create(['titolo' => 'Rifacimento del tetto', 'materia' => 'riparazioni_straordinarie', 'ordine' => 2]);

    $servizio = app(TesteAssembleaService::class);
    $votantiOrdinaria = collect($servizio->testePunto($assemblea, $ordinaria)['teste'])->pluck('anagraficaIds')->flatten()->all();
    $votantiLavori = collect($servizio->testePunto($assemblea, $lavori)['teste'])->pluck('anagraficaIds')->flatten()->all();

    expect($votantiOrdinaria)->toContain($usufruttuario->id)->not->toContain($dati['anagrafiche']['Rossi Mario']->id)
        ->and($votantiLavori)->toContain($dati['anagrafiche']['Rossi Mario']->id)->not->toContain($usufruttuario->id);

    // Avviso a entrambi (art. 67 c. 7 disp. att.)
    $this->actingAs($this->admin)->post(assRotta('destinatari.genera', $assemblea))->assertSessionHasNoErrors();
    expect($assemblea->destinatari()->count())->toBe(3);
});

it('chi possiede due unità vale una testa sola con la somma dei millesimi', function () {
    $dati = assCondominio(['Rossi Mario' => '300', 'Bianchi Anna' => '400', 'Verdi Luca' => '300']);
    // Rossi diventa proprietario anche dell'unità di Verdi
    DB::table('anagrafica_immobile')->where('immobile_id', $dati['immobili']['Verdi Luca']->id)
        ->update(['anagrafica_id' => $dati['anagrafiche']['Rossi Mario']->id]);

    $assemblea = assAssemblea($dati['condominio']);
    ['teste' => $teste, 'totale' => $totale] = app(TesteAssembleaService::class)->testeApertura($assemblea);

    expect($teste)->toHaveCount(2)
        ->and($totale)->toBe(100000000)
        ->and(collect($teste)->firstWhere('chiave', (string) $dati['anagrafiche']['Rossi Mario']->id)->valore)->toBe(60000000);
});
