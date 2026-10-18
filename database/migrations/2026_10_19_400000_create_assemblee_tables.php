<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modulo Assemblee: assemblea, ordine del giorno, destinatari della convocazione, fotografia delle
 * unità e dei millesimi, presenze e deleghe, voti per punto, dichiarazioni a verbale.
 *
 * Le colonne di stato e di materia sono VARCHAR e non ENUM (principio 10 della roadmap): i valori
 * ammessi li decidono gli enum PHP in `App\Enums\Assemblee`, e su SQLite un ENUM diventerebbe un
 * CHECK più severo dello schema di produzione.
 *
 * I millesimi della fotografia sono **interi in centomillesimi** (valore × 100000): la colonna
 * `quote_tabella.valore` è `decimal(12,5)`, quindi la conversione è esatta e i confronti dei quorum
 * si fanno su interi, senza arrotondamenti.
 *
 * Ogni tabella è creata solo se manca: la migrazione resta rieseguibile dopo un aggiornamento
 * interrotto a metà, come chiede `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('assemblee')) Schema::create('assemblee', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condomini')->cascadeOnDelete();
            $table->foreignId('esercizio_id')->nullable()->constrained('esercizi')->nullOnDelete();
            // Tabella dei millesimi su cui si contano i quorum (di norma la proprietà generale).
            $table->foreignId('tabella_id')->nullable()->constrained('tabelle')->nullOnDelete();
            $table->string('tipo', 30)->default('ordinaria');
            $table->string('titolo')->nullable();
            $table->string('stato', 30)->default('bozza');

            $table->dateTime('prima_convocazione_at');
            $table->string('prima_luogo')->nullable();
            $table->dateTime('seconda_convocazione_at')->nullable();
            $table->string('seconda_luogo')->nullable();

            $table->boolean('videoconferenza')->default(false);
            $table->string('piattaforma_video')->nullable();
            $table->string('link_video', 500)->nullable();
            // Art. 66 c. 6 disp. att.: serve il consenso preventivo della maggioranza dei condòmini.
            $table->boolean('consenso_video_acquisito')->default(false);

            $table->string('convocante')->nullable();
            // L'amministratore, se è anche condòmino: non può ricevere deleghe (art. 67 c. 5 disp. att.).
            $table->foreignId('amministratore_anagrafica_id')->nullable()->constrained('anagrafiche')->nullOnDelete();
            $table->string('amministratore_nome')->nullable();

            // Svolgimento
            $table->string('convocazione_tenuta', 10)->nullable(); // prima | seconda
            $table->boolean('prima_deserta')->default(false);
            $table->string('presidente')->nullable();
            $table->string('segretario')->nullable();
            $table->string('ora_apertura', 5)->nullable();
            $table->string('ora_chiusura', 5)->nullable();
            $table->text('allegati')->nullable(); // uno per riga: elenco degli allegati all'avviso
            $table->text('note')->nullable();
            // Tracciabilità delle bozze di testo: per tipo (convocazione, verbale) fonte, modello, versione del prompt, avvisi.
            $table->json('bozze_ia')->nullable();

            // Registro dei verbali (art. 1130 n. 7 c.c.)
            $table->unsignedInteger('numero_verbale')->nullable();
            $table->unsignedSmallInteger('anno_verbale')->nullable();
            $table->dateTime('verbale_registrato_at')->nullable();
            $table->foreignId('documento_verbale_id')->nullable()->constrained('documenti')->nullOnDelete();
            $table->foreignId('evento_id')->nullable()->constrained('eventi')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['condominio_id', 'esercizio_id']);
            $table->unique(['condominio_id', 'anno_verbale', 'numero_verbale'], 'assemblee_registro_unique');
        });

        if (! Schema::hasTable('assemblea_punti')) Schema::create('assemblea_punti', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assemblea_id')->constrained('assemblee')->cascadeOnDelete();
            $table->unsignedSmallInteger('ordine')->default(1);
            $table->string('titolo');
            $table->text('testo')->nullable();
            $table->string('materia', 50)->default('ordinaria');
            // Tabella diversa da quella dell'assemblea, per le parti comuni solo ad alcuni (Cass. 15927/2024).
            $table->foreignId('tabella_id')->nullable()->constrained('tabelle')->nullOnDelete();
            $table->text('discussione')->nullable();
            $table->text('testo_delibera')->nullable();
            $table->timestamps();
        });

        if (! Schema::hasTable('assemblea_destinatari')) Schema::create('assemblea_destinatari', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assemblea_id')->constrained('assemblee')->cascadeOnDelete();
            $table->foreignId('anagrafica_id')->nullable()->constrained('anagrafiche')->nullOnDelete();
            $table->string('nominativo');
            $table->string('ruolo', 50)->nullable();
            $table->string('mezzo', 30)->default('raccomandata');
            $table->string('recapito')->nullable();
            $table->date('data_invio')->nullable();
            $table->date('data_ricezione')->nullable();
            $table->string('riferimento')->nullable(); // n. raccomandata, ricevuta PEC, firma di ricevuta
            // Comunicazione del verbale (art. 1137 c.c.: per gli assenti il termine decorre da qui)
            $table->string('verbale_mezzo', 30)->nullable();
            $table->date('verbale_data_invio')->nullable();
            $table->date('verbale_data_ricezione')->nullable();
            $table->string('verbale_riferimento')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        if (! Schema::hasTable('assemblea_unita')) Schema::create('assemblea_unita', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assemblea_id')->constrained('assemblee')->cascadeOnDelete();
            $table->foreignId('immobile_id')->nullable()->constrained('immobili')->nullOnDelete();
            $table->string('etichetta');
            $table->unsignedBigInteger('valore')->default(0); // centomillesimi interi
            $table->json('proprietari')->nullable();   // id anagrafiche: proprietari e nudi proprietari
            $table->json('usufruttuari')->nullable();  // id anagrafiche: usufruttuari
            $table->timestamps();
        });

        if (! Schema::hasTable('assemblea_presenze')) Schema::create('assemblea_presenze', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assemblea_id')->constrained('assemblee')->cascadeOnDelete();
            $table->foreignId('anagrafica_id')->constrained('anagrafiche')->cascadeOnDelete();
            $table->string('modo', 20)->default('assente'); // presente | delegato | assente
            $table->foreignId('delegato_anagrafica_id')->nullable()->constrained('anagrafiche')->nullOnDelete();
            $table->string('delegato_nome')->nullable();
            $table->boolean('delega_scritta')->default(true);
            $table->string('ora_ingresso', 5)->nullable();
            $table->string('ora_uscita', 5)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['assemblea_id', 'anagrafica_id']);
        });

        if (! Schema::hasTable('assemblea_voti')) Schema::create('assemblea_voti', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assemblea_punto_id')->constrained('assemblea_punti')->cascadeOnDelete();
            // Chiave della «testa»: gli id delle anagrafiche che votano insieme, ordinati e uniti da «-».
            $table->string('chiave_testa', 191);
            $table->boolean('presente')->default(true);
            $table->string('voto', 20)->nullable(); // favorevole | contrario | astenuto
            $table->boolean('conflitto')->default(false);
            $table->timestamps();
            $table->unique(['assemblea_punto_id', 'chiave_testa']);
        });

        if (! Schema::hasTable('assemblea_dichiarazioni')) Schema::create('assemblea_dichiarazioni', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assemblea_id')->constrained('assemblee')->cascadeOnDelete();
            $table->foreignId('assemblea_punto_id')->nullable()->constrained('assemblea_punti')->nullOnDelete();
            $table->foreignId('anagrafica_id')->nullable()->constrained('anagrafiche')->nullOnDelete();
            $table->string('dichiarante');
            $table->text('testo'); // testo letterale, come reso
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assemblea_dichiarazioni');
        Schema::dropIfExists('assemblea_voti');
        Schema::dropIfExists('assemblea_presenze');
        Schema::dropIfExists('assemblea_unita');
        Schema::dropIfExists('assemblea_destinatari');
        Schema::dropIfExists('assemblea_punti');
        Schema::dropIfExists('assemblee');
    }
};
