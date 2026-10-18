<?php

namespace App\Services\Assemblee;

use App\Enums\RuoloAnagraficaImmobile;
use App\Models\Assemblee\Assemblea;
use App\Models\Condominio;
use App\Models\QuotaTabella;
use App\Models\Tabella;
use App\Support\Assemblee\Millesimi;
use Illuminate\Support\Facades\DB;

/**
 * Fotografa unità, titolari e millesimi del condominio al momento dell'assemblea.
 *
 * ## Quale tabella
 *
 * Il valore per i quorum è quello della **tabella generale di proprietà** (1000‰). KondoManager non
 * ha un indicatore «proprietà generale», quindi la si riconosce così, in ordine:
 *
 * 1. la tabella scelta esplicitamente sull'assemblea (`assemblee.tabella_id`);
 * 2. fra le tabelle attive del condominio in millesimi, di tipo `standard`, non legate a una
 *    palazzina o a una scala, quella il cui nome contiene «propriet» o «general»;
 * 3. altrimenti la prima di quelle (per id).
 *
 * ## Chi sono i titolari
 *
 * Dal pivot `anagrafica_immobile` attivo: `proprietario` e `nuda_proprietario` sono i titolari della
 * proprietà, `usufruttuario` è tenuto a parte perché vota solo su alcune materie
 * (`MateriaDelibera::votaUsufruttuario()`). L'inquilino non vota (salvo riscaldamento: limite noto).
 */
class FotografiaUnitaService
{
    public function tabellaProprieta(Condominio $condominio): ?Tabella
    {
        $candidate = Tabella::query()
            ->where('condominio_id', $condominio->id)
            ->where('quota', 'millesimi')
            ->where('attiva', true)
            ->whereNull('palazzina_id')
            ->whereNull('scala_id')
            ->orderBy('id')
            ->get();

        $standard = $candidate->where('tipo', 'standard');
        $pool = $standard->isNotEmpty() ? $standard : $candidate;

        return $pool->first(function (Tabella $t) {
            $nome = mb_strtolower($t->nome);

            return str_contains($nome, 'propriet') || str_contains($nome, 'general');
        }) ?? $pool->first();
    }

    /** La tabella su cui si contano i quorum di questa assemblea. */
    public function tabellaDi(Assemblea $assemblea): ?Tabella
    {
        if ($assemblea->tabella_id) {
            return $assemblea->tabella;
        }

        return $this->tabellaProprieta($assemblea->condominio);
    }

    /** Valori (centomillesimi) di una tabella per immobile. @return array<int,int> */
    public function valoriTabella(?Tabella $tabella): array
    {
        if (! $tabella) {
            return [];
        }

        return QuotaTabella::where('tabella_id', $tabella->id)
            ->get(['immobile_id', 'valore', 'escluso'])
            ->mapWithKeys(fn (QuotaTabella $q) => [
                $q->immobile_id => $q->escluso ? 0 : Millesimi::daDecimale($q->getRawOriginal('valore')),
            ])
            ->all();
    }

    /** Rifà la fotografia da capo: unità attive del condominio, titolari attivi, millesimi della tabella. */
    public function fotografa(Assemblea $assemblea): void
    {
        $assemblea->loadMissing('condominio');
        $tabella = $this->tabellaDi($assemblea);

        if ($tabella && ! $assemblea->tabella_id) {
            $assemblea->forceFill(['tabella_id' => $tabella->id])->save();
        }

        $valori = $this->valoriTabella($tabella);

        $immobili = $assemblea->condominio->immobili()
            ->where('attivo', true)
            ->with('anagrafiche')
            ->orderBy('id')
            ->get();

        DB::transaction(function () use ($assemblea, $immobili, $valori) {
            $assemblea->unita()->delete();

            foreach ($immobili as $immobile) {
                $proprietari = [];
                $usufruttuari = [];

                foreach ($immobile->anagrafiche as $anagrafica) {
                    if (! $anagrafica->pivot->attivo) {
                        continue;
                    }

                    $ruolo = RuoloAnagraficaImmobile::tryFrom((string) $anagrafica->pivot->tipologia);

                    if (in_array($ruolo, [RuoloAnagraficaImmobile::PROPRIETARIO, RuoloAnagraficaImmobile::NUDA_PROPRIETA], true)) {
                        $proprietari[] = $anagrafica->id;
                    } elseif ($ruolo === RuoloAnagraficaImmobile::USUFRUTTUARIO) {
                        $usufruttuari[] = $anagrafica->id;
                    }
                }

                $assemblea->unita()->create([
                    'immobile_id' => $immobile->id,
                    'etichetta' => $immobile->etichetta_estesa,
                    'valore' => $valori[$immobile->id] ?? 0,
                    'proprietari' => array_values(array_unique($proprietari)),
                    'usufruttuari' => array_values(array_unique($usufruttuari)),
                ]);
            }
        });

        $assemblea->unsetRelation('unita');
    }

    /** La fotografia c'è? Se no la si fa ora. */
    public function assicura(Assemblea $assemblea): void
    {
        if (! $assemblea->unita()->exists()) {
            $this->fotografa($assemblea);
        }
    }
}
