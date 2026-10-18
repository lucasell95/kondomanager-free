<?php

namespace App\Services\Assemblee;

/**
 * Una «testa» ai fini dei quorum: un condòmino, oppure il gruppo dei comproprietari che votano
 * insieme (art. 67 c. 2 disp. att.: un solo rappresentante), con il valore di tutte le sue unità.
 *
 * Chi possiede due unità vale **una** testa con la somma dei millesimi; le teste si contano per
 * persone, non per unità (vedi `analisi/ricerca-ia-assemblee.md` §1.1).
 *
 * `valore` è in centomillesimi interi (`App\Support\Assemblee\Millesimi`).
 */
final class TestaAssemblea
{
    /**
     * @param  list<int>  $anagraficaIds
     * @param  list<string>  $unita
     */
    public function __construct(
        public readonly string $chiave,
        public readonly string $etichetta,
        public readonly int $valore,
        public readonly bool $presente = false,
        public readonly ?string $voto = null,
        public readonly bool $conflitto = false,
        public readonly array $anagraficaIds = [],
        public readonly array $unita = [],
        public readonly ?string $rappresentante = null,
    ) {}

    public function con(?bool $presente = null, ?string $voto = null, ?bool $conflitto = null, bool $azzeraVoto = false): self
    {
        return new self(
            $this->chiave,
            $this->etichetta,
            $this->valore,
            $presente ?? $this->presente,
            $azzeraVoto ? null : ($voto ?? $this->voto),
            $conflitto ?? $this->conflitto,
            $this->anagraficaIds,
            $this->unita,
            $this->rappresentante,
        );
    }

    public function toArray(): array
    {
        return [
            'chiave' => $this->chiave,
            'etichetta' => $this->etichetta,
            'valore' => $this->valore,
            'presente' => $this->presente,
            'voto' => $this->voto,
            'conflitto' => $this->conflitto,
            'anagrafica_ids' => $this->anagraficaIds,
            'unita' => $this->unita,
            'rappresentante' => $this->rappresentante,
        ];
    }
}
