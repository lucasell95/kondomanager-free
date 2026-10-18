<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro delle chiamate all'IA: serve a mostrare la spesa del mese e a far rispettare il tetto.
 *
 * ⚠️ Non contiene **mai** il testo inviato o ricevuto: solo metadati (tipo di documento, modello,
 * token, costo stimato, esito) e l'impronta SHA-256 del contesto pseudonimizzato, che permette di
 * dire «questa bozza è nata da quel contesto» senza conservarne il contenuto.
 *
 * Il costo è in milionesimi di dollaro (interi, come gli importi in centesimi del resto del
 * progetto): una bozza costa pochi centesimi e i centesimi interi perderebbero tutta la precisione.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ia_utilizzi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tipo_documento', 50);
            $table->string('modello', 100);
            $table->unsignedInteger('token_ingresso')->default(0);
            $table->unsignedInteger('token_uscita')->default(0);
            $table->unsignedBigInteger('costo_microdollari')->default(0);
            // ok | bozza_non_valida | rifiuto | troncata | errore | bloccata_tetto
            $table->string('esito', 30);
            $table->string('codice_errore', 50)->nullable();
            $table->string('request_id', 100)->nullable();
            $table->char('hash_contesto', 64)->nullable();
            $table->unsignedInteger('durata_ms')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ia_utilizzi');
    }
};
