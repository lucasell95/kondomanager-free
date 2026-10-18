<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La fotografia dei residui per condòmino al momento della chiusura.
 *
 * Una riga per (anagrafica, unità, gestione di provenienza). È il dato che serve a chi, dopo la
 * chiusura, deve parlare dell'esercizio chiuso — il rendiconto, la situazione dei morosi
 * dell'art. 1129 c. 9 c.c. — senza dover ricostruire che cosa c'era prima del riporto:
 *
 *  - `residuo`: il saldo di fine esercizio, calcolato come l'estratto conto (saldo iniziale +
 *    movimenti dell'esercizio sui conti del condòmino). Positivo = debito, negativo = credito;
 *  - `riportato`: la parte diventata saldo iniziale LIBERO del nuovo esercizio (la assorbe un
 *    piano rate, tipicamente la Rata 0); le quote corrispondenti sono state chiuse da una
 *    scrittura di riporto;
 *  - `mantenuto`: la parte che resta sulle quote originarie (si incassa o si compensa lì) e che
 *    nel nuovo esercizio compare come saldo iniziale bloccato, solo perché l'estratto conto torni;
 *  - `conguaglio`: la parte del residuo che viene da scritture di riparto/conguaglio
 *    (`tipo_movimento = riparto`), se ce ne sono.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chiusura_esercizio_residui')) {
            return;
        }

        Schema::create('chiusura_esercizio_residui', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chiusura_esercizio_id')->constrained('chiusure_esercizi')->cascadeOnDelete();
            $table->foreignId('anagrafica_id')->nullable()->constrained('anagrafiche')->nullOnDelete();
            $table->foreignId('immobile_id')->nullable()->constrained('immobili')->nullOnDelete();
            $table->foreignId('gestione_id')->nullable()->constrained('gestioni')->nullOnDelete();
            $table->unsignedBigInteger('gestione_destinazione_id')->nullable();
            $table->string('modalita', 20)->nullable();

            $table->bigInteger('saldo_iniziale')->default(0);
            $table->bigInteger('residuo')->default(0);
            $table->bigInteger('riportato')->default(0);
            $table->bigInteger('mantenuto')->default(0);
            $table->bigInteger('conguaglio')->default(0);

            $table->timestamps();

            $table->index(['chiusura_esercizio_id', 'anagrafica_id'], 'chiusura_residui_anagrafica_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chiusura_esercizio_residui');
    }
};
