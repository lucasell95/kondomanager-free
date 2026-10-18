<?php

namespace App\Http\Controllers\Gestionale\Banca\Concerns;

use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Models\Gestionale\Banca\MovimentoBancario;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;

/**
 * Ciò che le tre pagine della banca hanno in comune: le casse importabili, i dati dell'intestazione
 * del gestionale e la guardia di appartenenza dei movimenti.
 */
trait DatiBanca
{
    /**
     * Il movimento, solo se è di questo condominio. È la guardia scritta a mano accanto allo
     * scoping delle rotte: qui il parametro è un id grezzo, quindi lo scoping non lo copre.
     */
    protected function assicuraMovimento(Condominio $condominio, int|string $movimento): MovimentoBancario
    {
        return MovimentoBancario::where('condominio_id', $condominio->id)->findOrFail((int) $movimento);
    }

    /** Le casse che hanno un estratto conto: i fondi sono partizioni del c/c, non conti. */
    protected function casseImportabili(Condominio $condominio)
    {
        return Cassa::where('condominio_id', $condominio->id)
            ->whereIn('tipo', ['banca', 'contanti', 'virtuale'])
            ->where('attiva', true)
            ->with('contoCorrente')
            ->orderByRaw("CASE WHEN tipo = 'banca' THEN 0 ELSE 1 END")
            ->orderBy('nome')
            ->get()
            ->map(fn (Cassa $c) => [
                'id' => $c->id,
                'nome' => $c->nome,
                'tipo' => $c->tipo,
                'iban' => $c->contoCorrente?->iban,
            ])
            ->values();
    }

    /** Le props che l'intestazione del gestionale (`PageHeaderGuide`) si aspetta. */
    protected function datiIntestazione(Condominio $condominio): array
    {
        return [
            'condominio' => $condominio,
            'condomini' => CondominioResource::collection($this->getCondomini())->resolve(),
            'esercizio' => $this->getEsercizioCorrente($condominio),
            'esercizi' => $condominio->esercizi()->orderBy('data_inizio', 'desc')->get(['id', 'nome', 'stato']),
        ];
    }

    /** I capitoli di spesa per la registrazione delle commissioni: stessi della regolazione immediata. */
    protected function capitoliDiSpesa(Condominio $condominio)
    {
        return Conto::whereIn('piano_conto_id', $condominio->pianiDeiConti()->pluck('id'))
            ->whereDoesntHave('sottoconti')
            ->whereNotNull('conto_contabile_id')
            ->visibili()
            ->with('parent:id,nome')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'label' => $c->parent ? $c->parent->nome.' — '.$c->nome : $c->nome,
                'gestione_id' => $c->pianoConto?->gestione_id,
            ])
            ->sortBy('label')
            ->values();
    }

    protected function gestioniAttive(Condominio $condominio)
    {
        return $condominio->gestioni()
            ->where('gestioni.attiva', true)
            ->get(['gestioni.id', 'gestioni.nome', 'gestioni.tipo']);
    }
}
