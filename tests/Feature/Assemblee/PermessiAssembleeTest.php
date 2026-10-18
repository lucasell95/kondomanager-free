<?php

/**
 * Il condòmino vede solo le assemblee convocate dei suoi condomìni, in sola lettura; le rotte del
 * gestionale gli sono chiuse.
 */

use App\Enums\Assemblee\StatoAssemblea;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/AssembleeTestHelpers.php';

beforeEach(function () {
    // Le pagine d'errore (403/404) chiedono all'utente se ha l'accesso al pannello: il permesso
    // deve esistere, come in un'installazione vera.
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);

    $this->dati = assCondominio();
    $this->condominio = $this->dati['condominio'];

    // Il condòmino: utente senza ruoli, legato all'anagrafica di Rossi.
    $this->condomino = User::factory()->create(['email_verified_at' => now()]);
    $this->dati['anagrafiche']['Rossi Mario']->update(['user_id' => $this->condomino->id]);

    $this->bozza = assAssemblea($this->condominio, ['stato' => StatoAssemblea::BOZZA, 'titolo' => 'Assemblea in preparazione']);
    $this->convocata = assAssemblea($this->condominio, ['titolo' => 'Assemblea convocata']);
    $this->convocata->punti()->create(['titolo' => 'Approvazione del rendiconto', 'materia' => 'rendiconto_preventivo', 'ordine' => 1]);
});

it('il condòmino vede le assemblee convocate e non quelle in bozza', function () {
    $this->actingAs($this->condomino)
        ->get(route('user.assemblee.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('assemblee/user/AssembleeList')
            ->has('assemblee', 1)
            ->where('assemblee.0.titolo', 'Assemblea convocata')
        );

    $this->actingAs($this->condomino)->get(route('user.assemblee.show', $this->bozza))->assertNotFound();
    $this->actingAs($this->condomino)->get(route('user.assemblee.avviso', $this->bozza))->assertNotFound();

    $this->actingAs($this->condomino)->get(route('user.assemblee.show', $this->convocata))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('assemblee/user/AssembleaShow')->has('assemblea.punti', 1));

    $this->actingAs($this->condomino)->get(route('user.assemblee.avviso', $this->convocata))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    // Il verbale solo quando è registrato
    $this->actingAs($this->condomino)->get(route('user.assemblee.verbale', $this->convocata))->assertNotFound();
});

it('il condòmino non vede le assemblee di un condominio che non è il suo', function () {
    $altro = assCondominio(['Bianchi Anna' => '1000']);
    $estranea = assAssemblea($altro['condominio']);

    $this->actingAs($this->condomino)->get(route('user.assemblee.show', $estranea))->assertNotFound();
    $this->actingAs($this->condomino)->get(route('user.assemblee.index'))
        ->assertInertia(fn (Assert $page) => $page->has('assemblee', 1));
});

it('il condòmino non entra nel gestionale delle assemblee e non modifica nulla', function () {
    $c = $this->condominio;
    $a = $this->convocata;
    $punto = $a->punti()->first();

    $this->actingAs($this->condomino)->get(route('admin.gestionale.assemblee.index', $c))->assertForbidden();
    $this->actingAs($this->condomino)->get(route('admin.gestionale.assemblee.show', [$c, $this->bozza]))->assertForbidden();
    $this->actingAs($this->condomino)->get(assRotta('stampa', $a, ['documento' => 'verbale']))->assertForbidden();

    $this->actingAs($this->condomino)->post(route('admin.gestionale.assemblee.store', $c), [
        'tipo' => 'ordinaria', 'prima_convocazione_at' => '2026-12-01T21:00',
    ])->assertForbidden();
    $this->actingAs($this->condomino)->put(assRotta('stato', $a), ['stato' => 'svolta'])->assertForbidden();
    $this->actingAs($this->condomino)->post(assRotta('punti.store', $a), ['titolo' => 'Nuovo', 'materia' => 'ordinaria'])->assertForbidden();
    $this->actingAs($this->condomino)->put(assRotta('presenze.update', $a), [
        'anagrafica_id' => $this->dati['anagrafiche']['Rossi Mario']->id, 'modo' => 'presente',
    ])->assertForbidden();
    $this->actingAs($this->condomino)->put(assRotta('punti.voti', $a, ['punto' => $punto->id]), ['voti' => []])->assertForbidden();
    $this->actingAs($this->condomino)->post(assRotta('verbale.registra', $a))->assertForbidden();
    $this->actingAs($this->condomino)->delete(route('admin.gestionale.assemblee.destroy', [$c, $this->bozza]))->assertForbidden();

    expect($a->fresh()->stato)->toBe(StatoAssemblea::CONVOCATA)
        ->and($a->punti()->count())->toBe(1)
        ->and($a->presenze()->count())->toBe(0)
        ->and($this->bozza->fresh())->not->toBeNull();
});

it('chi non è autenticato viene mandato al login', function () {
    $this->get(route('user.assemblee.index'))->assertRedirect();
    $this->get(route('admin.gestionale.assemblee.index', $this->condominio))->assertRedirect();
});
