<?php

namespace App\Providers;

use App\Contracts\IA\GeneratoreTesto;
use App\Services\IA\GeneratoreTestoNullo;
use Illuminate\Support\ServiceProvider;

/** Collega l'interfaccia del generatore di testi all'implementazione in uso. */
class IAServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(GeneratoreTesto::class, GeneratoreTestoNullo::class);
    }
}
