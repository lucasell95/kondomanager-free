<?php

namespace App\Services\Assemblee;

use App\Enums\Assemblee\ModoPresenza;
use App\Models\Anagrafica;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\PresenzaAssemblea;
use App\Models\Assemblee\PuntoOdg;
use App\Models\Assemblee\UnitaAssemblea;
use Illuminate\Support\Collection;

/**
 * Dalla fotografia delle unità alle «teste» su cui `QuorumService` conta.
 *
 * ## Le teste dipendono dalla materia
 *
 * Con un usufrutto, sull'ordinaria amministrazione vota l'usufruttuario e sul resto il nudo
 * proprietario (art. 67 c. 6-7 disp. att.). Quindi chi «è» una testa cambia da un punto all'altro:
 *
 * - regime `proprieta`: per ogni unità votano proprietari e nudi proprietari;
 * - regime `godimento`: votano gli usufruttuari, se l'unità ne ha; altrimenti i proprietari.
 *
 * Le unità che hanno **lo stesso insieme di votanti** formano una sola testa con la somma dei
 * millesimi: chi possiede due unità vale una testa, i comproprietari di un'unità votano insieme.
 * Unità con insiemi diversi (Rossi da solo su U1, Rossi e Bianchi su U3) restano teste distinte:
 * è un'approssimazione dichiarata, sul punto la giurisprudenza non dà una regola univoca.
 *
 * ## La presenza
 *
 * - all'apertura (quorum costitutivo): la testa è presente se è presente — in proprio o per
 *   delega — almeno una delle persone titolari delle sue unità, usufruttuari compresi;
 * - su un punto: vale la presenza registrata per quel punto (`assemblea_voti.presente`); se non è
 *   stata registrata, quella di apertura, tolto chi ha un'ora di uscita.
 */
class TesteAssembleaService
{
    public const REGIME_PROPRIETA = 'proprieta';
    public const REGIME_GODIMENTO = 'godimento';

    public function __construct(private FotografiaUnitaService $fotografia) {}

    /**
     * Le teste per il quorum costitutivo, con la presenza di apertura.
     *
     * @return array{teste: list<TestaAssemblea>, totale: int}
     */
    public function testeApertura(Assemblea $assemblea): array
    {
        $unita = $this->unita($assemblea);
        $presenze = $this->presenze($assemblea);
        $nomi = $this->nomi($unita);

        $gruppi = $this->raggruppa($unita, self::REGIME_PROPRIETA);
        $teste = [];

        foreach ($gruppi as $chiave => $gruppo) {
            $titolari = $gruppo['tutti_titolari'];
            $presente = collect($titolari)->contains(fn ($id) => $this->intervenuto($presenze->get($id)));

            $teste[] = new TestaAssemblea(
                chiave: $chiave,
                etichetta: $this->etichetta($gruppo, $nomi),
                valore: $gruppo['valore'],
                presente: $presente,
                anagraficaIds: $gruppo['votanti'],
                unita: $gruppo['etichette_unita'],
                rappresentante: $this->rappresentante($gruppo['votanti'], $presenze),
            );
        }

        return ['teste' => $teste, 'totale' => $unita->sum('valore')];
    }

    /**
     * Le teste che votano su un punto, con presenza e voto registrati per quel punto.
     *
     * @return array{teste: list<TestaAssemblea>, totale: int}
     */
    public function testePunto(Assemblea $assemblea, PuntoOdg $punto, ?array $sovrascrivi = null): array
    {
        $unita = $this->unita($assemblea);

        // Tabella diversa per il punto (parti comuni solo ad alcuni): si sostituiscono i valori.
        if ($punto->tabella_id && $punto->tabella_id !== $assemblea->tabella_id) {
            $valori = $this->fotografia->valoriTabella($punto->tabella);
            $unita = $unita->map(function (UnitaAssemblea $u) use ($valori) {
                $copia = clone $u;
                $copia->valore = $valori[$u->immobile_id] ?? 0;

                return $copia;
            });
        }

        $regime = $punto->materia?->votaUsufruttuario() ? self::REGIME_GODIMENTO : self::REGIME_PROPRIETA;
        $presenze = $this->presenze($assemblea);
        $nomi = $this->nomi($unita);
        $voti = $sovrascrivi !== null
            ? collect($sovrascrivi)->keyBy('chiave_testa')
            : $punto->voti()->get()->keyBy('chiave_testa');

        $teste = [];
        foreach ($this->raggruppa($unita, $regime) as $chiave => $gruppo) {
            $registrato = $voti->get($chiave);

            $presenteDefault = collect($gruppo['votanti'])->contains(function ($id) use ($presenze) {
                $p = $presenze->get($id);

                return $this->intervenuto($p) && blank($p->ora_uscita);
            });

            $presente = $registrato !== null ? (bool) data_get($registrato, 'presente') : $presenteDefault;
            $voto = $registrato !== null ? data_get($registrato, 'voto') : null;

            $teste[] = new TestaAssemblea(
                chiave: $chiave,
                etichetta: $this->etichetta($gruppo, $nomi),
                valore: $gruppo['valore'],
                presente: $presente,
                voto: $presente ? ($voto ?: null) : null,
                conflitto: (bool) data_get($registrato, 'conflitto', false),
                anagraficaIds: $gruppo['votanti'],
                unita: $gruppo['etichette_unita'],
                rappresentante: $this->rappresentante($gruppo['votanti'], $presenze),
            );
        }

        return ['teste' => $teste, 'totale' => $unita->sum('valore')];
    }

    /**
     * Le persone titolari (proprietari, nudi proprietari, usufruttuari), per la schermata delle
     * presenze e per i destinatari della convocazione.
     *
     * @return list<array{anagrafica_id:int,nome:string,unita:list<string>,ruoli:list<string>}>
     */
    public function persone(Assemblea $assemblea): array
    {
        $unita = $this->unita($assemblea);
        $nomi = $this->nomi($unita);
        $persone = [];

        foreach ($unita as $u) {
            foreach ((array) $u->proprietari as $id) {
                $persone[$id]['unita'][] = $u->etichetta;
                $persone[$id]['ruoli'][] = 'proprietario';
            }
            foreach ((array) $u->usufruttuari as $id) {
                $persone[$id]['unita'][] = $u->etichetta;
                $persone[$id]['ruoli'][] = 'usufruttuario';
            }
        }

        $risultato = [];
        foreach ($persone as $id => $dati) {
            $risultato[] = [
                'anagrafica_id' => (int) $id,
                'nome' => $nomi[$id] ?? ('Anagrafica #'.$id),
                'unita' => array_values(array_unique($dati['unita'])),
                'ruoli' => array_values(array_unique($dati['ruoli'])),
            ];
        }

        usort($risultato, fn ($a, $b) => strcmp($a['nome'], $b['nome']));

        return $risultato;
    }

    /** @return Collection<int, UnitaAssemblea> */
    public function unita(Assemblea $assemblea): Collection
    {
        $this->fotografia->assicura($assemblea);

        return $assemblea->unita()->get();
    }

    /** @return Collection<int, PresenzaAssemblea> keyed by anagrafica_id */
    public function presenze(Assemblea $assemblea): Collection
    {
        return $assemblea->presenze()->with('delegatoAnagrafica:id,nome')->get()->keyBy('anagrafica_id');
    }

    /**
     * @param  Collection<int, UnitaAssemblea>  $unita
     * @return array<string, array{votanti:list<int>,tutti_titolari:list<int>,valore:int,etichette_unita:list<string>}>
     */
    private function raggruppa(Collection $unita, string $regime): array
    {
        $gruppi = [];

        foreach ($unita as $u) {
            $proprietari = array_map('intval', (array) $u->proprietari);
            $usufruttuari = array_map('intval', (array) $u->usufruttuari);

            $votanti = ($regime === self::REGIME_GODIMENTO && $usufruttuari !== [])
                ? $usufruttuari
                : ($proprietari !== [] ? $proprietari : $usufruttuari);

            sort($votanti);
            $chiave = $votanti === [] ? 'u'.$u->id : implode('-', array_unique($votanti));

            $gruppi[$chiave] ??= ['votanti' => array_values(array_unique($votanti)), 'tutti_titolari' => [], 'valore' => 0, 'etichette_unita' => []];
            $gruppi[$chiave]['valore'] += (int) $u->valore;
            $gruppi[$chiave]['etichette_unita'][] = $u->etichetta;
            $gruppi[$chiave]['tutti_titolari'] = array_values(array_unique(array_merge(
                $gruppi[$chiave]['tutti_titolari'], $proprietari, $usufruttuari
            )));
        }

        return $gruppi;
    }

    /** @param Collection<int, UnitaAssemblea> $unita */
    private function nomi(Collection $unita): array
    {
        $ids = $unita->flatMap(fn ($u) => array_merge((array) $u->proprietari, (array) $u->usufruttuari))->unique()->values();

        return Anagrafica::whereIn('id', $ids)->pluck('nome', 'id')->all();
    }

    private function etichetta(array $gruppo, array $nomi): string
    {
        $persone = array_map(fn ($id) => $nomi[$id] ?? ('Anagrafica #'.$id), $gruppo['votanti']);
        $chi = $persone === [] ? 'Nessun titolare' : implode(' e ', $persone);

        return $chi.' ('.implode(', ', $gruppo['etichette_unita']).')';
    }

    private function intervenuto(?PresenzaAssemblea $p): bool
    {
        return $p !== null && $p->modo instanceof ModoPresenza && $p->modo->intervenuto();
    }

    /** «per delega a Bianchi» se tutti i votanti presenti lo sono per delega. */
    private function rappresentante(array $votanti, Collection $presenze): ?string
    {
        $intervenuti = collect($votanti)->map(fn ($id) => $presenze->get($id))->filter(fn ($p) => $this->intervenuto($p));

        if ($intervenuti->isEmpty() || $intervenuti->contains(fn ($p) => $p->modo === ModoPresenza::PRESENTE)) {
            return null;
        }

        $delegati = $intervenuti->map(fn (PresenzaAssemblea $p) => $p->nomeDelegato())->filter()->unique()->implode(', ');

        return $delegati !== '' ? 'per delega a '.$delegati : null;
    }
}
