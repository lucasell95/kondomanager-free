<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/** Impostazioni dell'assistenza IA: spenta finché l'amministratore non la attiva. */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('ia.ia_abilitata', false);
        $this->migrator->add('ia.api_key', null);
        $this->migrator->add('ia.modello', 'claude-sonnet-5-5');
        $this->migrator->add('ia.tetto_spesa_mensile_centesimi', null);
        $this->migrator->add('ia.informativa_confermata_il', null);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('ia.ia_abilitata');
        $this->migrator->deleteIfExists('ia.api_key');
        $this->migrator->deleteIfExists('ia.modello');
        $this->migrator->deleteIfExists('ia.tetto_spesa_mensile_centesimi');
        $this->migrator->deleteIfExists('ia.informativa_confermata_il');
    }
};
