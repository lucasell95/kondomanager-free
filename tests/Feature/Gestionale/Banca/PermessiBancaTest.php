<?php

/**
 * Le rotte della banca hanno le stesse protezioni del resto del gestionale: accesso al pannello
 * amministratore e perimetro del condominio dell'indirizzo.
 */

use App\Models\Gestionale\Banca\MovimentoBancario;
use App\Models\Gestionale\Banca\RegolaBancaria;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/BancaTestHelpers.php';

it('un utente senza accesso al pannello non entra in nessuna pagina della banca', function () {
    $s = scenarioBanca();
    $m = movimentoBanca($s);

    // Il permesso esiste (come in produzione): la pagina d'errore 403 lo interroga.
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $this->actingAs(User::factory()->create());

    $this->get(route('admin.gestionale.banca.importa', $s['condominio']))->assertForbidden();
    $this->get(route('admin.gestionale.banca.movimenti.index', $s['condominio']))->assertForbidden();
    $this->get(route('admin.gestionale.banca.riconciliazione', $s['condominio']))->assertForbidden();
    $this->getJson(route('admin.gestionale.banca.movimenti.proposte', [$s['condominio'], $m->id]))->assertForbidden();
    $this->post(route('admin.gestionale.banca.movimenti.ignora', [$s['condominio'], $m->id]))->assertForbidden();

    expect($m->fresh()->stato)->toBe(MovimentoBancario::STATO_DA_ABBINARE);
});

it('un ospite viene mandato al login', function () {
    $s = scenarioBanca();

    $this->get(route('admin.gestionale.banca.movimenti.index', $s['condominio']))->assertRedirect();
    $this->post(route('admin.gestionale.banca.importa.anteprima', $s['condominio']), ['cassa_id' => $s['cassa']->id])->assertRedirect();
});

it('il movimento di un altro condominio non si apre né si abbina dall\'indirizzo del proprio', function () {
    $mio = scenarioBanca();
    $altrui = scenarioBanca();
    $this->actingAs(adminBanca());

    $suo = movimentoBanca($altrui, ['importo' => 10000]);

    $this->getJson(route('admin.gestionale.banca.movimenti.proposte', [$mio['condominio'], $suo->id]))->assertNotFound();

    $this->post(route('admin.gestionale.banca.movimenti.incasso', [$mio['condominio'], $suo->id]), [
        'pagante_id' => $mio['rossi']->id,
        'righe' => [['quota_id' => $mio['quote']['Rossi Mario'][1]->id, 'importo' => 10000]],
    ])->assertNotFound();

    $this->post(route('admin.gestionale.banca.movimenti.ignora', [$mio['condominio'], $suo->id]))->assertNotFound();
    $this->post(route('admin.gestionale.banca.movimenti.scollega', [$mio['condominio'], $suo->id]))->assertNotFound();

    expect($suo->fresh()->stato)->toBe(MovimentoBancario::STATO_DA_ABBINARE);
});

it('non si incassa con le rate o il pagante di un altro condominio', function () {
    $mio = scenarioBanca();
    $altrui = scenarioBanca();
    $this->actingAs(adminBanca());
    $m = movimentoBanca($mio, ['importo' => 10000]);

    $this->post(route('admin.gestionale.banca.movimenti.incasso', [$mio['condominio'], $m->id]), [
        'pagante_id' => $altrui['rossi']->id,
        'righe' => [['quota_id' => $altrui['quote']['Rossi Mario'][1]->id, 'importo' => 10000]],
    ])->assertSessionHasErrors(['pagante_id', 'righe.0.quota_id']);

    expect($m->fresh()->stato)->toBe(MovimentoBancario::STATO_DA_ABBINARE);
});

it('non si carica un estratto conto su una cassa di un altro condominio, né su un fondo', function () {
    Storage::fake('local');
    $mio = scenarioBanca();
    $altrui = scenarioBanca();
    $this->actingAs(adminBanca());

    $file = fn () => UploadedFile::fake()->createWithContent('a.csv', "Data;Descrizione;Importo\n05/02/2026;X;1,00\n");

    $this->postJson(route('admin.gestionale.banca.importa.anteprima', $mio['condominio']), [
        'cassa_id' => $altrui['cassa']->id, 'file' => $file(),
    ])->assertJsonValidationErrors('cassa_id');

    $contoFondo = \Illuminate\Support\Facades\DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $mio['condominio']->id, 'ruolo' => null, 'codice' => 'FONDO-CASSA',
        'nome' => 'Fondo riserva', 'tipo' => 'passivo', 'categoria' => 'fondi',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $fondo = \App\Models\Gestionale\Cassa::create([
        'condominio_id' => $mio['condominio']->id, 'conto_contabile_id' => $contoFondo,
        'nome' => 'Fondo riserva', 'tipo' => 'fondo', 'attiva' => true,
    ]);

    $this->postJson(route('admin.gestionale.banca.importa.anteprima', $mio['condominio']), [
        'cassa_id' => $fondo->id, 'file' => $file(),
    ])->assertJsonValidationErrors('cassa_id');
});

it('una regola di un altro condominio non si cancella', function () {
    $mio = scenarioBanca();
    $altrui = scenarioBanca();
    $this->actingAs(adminBanca());

    $regola = RegolaBancaria::create([
        'condominio_id' => $altrui['condominio']->id, 'nome' => 'Bollo', 'verso' => 'uscita',
        'testo_contiene' => 'bollo', 'azione' => 'ignora', 'attiva' => true,
    ]);

    $this->delete(route('admin.gestionale.banca.regole.destroy', [$mio['condominio'], $regola->id]))->assertNotFound();

    expect(RegolaBancaria::whereKey($regola->id)->exists())->toBeTrue();
});
