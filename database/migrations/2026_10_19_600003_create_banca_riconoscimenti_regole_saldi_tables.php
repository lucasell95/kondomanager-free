<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Banca, la memoria: chi è la controparte (riconoscimenti), che cosa fare dei movimenti ricorrenti
 * (regole) e quanto diceva la banca a una certa data (saldi dell'estratto conto).
 *
 * ## Riconoscimenti
 *
 * Idea dalle «recognitions» di LiDeCo (AGPL): quando un nome o un IBAN viene abbinato a un
 * condòmino o a un fornitore, lo si ricorda. I condòmini pagano quasi sempre dallo stesso conto:
 * dal secondo mese la proposta è immediata. Una riga per (condominio, tipo, valore): il valore è
 * normalizzato (IBAN senza spazi in maiuscolo, nome senza punteggiatura in minuscolo).
 *
 * ## Regole
 *
 * Per commissioni, bolli e addebiti ricorrenti: «se la descrizione contiene X» → registra una
 * regolazione immediata sul capitolo scelto, oppure ignora. Idea dal motore di regole di Firefly III
 * e dai modelli di riconciliazione OCA, ridotta al caso del condominio minimo.
 *
 * ## Saldi dell'estratto conto
 *
 * Saldo a fine giornata secondo la banca, dichiarato dal file (CAMT, OFX, intestazione XLSX) o
 * inserito a mano nella riconciliazione. Uno per (cassa, data).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('banca_riconoscimenti')) {
            Schema::create('banca_riconoscimenti', function (Blueprint $table) {
                $table->id();
                $table->foreignId('condominio_id')->constrained('condomini')->cascadeOnDelete();
                // 'iban' | 'nome'
                $table->string('tipo', 10);
                $table->string('valore');
                $table->foreignId('anagrafica_id')->nullable()->constrained('anagrafiche')->nullOnDelete();
                $table->foreignId('fornitore_id')->nullable()->constrained('fornitori')->nullOnDelete();
                $table->unsignedInteger('conteggio')->default(1);
                $table->timestamp('ultimo_uso_at')->nullable();
                $table->timestamps();

                $table->unique(['condominio_id', 'tipo', 'valore']);
            });
        }

        if (! Schema::hasTable('banca_regole')) {
            Schema::create('banca_regole', function (Blueprint $table) {
                $table->id();
                $table->foreignId('condominio_id')->constrained('condomini')->cascadeOnDelete();
                $table->foreignId('cassa_id')->nullable()->constrained('casse')->cascadeOnDelete();
                $table->string('nome');
                // 'entrata' | 'uscita' | 'entrambi'
                $table->string('verso', 10)->default('uscita');
                $table->string('testo_contiene');
                $table->bigInteger('importo_min')->nullable();
                $table->bigInteger('importo_max')->nullable();
                // 'regolazione' | 'ignora'
                $table->string('azione', 20)->default('regolazione');
                $table->foreignId('conto_id')->nullable()->constrained('conti')->nullOnDelete();
                $table->foreignId('gestione_id')->nullable()->constrained('gestioni')->nullOnDelete();
                $table->string('causale')->nullable();
                $table->boolean('attiva')->default(true);
                $table->timestamps();

                $table->index(['condominio_id', 'attiva']);
            });
        }

        if (! Schema::hasTable('banca_saldi')) {
            Schema::create('banca_saldi', function (Blueprint $table) {
                $table->id();
                $table->foreignId('condominio_id')->constrained('condomini')->cascadeOnDelete();
                $table->foreignId('cassa_id')->constrained('casse')->cascadeOnDelete();
                $table->date('data');
                $table->bigInteger('saldo');
                // 'import' | 'manuale'
                $table->string('fonte', 10)->default('manuale');
                $table->foreignId('importazione_id')->nullable()
                    ->constrained('banca_importazioni')->nullOnDelete();
                $table->timestamps();

                $table->unique(['cassa_id', 'data']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('banca_saldi');
        Schema::dropIfExists('banca_regole');
        Schema::dropIfExists('banca_riconoscimenti');
    }
};
