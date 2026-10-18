<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * I movimenti letti dall'estratto conto, uno per riga della banca.
 *
 * Non sono scritture contabili: sono **quello che dice la banca**. Il giornale resta l'unica fonte
 * della contabilità, e un movimento bancario lo tocca solo quando viene abbinato, passando dai
 * flussi esistenti (incasso rate, pagamento fornitore, regolazione immediata) o collegandosi a una
 * scrittura già registrata.
 *
 * ## Il segno
 *
 * `importo` è in centesimi **con segno**, dal punto di vista del conto: positivo = accredito
 * (entra denaro), negativo = addebito.
 *
 * ## L'hash
 *
 * `hash` = sha256 di conto, data contabile, importo, descrizione normalizzata e **occorrenza**
 * (la seconda commissione identica dello stesso giorno ha occorrenza 2). Unico per cassa: ricaricare
 * lo stesso mese, o un periodo che si sovrappone, non crea doppioni (idea dal `DuplicateSafetyCatch`
 * del data-importer di Firefly III).
 *
 * ## Lo stato
 *
 * `da_abbinare` → `abbinato` (con `scrittura_contabile_id`) oppure `ignorato` (movimento che non va
 * a giornale, con il motivo in `note`). L'annullamento dell'abbinamento riporta a `da_abbinare` e
 * non tocca la scrittura: uno storno si fa dal suo flusso.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('banca_movimenti')) {
            return;
        }

        Schema::create('banca_movimenti', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condomini')->cascadeOnDelete();
            $table->foreignId('cassa_id')->constrained('casse')->cascadeOnDelete();
            $table->foreignId('importazione_id')->nullable()
                ->constrained('banca_importazioni')->nullOnDelete();

            $table->date('data_contabile');
            $table->date('data_valuta')->nullable();
            $table->bigInteger('importo');
            $table->string('divisa', 3)->default('EUR');
            $table->text('descrizione')->nullable();
            $table->string('controparte')->nullable();
            $table->string('iban_controparte', 34)->nullable();
            // CRO / TRN / EndToEndId / FITID, quando l'estratto lo porta.
            $table->string('riferimento')->nullable();

            $table->string('hash', 64);
            $table->unsignedInteger('riga_origine')->nullable();

            $table->string('stato', 20)->default('da_abbinare');
            // incasso_rate, pagamento_fornitore, regolazione, esistente
            $table->string('tipo_abbinamento', 30)->nullable();
            $table->foreignId('scrittura_contabile_id')->nullable()
                ->constrained('scritture_contabili')->nullOnDelete();
            $table->foreignId('abbinato_da')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('abbinato_at')->nullable();
            $table->text('note')->nullable();

            // Chiave passata al flusso pagamenti: una doppia conferma restituisce lo stesso
            // pagamento invece di registrarne due.
            $table->uuid('idempotency_key')->unique();

            $table->timestamps();

            $table->unique(['cassa_id', 'hash']);
            $table->index(['condominio_id', 'stato']);
            $table->index(['cassa_id', 'data_contabile']);
            $table->index('scrittura_contabile_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banca_movimenti');
    }
};
