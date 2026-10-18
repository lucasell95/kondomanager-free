<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Banca, primo passo: come si legge l'estratto conto di un conto (profilo) e che cosa è stato
 * caricato (importazione).
 *
 * Solo tabelle nuove, nessun `ALTER`: una `CREATE TABLE` interrotta non lascia dati a metà.
 * Rieseguibile: ogni `create` è protetta da `hasTable`.
 *
 * ## Il profilo di importazione
 *
 * Ogni home banking esporta la «lista movimenti» a modo suo: righe di intestazione sopra la
 * tabella, nomi di colonna diversi, importo unico o accrediti/addebiti separati, decimali con la
 * virgola. Il profilo ricorda, **per conto**, la mappatura scelta la prima volta (idea dal
 * data-importer di Firefly III e da OCA `account_statement_import_sheet_file`), così dal secondo
 * mese l'import è un clic.
 *
 * ## L'importazione
 *
 * Tiene traccia di ogni caricamento: file (solo nome e hash, il file non si conserva), periodo,
 * saldi dichiarati dall'estratto e contatori. L'hash del file serve ad avvisare quando si
 * ricarica lo stesso file; i doppioni veri si riconoscono comunque riga per riga.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('banca_profili_importazione')) {
            Schema::create('banca_profili_importazione', function (Blueprint $table) {
                $table->id();
                $table->foreignId('condominio_id')->constrained('condomini')->cascadeOnDelete();
                $table->foreignId('cassa_id')->constrained('casse')->cascadeOnDelete();
                // 'tabellare' (CSV/XLSX/XLS): gli unici che hanno bisogno di una mappatura.
                $table->string('formato', 20)->default('tabellare');
                $table->string('nome')->nullable();
                // Riga dell'intestazione, separatori, formato data, colonne → ruoli.
                $table->json('configurazione');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['cassa_id', 'formato']);
            });
        }

        if (! Schema::hasTable('banca_importazioni')) {
            Schema::create('banca_importazioni', function (Blueprint $table) {
                $table->id();
                $table->foreignId('condominio_id')->constrained('condomini')->cascadeOnDelete();
                $table->foreignId('cassa_id')->constrained('casse')->cascadeOnDelete();
                $table->foreignId('profilo_id')->nullable()
                    ->constrained('banca_profili_importazione')->nullOnDelete();
                // csv, xlsx, xls, camt053, camt052, ofx
                $table->string('formato', 20);
                $table->string('nome_file')->nullable();
                $table->string('hash_file', 64)->nullable()->index();
                $table->date('data_da')->nullable();
                $table->date('data_a')->nullable();
                // Saldi dichiarati dall'estratto, in centesimi con segno.
                $table->bigInteger('saldo_iniziale')->nullable();
                $table->bigInteger('saldo_finale')->nullable();
                $table->unsignedInteger('righe_lette')->default(0);
                $table->unsignedInteger('righe_importate')->default(0);
                $table->unsignedInteger('righe_duplicate')->default(0);
                $table->unsignedInteger('righe_collegate')->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['cassa_id', 'data_a']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('banca_importazioni');
        Schema::dropIfExists('banca_profili_importazione');
    }
};
