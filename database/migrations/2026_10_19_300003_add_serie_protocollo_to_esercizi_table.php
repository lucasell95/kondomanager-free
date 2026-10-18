<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Numerazione dei protocolli per esercizio.
 *
 * Il protocollo è `PREFISSO-ANNO-NNNNN` e l'anno è quello solare del giorno di registrazione:
 * con un esercizio 01/06–31/05 la numerazione ripartiva il 1° gennaio, a metà esercizio, ed era
 * condivisa fra due esercizi.
 *
 * `serie_protocollo` è il segmento centrale da usare al posto dell'anno solare per le scritture
 * e le fatture di QUESTO esercizio: «2027/28» per un esercizio a cavallo d'anno, «2027» per uno
 * sull'anno solare. NULL (tutti gli esercizi esistenti) = comportamento di prima: nessun
 * protocollo già assegnato cambia e nessuna serie già in corso si interrompe. La valorizza la
 * procedura di chiusura sul nuovo esercizio, se l'amministratore lo sceglie.
 *
 * La barra non collide con le serie per anno: `INC-2027-%` non trova `INC-2027/28-00001`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('esercizi', 'serie_protocollo')) {
            Schema::table('esercizi', function (Blueprint $table) {
                $table->string('serie_protocollo', 9)->nullable()->after('stato');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('esercizi', 'serie_protocollo')) {
            Schema::table('esercizi', function (Blueprint $table) {
                $table->dropColumn('serie_protocollo');
            });
        }
    }
};
