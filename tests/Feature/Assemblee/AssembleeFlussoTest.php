<?php

/**
 * Il flusso completo dal gestionale, con sei condòmini: creazione, ordine del giorno, convocazione,
 * presenze, votazioni, PDF, registro dei verbali e archiviazione in Documenti.
 */

use App\Enums\Assemblee\StatoAssemblea;
use App\Models\Assemblee\Assemblea;
use App\Models\CategoriaEvento;
use App\Models\Documento;
use App\Models\Evento;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/AssembleeTestHelpers.php';

beforeEach(function () {
    Storage::fake('local');
    $this->admin = assAdmin();
    $this->dati = assCondominio();
    $this->condominio = $this->dati['condominio'];
});

it('crea l\'assemblea, fotografa unità e millesimi della proprietà generale e la mostra', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.gestionale.assemblee.store', $this->condominio), [
            'tipo' => 'ordinaria',
            'prima_convocazione_at' => '2026-11-20T21:00',
            'prima_luogo' => 'Androne',
            'seconda_convocazione_at' => '2026-11-23T21:00',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $assemblea = Assemblea::firstOrFail();

    expect($assemblea->stato)->toBe(StatoAssemblea::BOZZA)
        ->and($assemblea->tabella_id)->toBe($this->dati['tabella_id'])
        ->and($assemblea->unita()->count())->toBe(6)
        ->and((int) $assemblea->unita()->sum('valore'))->toBe(100000000);

    $this->actingAs($this->admin)
        ->get(route('admin.gestionale.assemblee.show', [$this->condominio, $assemblea]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('gestionale/assemblee/AssembleaShow')
            ->where('totaleMillesimi', '1.000,000')
            ->has('persone', 6)
            ->where('costitutivo.prima.teste_totali', 6)
        );

    $this->actingAs($this->admin)
        ->get(route('admin.gestionale.assemblee.index', $this->condominio))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('gestionale/assemblee/AssembleeList')->has('assemblee', 1));
});

it('rifiuta una seconda convocazione nello stesso giorno della prima o oltre dieci giorni', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.gestionale.assemblee.store', $this->condominio), [
            'tipo' => 'ordinaria',
            'prima_convocazione_at' => '2026-11-20T18:00',
            'seconda_convocazione_at' => '2026-11-20T21:00',
        ])
        ->assertSessionHasErrors('seconda_convocazione_at');

    $this->actingAs($this->admin)
        ->post(route('admin.gestionale.assemblee.store', $this->condominio), [
            'tipo' => 'ordinaria',
            'prima_convocazione_at' => '2026-11-20T18:00',
            'seconda_convocazione_at' => '2026-12-02T21:00',
        ])
        ->assertSessionHasErrors('seconda_convocazione_at');

    expect(Assemblea::count())->toBe(0);
});

it('svolge l\'assemblea con sei condòmini: quorum, voti punto per punto, esito ed archiviazione del verbale', function () {
    $a = $this->dati['anagrafiche'];
    $assemblea = assAssemblea($this->condominio, ['stato' => StatoAssemblea::BOZZA]);

    $this->actingAs($this->admin)->post(assRotta('punti.store', $assemblea), [
        'titolo' => 'Approvazione del rendiconto 2025/26', 'materia' => 'rendiconto_preventivo',
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(assRotta('punti.store', $assemblea), [
        'titolo' => 'Nomina dell\'amministratore', 'materia' => 'nomina_amministratore',
    ])->assertSessionHasNoErrors();

    // Destinatari dai titolari, ricezione registrata
    $this->actingAs($this->admin)->post(assRotta('destinatari.genera', $assemblea))->assertSessionHasNoErrors();
    expect($assemblea->destinatari()->count())->toBe(6);

    $this->actingAs($this->admin)->put(assRotta('stato', $assemblea), ['stato' => 'convocata'])->assertSessionHasNoErrors();
    expect($assemblea->fresh()->stato)->toBe(StatoAssemblea::CONVOCATA);

    // L'evento «Assemblea» in agenda
    $evento = Evento::find($assemblea->fresh()->evento_id);
    expect($evento)->not->toBeNull()
        ->and($evento->categoria->name)->toBe('Assemblea')
        ->and($evento->condomini()->pluck('condomini.id')->all())->toBe([$this->condominio->id]);

    // Presenti in 5 (Ferrari assente): 835 millesimi
    foreach (['Rossi Mario', 'Bianchi Anna', 'Verdi Luca', 'Neri Paola', 'Gialli Carlo'] as $nome) {
        $this->actingAs($this->admin)->put(assRotta('presenze.update', $assemblea), [
            'anagrafica_id' => $a[$nome]->id, 'modo' => 'presente',
        ])->assertSessionHasNoErrors();
    }

    [$rendiconto, $nomina] = $assemblea->punti()->get()->all();
    $chiave = fn (string $nome) => (string) $a[$nome]->id;

    // Rendiconto: favorevoli Rossi, Bianchi, Neri = 500 esatti, 3 su 5 → approvato
    $this->actingAs($this->admin)->put(assRotta('punti.voti', $assemblea, ['punto' => $rendiconto->id]), ['voti' => [
        ['chiave_testa' => $chiave('Rossi Mario'), 'presente' => true, 'voto' => 'favorevole'],
        ['chiave_testa' => $chiave('Bianchi Anna'), 'presente' => true, 'voto' => 'favorevole'],
        ['chiave_testa' => $chiave('Neri Paola'), 'presente' => true, 'voto' => 'favorevole'],
        ['chiave_testa' => $chiave('Verdi Luca'), 'presente' => true, 'voto' => 'contrario'],
        ['chiave_testa' => $chiave('Gialli Carlo'), 'presente' => true, 'voto' => 'astenuto'],
    ]])->assertSessionHasNoErrors();

    // Nomina: Gialli esce prima del voto; favorevoli Rossi e Bianchi (350) → non raggiunge 500
    $this->actingAs($this->admin)->put(assRotta('punti.voti', $assemblea, ['punto' => $nomina->id]), ['voti' => [
        ['chiave_testa' => $chiave('Rossi Mario'), 'presente' => true, 'voto' => 'favorevole'],
        ['chiave_testa' => $chiave('Bianchi Anna'), 'presente' => true, 'voto' => 'favorevole'],
        ['chiave_testa' => $chiave('Gialli Carlo'), 'presente' => false, 'voto' => 'favorevole'],
    ]])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->get(route('admin.gestionale.assemblee.show', [$this->condominio, $assemblea]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('costitutivo.prima.raggiunto', true)
            ->where('costitutivo.prima.teste_presenti', 5)
            ->where('costitutivo.prima.valore_presente_testo', '835,000')
            ->where('punti.0.votazione.esito.esito', 'approvata')
            ->where('punti.0.votazione.esito.favorevoli.valore_testo', '500,000')
            ->where('punti.1.votazione.esito.esito', 'respinta')
            ->where('punti.1.votazione.esito.intervenuti.teste', 4)
            ->has('assenti', 1)
        );

    // Anteprima in tempo reale: cambiando un voto l'esito si ricalcola senza salvare
    $this->actingAs($this->admin)->postJson(assRotta('punti.anteprima', $assemblea, ['punto' => $nomina->id]), ['voti' => [
        ['chiave_testa' => $chiave('Rossi Mario'), 'presente' => true, 'voto' => 'favorevole'],
        ['chiave_testa' => $chiave('Bianchi Anna'), 'presente' => true, 'voto' => 'favorevole'],
        ['chiave_testa' => $chiave('Verdi Luca'), 'presente' => true, 'voto' => 'favorevole'],
    ]])->assertOk()->assertJsonPath('esito.esito', 'approvata');
    expect($nomina->voti()->where('chiave_testa', $chiave('Verdi Luca'))->exists())->toBeFalse();

    // Una chiave inventata non entra nel calcolo
    $this->actingAs($this->admin)->put(assRotta('punti.voti', $assemblea, ['punto' => $nomina->id]), ['voti' => [
        ['chiave_testa' => '999999', 'presente' => true, 'voto' => 'favorevole'],
    ]])->assertSessionHasErrors('voti');

    // Dichiarazione a verbale testuale
    $this->actingAs($this->admin)->post(assRotta('dichiarazioni.store', $assemblea), [
        'assemblea_punto_id' => $rendiconto->id, 'anagrafica_id' => $a['Verdi Luca']->id,
        'dichiarante' => 'Verdi Luca', 'testo' => 'Chiedo che si metta a verbale il mio dissenso sulla voce pulizie.',
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->put(assRotta('stato', $assemblea), ['stato' => 'svolta'])->assertSessionHasNoErrors();

    // Registrazione del verbale: numero 1/2026, archiviato in Documenti → Verbali
    $this->actingAs($this->admin)->post(assRotta('verbale.registra', $assemblea), ['pubblica' => true])->assertSessionHasNoErrors();

    $assemblea->refresh();
    expect($assemblea->stato)->toBe(StatoAssemblea::VERBALIZZATA)
        ->and($assemblea->numeroRegistro())->toBe('1/2026');

    $documento = Documento::findOrFail($assemblea->documento_verbale_id);
    expect($documento->categoria->name)->toBe('Verbali')
        ->and($documento->name)->toContain('Verbale 1/2026')
        ->and($documento->documentable_type)->toBe(Assemblea::class)
        ->and((bool) $documento->is_published)->toBeTrue()
        ->and($documento->condomini()->pluck('condomini.id')->all())->toBe([$this->condominio->id]);
    Storage::disk('local')->assertExists($documento->path);

    // Dopo la registrazione non si modifica più
    $this->actingAs($this->admin)->put(assRotta('punti.voti', $assemblea, ['punto' => $rendiconto->id]), ['voti' => [
        ['chiave_testa' => $chiave('Rossi Mario'), 'presente' => true, 'voto' => 'contrario'],
    ]])->assertSessionHasErrors('stato');

    // Comunicazione del verbale all'assente, con termine di impugnazione
    $this->actingAs($this->admin)->put(assRotta('comunicazione-verbale', $assemblea), [
        'anagrafica_id' => $a['Ferrari Elena']->id, 'verbale_mezzo' => 'raccomandata',
        'verbale_data_invio' => '2026-11-25', 'verbale_data_ricezione' => '2026-11-27',
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->get(route('admin.gestionale.assemblee.show', [$this->condominio, $assemblea]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('assenti.0.nome', 'Ferrari Elena')
            ->where('assenti.0.scadenza_impugnazione', '2026-12-28')
        );

    // Una seconda assemblea dello stesso anno prende il numero 2
    $seconda = assAssemblea($this->condominio, ['stato' => StatoAssemblea::SVOLTA, 'prima_convocazione_at' => '2026-12-10 21:00:00', 'seconda_convocazione_at' => null]);
    $this->actingAs($this->admin)->post(assRotta('verbale.registra', $seconda), [
        'file' => UploadedFile::fake()->create('verbale-firmato.pdf', 20, 'application/pdf'),
    ])->assertSessionHasNoErrors();
    expect($seconda->fresh()->numeroRegistro())->toBe('2/2026');
});

it('genera i PDF di avviso, foglio presenze, verbale, lettera agli assenti e registro', function () {
    $assemblea = assAssemblea($this->condominio);
    $assemblea->punti()->create(['titolo' => 'Approvazione del preventivo', 'materia' => 'rendiconto_preventivo', 'ordine' => 1]);
    $assemblea->punti()->create(['titolo' => 'Comunicazioni dell\'amministratore', 'materia' => 'informativa', 'ordine' => 2]);
    $assemblea->destinatari()->create(['nominativo' => 'Rossi Mario', 'mezzo' => 'mano', 'data_ricezione' => '2026-11-10']);
    assPresenti($assemblea, $this->dati['anagrafiche'], ['Rossi Mario', 'Bianchi Anna', 'Verdi Luca', 'Neri Paola']);

    foreach (['avviso', 'presenze', 'verbale', 'assenti'] as $documento) {
        $risposta = $this->actingAs($this->admin)->get(assRotta('stampa', $assemblea, ['documento' => $documento]));
        $risposta->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect(substr($risposta->getContent(), 0, 4))->toBe('%PDF');
    }

    $this->actingAs($this->admin)
        ->get(route('admin.gestionale.assemblee.registro.stampa', $this->condominio))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it('riporta in bozza un\'assemblea convocata e toglie l\'evento dall\'agenda', function () {
    $assemblea = assAssemblea($this->condominio, ['stato' => StatoAssemblea::BOZZA]);
    $assemblea->punti()->create(['titolo' => 'Varie', 'materia' => 'informativa', 'ordine' => 1]);

    $this->actingAs($this->admin)->put(assRotta('stato', $assemblea), ['stato' => 'convocata'])->assertSessionHasNoErrors();
    $eventoId = $assemblea->fresh()->evento_id;
    expect($eventoId)->not->toBeNull();

    $this->actingAs($this->admin)->put(assRotta('stato', $assemblea), ['stato' => 'bozza'])->assertSessionHasNoErrors();
    expect(Evento::find($eventoId))->toBeNull()
        ->and($assemblea->fresh()->evento_id)->toBeNull();

    // Da bozza non si salta a verbalizzata, e senza punti non si convoca
    $this->actingAs($this->admin)->put(assRotta('stato', $assemblea), ['stato' => 'svolta'])->assertSessionHasErrors('stato');
});

it('non apre l\'assemblea di un altro condominio', function () {
    $altro = assCondominio(['Rossi Mario' => '1000']);
    $estranea = assAssemblea($altro['condominio']);

    $this->actingAs($this->admin)
        ->get(route('admin.gestionale.assemblee.show', [$this->condominio, $estranea]))
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->get(route('admin.gestionale.assemblee.stampa', ['condominio' => $this->condominio->id, 'assemblea' => $estranea->id, 'documento' => 'verbale']))
        ->assertNotFound();
});
