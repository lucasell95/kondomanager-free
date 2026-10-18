<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il saldo iniziale generato da una chiusura d'esercizio sa da quale chiusura viene.
 *
 * Serve a non toccarlo a mano: un saldo riportato ha una scrittura di riporto gemella (o, se
 * «mantenuto», delle quote ancora aperte che lo giustificano), e modificarlo o eliminarlo da
 * solo rimetterebbe il condòmino a dover pagare due volte. Per correggerlo si annulla la
 * chiusura. NULL = saldo inserito a mano o importato, come prima.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('saldi', 'chiusura_esercizio_id')) {
            Schema::table('saldi', function (Blueprint $table) {
                $table->foreignId('chiusura_esercizio_id')
                    ->nullable()
                    ->constrained('chiusure_esercizi')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('saldi', 'chiusura_esercizio_id')) {
            Schema::table('saldi', function (Blueprint $table) {
                $table->dropConstrainedForeignId('chiusura_esercizio_id');
            });
        }
    }
};
