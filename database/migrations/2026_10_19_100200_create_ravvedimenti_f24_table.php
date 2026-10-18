<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gli interessi e le sanzioni versati per ravvedere una ritenuta pagata in ritardo.
 *
 * Il quadro ST del 770 chiede, per ogni versamento, anche gli **interessi** e la casella del
 * **ravvedimento**. Il modulo F24 del programma versa solo la ritenuta (`righe_f24.importo_debito`)
 * e la delega versata è sotto sigillo: non si riscrive. Questa tabella registra a fianco, senza
 * toccarla, ciò che è stato versato in più per il ravvedimento di una riga.
 *
 * Gli interessi si sommano al tributo e si versano con il suo stesso codice; la sanzione ha un
 * codice proprio (8947-8949, ris. AdE 18/E/2023). Qui si annotano entrambi, ma nel quadro ST entra
 * solo l'interesse: la sanzione è annotata per completezza e per la contabilità.
 *
 * ⚠️ La registrazione contabile di interessi e sanzioni (costo e uscita di banca) **non** nasce da
 * qui: va registrata a parte. Questa è un'informazione fiscale, non una scrittura.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ravvedimenti_f24')) {
            return;
        }

        Schema::create('ravvedimenti_f24', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condomini')->restrictOnDelete();
            $table->foreignId('riga_f24_id')->constrained('righe_f24')->restrictOnDelete();

            $table->bigInteger('interessi')->default(0);
            $table->bigInteger('sanzione')->default(0);
            $table->string('codice_tributo_sanzione', 4)->nullable();
            $table->date('data_versamento');
            $table->text('note')->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Un ravvedimento per riga: un secondo versamento integrativo si annota correggendo
            // questo, non aggiungendone un altro che il quadro ST dovrebbe poi sommare.
            $table->unique('riga_f24_id', 'ravvedimenti_f24_riga_unica');
            $table->index('condominio_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ravvedimenti_f24');
    }
};
