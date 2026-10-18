<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il firmatario delle dichiarazioni del condominio come sostituto d'imposta (CU e 770).
 *
 * Il codice fiscale del condominio c'era già (`condomini.codice_fiscale`); mancava chi firma:
 * cognome, nome e codice fiscale del rappresentante, il **codice carica** (13 = amministratore di
 * condominio nella tabella dei codici carica delle istruzioni; da verificare sul modello
 * dell'anno) e la data da cui è in carica. Più un telefono del sostituto, che il frontespizio
 * chiede accanto all'email già presente.
 *
 * Sono dati del condominio e non dell'utente: l'amministratore cambia, e la CU di un anno va
 * firmata da chi era in carica allora. Per questo stanno qui e non nell'anagrafica dell'utente.
 */
return new class extends Migration
{
    private array $colonne = [
        'firmatario_codice_fiscale',
        'firmatario_cognome',
        'firmatario_nome',
        'firmatario_codice_carica',
        'firmatario_data_carica',
        'sostituto_telefono',
    ];

    public function up(): void
    {
        Schema::table('condomini', function (Blueprint $table) {
            if (! Schema::hasColumn('condomini', 'firmatario_codice_fiscale')) {
                $table->string('firmatario_codice_fiscale', 16)->nullable();
            }
            if (! Schema::hasColumn('condomini', 'firmatario_cognome')) {
                $table->string('firmatario_cognome', 100)->nullable();
            }
            if (! Schema::hasColumn('condomini', 'firmatario_nome')) {
                $table->string('firmatario_nome', 100)->nullable();
            }
            if (! Schema::hasColumn('condomini', 'firmatario_codice_carica')) {
                $table->string('firmatario_codice_carica', 2)->nullable();
            }
            if (! Schema::hasColumn('condomini', 'firmatario_data_carica')) {
                $table->date('firmatario_data_carica')->nullable();
            }
            if (! Schema::hasColumn('condomini', 'sostituto_telefono')) {
                $table->string('sostituto_telefono', 20)->nullable();
            }
        });
    }

    public function down(): void
    {
        $presenti = array_values(array_filter($this->colonne, fn ($c) => Schema::hasColumn('condomini', $c)));

        if ($presenti !== []) {
            Schema::table('condomini', fn (Blueprint $table) => $table->dropColumn($presenti));
        }
    }
};
