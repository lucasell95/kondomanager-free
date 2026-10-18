<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * I dati anagrafici del percipiente persona fisica che la Certificazione Unica chiede e che
 * l'anagrafica del fornitore non aveva: cognome e nome separati, sesso, data e luogo di nascita.
 *
 * Nella CU (record D del file telematico, campi DA002002-DA002007 secondo il codice dei generatori
 * open source citati in `analisi/ricerca-770-cu.md` §3) il percipiente persona fisica si indica con
 * cognome, nome, sesso, data, comune e provincia di nascita. `ragione_sociale` resta il nome con cui
 * il fornitore compare nel resto del programma; questi campi servono solo alle certificazioni.
 *
 * Il domicilio fiscale non è qui: è l'indirizzo già presente sul fornitore (indirizzo, CAP, comune,
 * provincia). Il codice catastale del comune di nascita è facoltativo: se manca il programma lo cerca
 * nella tabella `comuni`.
 *
 * Additiva e rieseguibile: ogni colonna è protetta da `hasColumn`, come le altre migrazioni del
 * salto 1.10 sulla stessa tabella.
 */
return new class extends Migration
{
    private array $colonne = [
        'cognome',
        'nome',
        'sesso',
        'data_nascita',
        'comune_nascita',
        'provincia_nascita',
        'codice_catastale_nascita',
    ];

    public function up(): void
    {
        Schema::table('fornitori', function (Blueprint $table) {
            if (! Schema::hasColumn('fornitori', 'cognome')) {
                $table->string('cognome', 100)->nullable();
            }
            if (! Schema::hasColumn('fornitori', 'nome')) {
                $table->string('nome', 100)->nullable();
            }
            if (! Schema::hasColumn('fornitori', 'sesso')) {
                $table->char('sesso', 1)->nullable();
            }
            if (! Schema::hasColumn('fornitori', 'data_nascita')) {
                $table->date('data_nascita')->nullable();
            }
            if (! Schema::hasColumn('fornitori', 'comune_nascita')) {
                $table->string('comune_nascita', 100)->nullable();
            }
            if (! Schema::hasColumn('fornitori', 'provincia_nascita')) {
                $table->string('provincia_nascita', 2)->nullable();
            }
            if (! Schema::hasColumn('fornitori', 'codice_catastale_nascita')) {
                $table->string('codice_catastale_nascita', 4)->nullable();
            }
        });
    }

    public function down(): void
    {
        $presenti = array_values(array_filter($this->colonne, fn ($c) => Schema::hasColumn('fornitori', $c)));

        if ($presenti !== []) {
            Schema::table('fornitori', fn (Blueprint $table) => $table->dropColumn($presenti));
        }
    }
};
