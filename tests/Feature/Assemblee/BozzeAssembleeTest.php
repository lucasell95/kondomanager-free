<?php

/**
 * Bozze di testo: senza IA il modulo usa i modelli fissi; con l'IA i segnaposto {{F:chiave}} li
 * sostituisce il programma, e una risposta che cambia i punti dell'ordine del giorno è scartata.
 */

use App\Contracts\IA\GeneratoreTesto;
use App\Contracts\IA\RisultatoGenerazione;
use App\Enums\Assemblee\StatoAssemblea;
use App\Services\Assemblee\BozzeAssembleaService;

require_once __DIR__.'/AssembleeTestHelpers.php';

/** Generatore finto: restituisce i dati che gli si danno e ricorda cosa ha ricevuto. */
function assGeneratoreFinto(array $dati, bool $ok = true): GeneratoreTesto
{
    return new class($dati, $ok) implements GeneratoreTesto
    {
        public array $ricevuto = [];

        public function __construct(private array $dati, private bool $ok) {}

        public function disponibile(): bool
        {
            return true;
        }

        public function genera(string $tipoDocumento, array $contesto, array $schema, ?string $istruzioni = null): RisultatoGenerazione
        {
            $this->ricevuto = compact('tipoDocumento', 'contesto', 'schema', 'istruzioni');

            return $this->ok
                ? new RisultatoGenerazione(ok: true, dati: $this->dati, avvisi: ['Avviso della validazione'], modello: 'modello-di-prova')
                : RisultatoGenerazione::nonDisponibile('servizio irraggiungibile');
        }
    };
}

beforeEach(function () {
    $this->admin = assAdmin();
    $this->dati = assCondominio();
    $this->assemblea = assAssemblea($this->dati['condominio'], ['stato' => StatoAssemblea::BOZZA]);
    $this->assemblea->punti()->create(['titolo' => 'rendiconto', 'materia' => 'rendiconto_preventivo', 'ordine' => 1]);
    $this->assemblea->punti()->create(['titolo' => 'comunicazioni', 'materia' => 'informativa', 'ordine' => 2]);
});

it('senza IA configurata la bozza viene dai modelli fissi', function () {
    $this->actingAs($this->admin)
        ->postJson(assRotta('bozza', $this->assemblea, ['tipo' => 'convocazione']))
        ->assertOk()
        ->assertJsonPath('fonte', 'modello')
        ->assertJsonCount(2, 'punti')
        ->assertJsonPath('punti.0.titolo', 'rendiconto');

    $this->actingAs($this->admin)
        ->postJson(assRotta('bozza', $this->assemblea, ['tipo' => 'verbale']))
        ->assertOk()
        ->assertJsonPath('fonte', 'modello')
        ->assertJsonPath('punti.1.testo_delibera', 'L\'assemblea prende atto delle comunicazioni.');

    expect($this->assemblea->fresh()->bozze_ia['verbale']['fonte'])->toBe('modello');
});

it('con l\'IA sostituisce i segnaposto e segnala quelli sconosciuti', function () {
    $finto = assGeneratoreFinto(['punti' => [
        ['n' => 1, 'titolo' => 'Approvazione del rendiconto', 'testo' => 'Assemblea del {{F:ass.data_prima}} presso {{F:ass.luogo_prima}}. Importo {{F:inventato}}.'],
        ['n' => 2, 'titolo' => 'Comunicazioni', 'testo' => 'Comunicazioni dell\'amministratore.'],
    ], 'note_per_revisore' => ['Verificare il punto 1']]);
    app()->instance(GeneratoreTesto::class, $finto);

    $bozza = app(BozzeAssembleaService::class)->convocazione($this->assemblea);

    expect($bozza['fonte'])->toBe('ia')
        ->and($bozza['modello'])->toBe('modello-di-prova')
        ->and($bozza['punti'][0]['testo'])->toContain('20 novembre 2026')
        ->and($bozza['punti'][0]['testo'])->toContain('Androne del condominio')
        ->and($bozza['punti'][0]['testo'])->toContain('[[DA COMPLETARE: inventato]]')
        ->and(implode(' ', $bozza['avvisi']))->toContain('inventato')
        ->and($bozza['avvisi'])->toContain('Avviso della validazione')
        ->and($bozza['note_per_revisore'])->toBe(['Verificare il punto 1']);

    // Il contesto concordato con l'area IA, senza dati personali superflui
    $contesto = $finto->ricevuto['contesto'];
    expect($finto->ricevuto['tipoDocumento'])->toBe('convocazione')
        ->and($contesto['condominio_id'])->toBe($this->assemblea->condominio_id)
        ->and($contesto['punti'][0]['n'])->toBe(1)
        ->and($contesto['fatti']['ass.data_prima']['valore'])->toContain('2026')
        ->and($finto->ricevuto['schema']['required'])->toContain('note_per_revisore')
        ->and(json_encode($contesto))->not->toContain('TSTTST')
        ->and(json_encode($contesto))->not->toContain('@test.it');

    // La bozza non si salva da sola
    expect($this->assemblea->punti()->first()->titolo)->toBe('rendiconto');
});

it('scarta la bozza IA che cambia il numero dei punti e ripiega sul modello', function () {
    app()->instance(GeneratoreTesto::class, assGeneratoreFinto(['punti' => [
        ['n' => 1, 'discussione' => 'Sintesi', 'testo_delibera' => 'Delibera'],
    ], 'note_per_revisore' => []]));

    $bozza = app(BozzeAssembleaService::class)->verbale($this->assemblea);

    expect($bozza['fonte'])->toBe('modello')
        ->and(implode(' ', $bozza['avvisi']))->toContain('numero o l\'ordine dei punti');
});

it('se il servizio IA non risponde usa il modello e lo dice', function () {
    app()->instance(GeneratoreTesto::class, assGeneratoreFinto([], ok: false));

    $bozza = app(BozzeAssembleaService::class)->convocazione($this->assemblea);

    expect($bozza['fonte'])->toBe('modello')
        ->and($bozza['avvisi'][0])->toContain('servizio irraggiungibile');
});

it('mostra in anteprima il contesto senza le chiavi di servizio', function () {
    $this->actingAs($this->admin)
        ->getJson(assRotta('bozza.contesto', $this->assemblea, ['tipo' => 'verbale']))
        ->assertOk()
        ->assertJsonMissingPath('contesto.condominio_id')
        ->assertJsonPath('contesto.punti.0.n', 1)
        ->assertJsonStructure(['contesto' => ['punti', 'fatti', 'norme_ammesse', 'note_seduta']]);
});
