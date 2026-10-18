<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le parti del rendiconto 1130-bis che **non** si calcolano dalla contabilità.
 *
 * Il rendiconto si legge tutto dal giornale (registro, riepilogo, consuntivo, riparto), tranne
 * due cose che scrive l'amministratore e che devono restare uguali da una stampa all'altra:
 *
 * - la **nota sintetica esplicativa**, salvata con i segnaposto `{{F:chiave}}`: i numeri si
 *   sostituiscono al momento della stampa, così il testo non diverge dai conti;
 * - il **saldo dell'estratto conto** di ogni conto di liquidità a fine esercizio, che il
 *   riepilogo confronta con il saldo contabile.
 *
 * Una riga per esercizio. Tabella nuova e additiva: nessuna colonna esistente cambia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rendiconti_esercizio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condomini')->cascadeOnDelete();
            $table->foreignId('esercizio_id')->unique()->constrained('esercizi')->cascadeOnDelete();
            $table->longText('nota_sintetica')->nullable();
            // 'modello' | 'ia' | 'manuale': da dove viene l'ultima versione della nota.
            $table->string('fonte_nota', 20)->nullable();
            // Tracciabilità di una nota nata da una bozza IA: modello e versione del prompt.
            $table->string('modello_ia', 100)->nullable();
            $table->string('versione_prompt_ia', 50)->nullable();
            // conto_contabile_id => centesimi (saldo da estratto conto a fine esercizio).
            $table->json('saldi_estratto_conto')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rendiconti_esercizio');
    }
};
