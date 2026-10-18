<?php

namespace App\Providers;

use App\Contracts\IA\GeneratoreTesto;
use App\Services\IA\GeneratoreTestoAnthropic;
use App\Services\IA\GeneratoreTestoNullo;
use App\Settings\IASettings;
use Illuminate\Support\ServiceProvider;

/**
 * Collega l'interfaccia del generatore di testi all'implementazione in uso.
 *
 * - IA attivata nelle impostazioni **e** chiave API leggibile → `GeneratoreTestoAnthropic`;
 * - altrimenti (spenta, chiave assente o non decifrabile, impostazioni non ancora migrate durante
 *   l'installazione) → `GeneratoreTestoNullo`, e i chiamanti ripiegano sul testo fisso.
 *
 * `bind` e non `singleton`: le impostazioni si leggono a ogni risoluzione, così attivare o
 * spegnere l'IA ha effetto subito, anche nei worker della coda.
 */
class IAServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(GeneratoreTesto::class, function ($app) {
            try {
                if ($app->make(IASettings::class)->operativa()) {
                    return $app->make(GeneratoreTestoAnthropic::class);
                }
            } catch (\Throwable) {
                // Tabella delle impostazioni assente o gruppo 'ia' non ancora migrato.
            }

            return $app->make(GeneratoreTestoNullo::class);
        });
    }
}
