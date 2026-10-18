<?php

/**
 * Helper condivisi per i test del modulo Banca (import estratti conto, abbinamento,
 * riconciliazione). Solo funzioni, nessun test.
 *
 * Dati fittizi: nomi Rossi/Bianchi/Verdi, email @test.it, codici fiscali TSTTST…, IBAN di
 * esempio della documentazione ISO 13616 (non italiani).
 */

use App\Models\Anagrafica;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/../GestionaleTestHelpers.php';

/** IBAN di esempio con controllo mod 97 valido (documentazione ISO 13616). */
const IBAN_ROSSI = 'DE89370400440532013000';
const IBAN_BIANCHI = 'GB82WEST12345698765432';
const IBAN_CONTO_CONDOMINIO = 'FR1420041010050500013M02606';

if (! function_exists('adminBanca')) {
    function adminBanca(): User
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
        $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
        $ruolo->givePermissionTo($permesso);

        $user = User::factory()->create();
        $user->assignRole($ruolo);

        return $user;
    }
}

if (! function_exists('scenarioBanca')) {
    /**
     * Un condominio con esercizio 2026 aperto, piano dei conti, conto corrente (cassa «banca»),
     * conti crediti/anticipi/spese bancarie, due condòmini (Rossi, Bianchi) e per ciascuno una
     * rata emessa da € 100,00 (scadenza 31/01) e una da € 100,00 (scadenza 28/02).
     *
     * @return array<string, mixed>
     */
    function scenarioBanca(): array
    {
        [$condominio, $esercizio, $gestione, $fornitore, $capitolo, , $immobileId] = setupContabile();

        $contoBancaId = DB::table('conti_contabili')->insertGetId([
            'condominio_id' => $condominio->id, 'ruolo' => 'conto_bancario', 'codice' => 'BANCA-TEST',
            'nome' => 'Conto corrente', 'tipo' => 'attivo', 'categoria' => 'crediti',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (['anticipi_condomini' => 'passivo', 'spese_bancarie' => 'attivo'] as $ruolo => $tipo) {
            DB::table('conti_contabili')->insert([
                'condominio_id' => $condominio->id, 'ruolo' => $ruolo, 'codice' => strtoupper($ruolo),
                'nome' => ucfirst(str_replace('_', ' ', $ruolo)), 'tipo' => $tipo,
                'categoria' => $tipo === 'passivo' ? 'debiti' : 'crediti',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $cassa = Cassa::create([
            'condominio_id' => $condominio->id,
            'conto_contabile_id' => $contoBancaId,
            'nome' => 'Conto corrente condominio',
            'tipo' => 'banca',
            'attiva' => true,
        ]);
        $cassa->contoCorrente()->create(['intestatario' => 'Condominio Test', 'iban' => IBAN_CONTO_CONDOMINIO]);

        $immobile2 = DB::table('immobili')->insertGetId([
            'condominio_id' => $condominio->id, 'interno' => '2', 'nome' => 'Appartamento 2', 'descrizione' => '',
            'codice_immobile' => 'APP2-TEST-'.$condominio->id, 'attivo' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $rossi = Anagrafica::create([
            'nome' => 'Rossi Mario', 'indirizzo' => 'Via Roma 1', 'email' => 'rossi'.$condominio->id.'@test.it',
            'codice_fiscale' => 'TSTTST80A01H501'.chr(65 + $condominio->id % 26),
        ]);
        $bianchi = Anagrafica::create([
            'nome' => 'Bianchi Anna', 'indirizzo' => 'Via Roma 1', 'email' => 'bianchi'.$condominio->id.'@test.it',
            'codice_fiscale' => 'TSTTST70A41H501'.chr(65 + $condominio->id % 26),
        ]);

        $condominio->anagrafiche()->attach([$rossi->id, $bianchi->id]);

        foreach ([[$immobileId, $rossi], [$immobile2, $bianchi]] as [$imm, $anag]) {
            DB::table('anagrafica_immobile')->insert([
                'anagrafica_id' => $anag->id, 'immobile_id' => $imm, 'tipologia' => 'proprietario',
                'quota' => 100, 'attivo' => true, 'data_inizio' => '2025-01-01',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $piano = PianoRate::create([
            'condominio_id' => $condominio->id, 'gestione_id' => $gestione->id,
            'nome' => 'Piano 2026', 'numero_rate' => 2,
        ]);

        // Una scrittura di emissione «segnaposto»: basta perché le quote risultino emesse.
        $emissione = ScritturaContabile::create([
            'condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id, 'gestione_id' => $gestione->id,
            'data_registrazione' => '2026-01-01', 'data_competenza' => '2026-01-01',
            'causale' => 'Emissione rate', 'tipo_movimento' => 'emissione_rata', 'stato' => 'registrata',
        ]);

        $quote = [];

        foreach ([1 => '2026-01-31', 2 => '2026-02-28'] as $n => $scadenza) {
            $rata = Rata::create([
                'piano_rate_id' => $piano->id, 'numero_rata' => $n, 'data_scadenza' => $scadenza,
                'importo_totale' => 20000, 'stato' => 'emessa',
            ]);

            foreach ([[$rossi, $immobileId], [$bianchi, $immobile2]] as [$anag, $imm]) {
                $quote[$anag->nome][$n] = RataQuote::create([
                    'rata_id' => $rata->id, 'anagrafica_id' => $anag->id, 'immobile_id' => $imm,
                    'importo' => 10000, 'importo_pagato' => 0, 'stato' => 'da_pagare',
                    'data_scadenza' => $scadenza, 'scrittura_contabile_id' => $emissione->id,
                ]);
            }
        }

        return compact('condominio', 'esercizio', 'gestione', 'fornitore', 'capitolo', 'cassa', 'rossi', 'bianchi', 'quote', 'contoBancaId');
    }
}

if (! function_exists('movimentoBanca')) {
    function movimentoBanca(array $s, array $dati = []): \App\Models\Gestionale\Banca\MovimentoBancario
    {
        return \App\Models\Gestionale\Banca\MovimentoBancario::create(array_merge([
            'condominio_id' => $s['condominio']->id,
            'cassa_id' => $s['cassa']->id,
            'data_contabile' => '2026-02-05',
            'importo' => 10000,
            'descrizione' => 'Bonifico a vostro favore',
            'hash' => hash('sha256', uniqid('', true)),
        ], $dati));
    }
}

if (! function_exists('righeNormalizzate')) {
    /**
     * Le righe di una scrittura in una forma confrontabile fra condomini diversi: il conto per
     * ruolo, l'anagrafica per nome, la rata per numero.
     *
     * @return list<string>
     */
    function righeNormalizzate(int $scritturaId): array
    {
        $righe = DB::table('righe_scritture')
            ->leftJoin('conti_contabili', 'conti_contabili.id', '=', 'righe_scritture.conto_contabile_id')
            ->leftJoin('anagrafiche', 'anagrafiche.id', '=', 'righe_scritture.anagrafica_id')
            ->leftJoin('rate', 'rate.id', '=', 'righe_scritture.rata_id')
            ->where('righe_scritture.scrittura_id', $scritturaId)
            ->get([
                'righe_scritture.tipo_riga', 'righe_scritture.importo', 'righe_scritture.cassa_id',
                'righe_scritture.immobile_id', 'conti_contabili.ruolo', 'anagrafiche.nome', 'rate.numero_rata',
            ])
            ->map(fn ($r) => implode('|', [
                $r->tipo_riga, $r->ruolo, $r->importo, $r->nome ?? '-', $r->numero_rata ?? '-',
                $r->cassa_id ? 'cassa' : '-', $r->immobile_id ? 'immobile' : '-',
            ]))
            ->sort()
            ->values()
            ->all();

        return $righe;
    }
}
