<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro dei passaggi d'esercizio eseguiti con la procedura guidata.
 *
 * Una riga per ogni «Chiudi esercizio e apri il successivo». Serve a tre cose:
 *
 *  - **sapere che cosa è stato generato** (nuovo esercizio, gestioni, scritture di riporto,
 *    saldi iniziali, quote riportate) e quindi poterlo annullare esattamente, finché nel nuovo
 *    esercizio non si è registrato niente;
 *  - **sapere chi e quando**: l'annullamento non cancella la riga, la marca `annullata`;
 *  - **sapere che un esercizio è stato chiuso dalla procedura**: da lì in poi non si riapre
 *    più dal modulo di modifica (si passa dall'annullamento, che rimette a posto anche i saldi).
 *
 * `esercizio_nuovo_id` è `nullOnDelete`: l'annullamento elimina il nuovo esercizio e la riga
 * resta come traccia.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chiusure_esercizi')) {
            return;
        }

        Schema::create('chiusure_esercizi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condomini')->cascadeOnDelete();
            $table->foreignId('esercizio_chiuso_id')->constrained('esercizi')->cascadeOnDelete();
            $table->foreignId('esercizio_nuovo_id')->nullable()->constrained('esercizi')->nullOnDelete();

            // eseguita | annullata
            $table->string('stato', 20)->default('eseguita');

            // Le scelte fatte nella procedura (modalità per gestione, numerazione, nomi).
            $table->json('opzioni')->nullable();
            // Che cosa è stato generato, con gli id: è la mappa che l'annullamento ripercorre.
            $table->json('riepilogo')->nullable();

            $table->foreignId('eseguita_da')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('eseguita_il')->nullable();
            $table->foreignId('annullata_da')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('annullata_il')->nullable();

            $table->timestamps();

            $table->index(['condominio_id', 'stato']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chiusure_esercizi');
    }
};
