<?php

namespace App\Services\Assemblee;

use App\Enums\Assemblee\StatoAssemblea;
use App\Enums\CategoriaEventoEnum;
use App\Enums\VisibilityStatus;
use App\Models\Assemblee\Assemblea;
use App\Models\CategoriaEvento;
use App\Models\Evento;

/**
 * Collega l'assemblea all'agenda esistente: un evento di categoria «Assemblea», visibile ai
 * condòmini del condominio, creato quando l'assemblea è convocata e tolto se torna in bozza.
 *
 * L'evento punta all'assemblea con la relazione polimorfica `eventable` già prevista dal modello.
 */
class AgendaAssembleaService
{
    public function sincronizza(Assemblea $assemblea, ?int $userId = null): void
    {
        if ($assemblea->stato === StatoAssemblea::BOZZA) {
            $this->rimuovi($assemblea);

            return;
        }

        $autore = $userId ?? $assemblea->created_by;
        if (! $autore) {
            return; // `eventi.created_by` è obbligatorio: senza autore non si crea l'evento.
        }

        $categoria = CategoriaEvento::firstOrCreate(
            ['name' => CategoriaEventoEnum::ASSEMBLEA->value],
            ['description' => CategoriaEventoEnum::ASSEMBLEA->description()]
        );

        $inizio = $assemblea->dataSvolgimento() ?? $assemblea->prima_convocazione_at;
        $descrizione = 'Prima convocazione: '.$assemblea->prima_convocazione_at?->format('d/m/Y H:i')
            .($assemblea->prima_luogo ? ' – '.$assemblea->prima_luogo : '');

        if ($assemblea->seconda_convocazione_at) {
            $descrizione .= "\nSeconda convocazione: ".$assemblea->seconda_convocazione_at->format('d/m/Y H:i')
                .(($assemblea->seconda_luogo ?: $assemblea->prima_luogo) ? ' – '.($assemblea->seconda_luogo ?: $assemblea->prima_luogo) : '');
        }

        $dati = [
            'title' => $assemblea->titoloLeggibile(),
            'description' => $descrizione,
            'start_time' => $inizio,
            'end_time' => $inizio?->copy()->addHours(2),
            'created_by' => $autore,
            'category_id' => $categoria->id,
            'visibility' => VisibilityStatus::PUBLIC->value,
            'is_approved' => true,
            'timezone' => config('app.timezone'),
            'eventable_type' => Assemblea::class,
            'eventable_id' => $assemblea->id,
        ];

        $evento = $assemblea->evento_id ? Evento::find($assemblea->evento_id) : null;

        if ($evento) {
            $evento->update($dati);
        } else {
            $evento = Evento::create($dati);
            $assemblea->forceFill(['evento_id' => $evento->id])->saveQuietly();
        }

        $evento->condomini()->syncWithoutDetaching([$assemblea->condominio_id]);
    }

    public function rimuovi(Assemblea $assemblea): void
    {
        if (! $assemblea->evento_id) {
            return;
        }

        $evento = Evento::find($assemblea->evento_id);
        $assemblea->forceFill(['evento_id' => null])->saveQuietly();

        if ($evento) {
            $evento->condomini()->detach();
            $evento->delete();
        }
    }
}
