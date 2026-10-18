<?php

use App\Actions\Gestionale\Movimenti\StoreIncassoRateAction;
use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\RataQuote;
use App\Models\User;
use App\Services\Gestionale\FatturaPassivaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * Spesa ad personam rateizzata con un piano straordinario, dall'inizio alla fine:
 * fattura con una riga sull'unità → piano straordinario che la addebita al proprietario →
 * emissione → incasso dell'intero importo.
 *
 * Alla fine il condòmino ha pagato quello che doveva: la sua unità non deve avere più niente
 * aperto su Crediti verso condòmini (1101).
 *
 * Il sospetto (analisi B7): la fattura fa già DARE 1101 con l'immobile, e l'emissione fa di nuovo
 * DARE 1101 / AVERE Gestione rate per la stessa spesa, quindi 1101 resterebbe gonfiato
 * dell'importo ad personam anche dopo il pagamento.
 */
function adPersScenario(): array
{
    [$condominio, $esercizio, $gestione, $fornitore, , , $immobileId] = setupContabile();

    // setupContabile() non crea Gestione rate, che l'emissione richiede.
    DB::table('conti_contabili')->insert([
        'condominio_id' => $condominio->id, 'ruolo' => 'gestione_rate', 'codice' => '3001',
        'nome' => 'Gestione Rate', 'tipo' => 'passivo', 'categoria' => 'fondi',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $anagrafica = Anagrafica::factory()->create();
    $condominio->anagrafiche()->attach($anagrafica->id);
    DB::table('anagrafica_immobile')->insert([
        'anagrafica_id' => $anagrafica->id, 'immobile_id' => $immobileId, 'tipologia' => 'proprietario',
        'quota' => 100, 'attivo' => true, 'data_inizio' => now(),
    ]);

    // Fattura con una sola riga ad personam: riparazione del balcone dell'interno 1, 500,00.
    $fattura = (new FatturaPassivaService())->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'righe' => [[
                'descrizione' => 'Riparazione balcone', 'importo_imponibile' => 500, 'aliquota_iva' => 0,
                'immobile_id' => $immobileId, 'is_sopravvenienza' => false,
            ]],
        ]),
        $condominio->id
    );

    $piano = PianoRate::create([
        'gestione_id' => $gestione->id, 'condominio_id' => $condominio->id,
        'nome' => 'Straordinario balcone', 'stato' => 'bozza', 'tipo' => 'straordinario', 'numero_rate' => 1,
    ]);
    $piano->fatture()->attach($fattura->id, ['importo_collegato' => 50000]);

    app(GeneratePianoRateAction::class)->execute($piano, accettaScoperti: false);
    $piano->update(['stato' => 'approvato']);

    return [$condominio, $esercizio, $gestione, $immobileId, $anagrafica, $piano];
}

/** Saldo DARE − AVERE su Crediti verso condòmini per l'unità, su tutto il giornale. */
function adPersSaldo1101(int $condominioId, int $immobileId): int
{
    $conto = ContoContabile::where('condominio_id', $condominioId)->where('ruolo', 'crediti_condomini')->firstOrFail();

    $righe = DB::table('righe_scritture as rs')
        ->join('scritture_contabili as sc', 'sc.id', '=', 'rs.scrittura_id')
        ->whereNull('sc.deleted_at')
        ->where('rs.conto_contabile_id', $conto->id)
        ->where('rs.immobile_id', $immobileId)
        ->get(['rs.tipo_riga', 'rs.importo']);

    return (int) $righe->where('tipo_riga', 'dare')->sum('importo') - (int) $righe->where('tipo_riga', 'avere')->sum('importo');
}

test('spesa ad personam rateizzata: dopo emissione e pagamento la posizione su 1101 torna a zero', function () {
    [$condominio, $esercizio, $gestione, $immobileId, $anagrafica, $piano] = adPersScenario();

    // Il piano addebita i 500,00 al proprietario.
    $quote = RataQuote::whereHas('rata', fn ($q) => $q->where('piano_rate_id', $piano->id))->get();
    expect((int) $quote->sum('importo'))->toBe(50000);

    // Emissione dal controller.
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $user = User::factory()->create();
    $user->assignRole($ruolo);

    $this->actingAs($user)
        ->post(route('admin.gestionale.piani-rate.emetti', ['condominio' => $condominio->id, 'pianoRate' => $piano->id]), [
            'rate_ids' => $piano->rate()->pluck('id')->all(), 'data_emissione' => now()->toDateString(), 'invia_notifiche' => false,
        ])
        ->assertSessionHas('message.type', 'success');

    // Dopo l'emissione il condòmino deve 500,00, non 1.000,00.
    expect(adPersSaldo1101($condominio->id, $immobileId))->toBe(50000);

    // Incasso dell'intero importo.
    $contoBanca = DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominio->id, 'ruolo' => 'conto_bancario', 'codice' => '1010',
        'nome' => 'Banca', 'tipo' => 'attivo', 'categoria' => 'liquidita', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $cassa = Cassa::create([
        'condominio_id' => $condominio->id, 'nome' => 'Banca', 'tipo' => 'banca',
        'conto_contabile_id' => $contoBanca, 'saldo_iniziale' => 0, 'attiva' => true,
    ]);

    app(StoreIncassoRateAction::class)->execute([
        'pagante_id' => $anagrafica->id, 'cassa_id' => $cassa->id, 'gestione_id' => $gestione->id,
        'data_pagamento' => now()->toDateString(), 'descrizione' => 'Rimborso balcone',
        'importo_totale' => 500.00, 'eccedenza' => 0,
        'dettaglio_pagamenti' => $quote->map(fn ($q) => ['rata_id' => $q->id, 'importo' => $q->importo / 100])->values()->all(),
    ], $condominio, $esercizio);

    expect((int) RataQuote::whereIn('id', $quote->pluck('id'))->sum('importo_pagato'))->toBe(50000)
        ->and(adPersSaldo1101($condominio->id, $immobileId))->toBe(0);
});
