<?php

namespace App\Services\Assemblee;

use App\Enums\Assemblee\Convocazione;
use App\Enums\Assemblee\EspressioneVoto;
use App\Enums\Assemblee\MateriaDelibera;
use App\Support\Assemblee\Millesimi;

/**
 * # Quorum costitutivi e deliberativi (art. 1136 c.c.)
 *
 * PHP puro, nessun accesso al database: riceve le teste (`TestaAssemblea`) e il totale del valore
 * della tabella, restituisce l'esito **e la spiegazione del calcolo**, perché con sei condòmini una
 * testa sola cambia l'esito e chi presiede deve poterlo verificare a mano.
 *
 * ## Regole applicate
 *
 * | | Costitutivo | Deliberativo (ordinaria) |
 * |---|---|---|
 * | 1ª convocazione (c. 1-2) | più della metà dei partecipanti **e** almeno 2/3 del valore | più della metà degli intervenuti **e** almeno 1/2 del valore |
 * | 2ª convocazione (c. 3) | almeno 1/3 dei partecipanti **e** almeno 1/3 del valore | più della metà degli intervenuti **e** almeno 1/3 del valore |
 *
 * Le altre maggioranze (c. 4, c. 5, 1117-ter, unanimità) stanno in `MateriaDelibera::regola()`.
 *
 * ## Scelte
 *
 * - **Confronti su interi, mai divisioni** (`RegolaMaggioranza`): «almeno un terzo» è `3 × m ≥ T`.
 * - **Astenuti e voti non espressi contano fra gli intervenuti**: la maggioranza è degli intervenuti,
 *   non dei votanti. Chi è uscito prima del voto non è intervenuto su quel punto (Cass. 4191/2024):
 *   la presenza si registra punto per punto.
 * - **Conflitto d'interessi**: il voto in conflitto si conta (la legge non lo esclude), ma si fa la
 *   **prova di resistenza** — l'esito ricalcolato senza quei voti. Se cambia, la delibera è
 *   annullabile qualora il conflitto sia concreto e la delibera dannosa (Cass. 10754/2011,
 *   Cass. 19131/2015). Il programma non decide se il conflitto c'è: lo segnala.
 */
class QuorumService
{
    /**
     * Quorum costitutivo all'apertura dell'assemblea.
     *
     * @param  list<TestaAssemblea>  $teste  tutte le teste del condominio, con `presente`
     */
    public function costitutivo(array $teste, int $totaleValore, Convocazione $convocazione, int $decimali = 3): array
    {
        $partecipanti = count($teste);
        $presenti = array_values(array_filter($teste, fn (TestaAssemblea $t) => $t->presente));
        $testePresenti = count($presenti);
        $valorePresente = array_sum(array_map(fn (TestaAssemblea $t) => $t->valore, $presenti));

        $regola = $convocazione === Convocazione::PRIMA
            ? new RegolaMaggioranza(RegolaMaggioranza::BASE_PARTECIPANTI, 1, 2, true, 2, 3, false, false,
                'Maggioranza dei partecipanti e almeno due terzi del valore', 'art. 1136 c. 1 c.c.')
            : new RegolaMaggioranza(RegolaMaggioranza::BASE_PARTECIPANTI, 1, 3, false, 1, 3, false, false,
                'Almeno un terzo dei partecipanti e almeno un terzo del valore', 'art. 1136 c. 3 c.c.');

        $requisiti = [
            $this->requisitoTeste($regola, $testePresenti, $partecipanti, 'presenti', 'partecipanti'),
            $this->requisitoValore($regola, $valorePresente, $totaleValore, 'presenti', $decimali),
        ];

        $raggiunto = $requisiti[0]['raggiunto'] && $requisiti[1]['raggiunto'];

        return [
            'convocazione' => $convocazione->value,
            'raggiunto' => $raggiunto,
            'regola' => $regola->descrizione,
            'norma' => $regola->norma,
            'teste_presenti' => $testePresenti,
            'teste_totali' => $partecipanti,
            'valore_presente' => $valorePresente,
            'valore_totale' => $totaleValore,
            'valore_presente_testo' => Millesimi::formatta($valorePresente, $decimali),
            'valore_totale_testo' => Millesimi::formatta($totaleValore, $decimali),
            'requisiti' => $requisiti,
            'spiegazione' => $this->spiegazione(
                $convocazione->label().' — '.$regola->descrizione.' ('.$regola->norma.')',
                $requisiti,
                $raggiunto ? 'Assemblea validamente costituita.' : 'Quorum costitutivo NON raggiunto: l\'assemblea non è validamente costituita.'
            ),
        ];
    }

    /**
     * Esito della votazione di un punto.
     *
     * @param  list<TestaAssemblea>  $teste  tutte le teste del condominio per il regime di voto della
     *                                       materia, con presenza e voto **al momento del voto**
     */
    public function deliberativo(array $teste, int $totaleValore, MateriaDelibera $materia, Convocazione $convocazione, int $decimali = 3): array
    {
        $regola = $materia->regola($convocazione);

        if ($regola === null) {
            return [
                'esito' => 'senza_votazione',
                'materia' => $materia->value,
                'materia_label' => $materia->label(),
                'regola' => null,
                'norma' => $materia->norma(),
                'spiegazione' => 'Punto informativo: nessuna votazione.',
                'requisiti' => [],
                'intervenuti' => $this->conteggio(array_filter($teste, fn ($t) => $t->presente), $decimali),
                'favorevoli' => $this->conteggio([], $decimali),
                'contrari' => $this->conteggio([], $decimali),
                'astenuti' => $this->conteggio([], $decimali),
                'non_espressi' => $this->conteggio([], $decimali),
                'conflitto' => null,
                'decisivi' => [],
            ];
        }

        $calcolo = $this->calcola($teste, $totaleValore, $regola, $decimali);

        // Prova di resistenza: i voti in conflitto si neutralizzano (restano intervenuti, come astenuti).
        $inConflitto = array_values(array_filter($teste, fn (TestaAssemblea $t) => $t->presente && $t->conflitto && $t->voto !== null));
        $conflitto = null;

        if ($inConflitto !== []) {
            $senza = array_map(
                fn (TestaAssemblea $t) => $t->conflitto && $t->presente && $t->voto !== null
                    ? $t->con(voto: EspressioneVoto::ASTENUTO->value)
                    : $t,
                $teste
            );
            $calcoloSenza = $this->calcola($senza, $totaleValore, $regola, $decimali);
            $determinante = $calcoloSenza['esito'] !== $calcolo['esito'];

            $conflitto = [
                'teste' => array_map(fn (TestaAssemblea $t) => $t->etichetta, $inConflitto),
                'esito_senza' => $calcoloSenza['esito'],
                'determinante' => $determinante,
                'spiegazione' => $determinante
                    ? 'Prova di resistenza: senza i voti in conflitto d\'interessi la delibera risulterebbe '
                        .strtoupper($this->etichettaEsito($calcoloSenza['esito'])).'. Il voto in conflitto è determinante: '
                        .'se il conflitto è concreto e la delibera è dannosa per il condominio, è annullabile (Cass. 10754/2011).'
                    : 'Prova di resistenza superata: anche senza i voti in conflitto d\'interessi l\'esito non cambia.',
                'requisiti_senza' => $calcoloSenza['requisiti'],
            ];
        }

        // Chi è decisivo: il favorevole senza il cui voto la delibera cadrebbe.
        $decisivi = [];
        if ($calcolo['esito'] === 'approvata') {
            foreach ($teste as $i => $t) {
                if (! $t->presente || $t->voto !== EspressioneVoto::FAVOREVOLE->value) {
                    continue;
                }
                $variante = $teste;
                $variante[$i] = $t->con(voto: EspressioneVoto::ASTENUTO->value);
                if ($this->calcola($variante, $totaleValore, $regola, $decimali)['esito'] !== 'approvata') {
                    $decisivi[] = $t->etichetta;
                }
            }
        }

        $titolo = $convocazione->label().' — '.$materia->label().': '.$regola->descrizione.' ('.$regola->norma.')';
        $chiusura = match ($calcolo['esito']) {
            'approvata' => 'Delibera APPROVATA.',
            'respinta' => 'Delibera NON approvata.',
            default => 'Votazione non ancora espressa.',
        };

        $spiegazione = $this->spiegazione($titolo, $calcolo['requisiti'], $chiusura);
        if ($conflitto !== null) {
            $spiegazione .= "\n".$conflitto['spiegazione'];
        }
        if ($decisivi !== []) {
            $spiegazione .= "\nVoto decisivo: ".implode(', ', $decisivi).' (senza il loro voto favorevole la delibera non passerebbe).';
        }

        return [
            'esito' => $calcolo['esito'],
            'materia' => $materia->value,
            'materia_label' => $materia->label(),
            'regola' => $regola->descrizione,
            'norma' => $regola->norma,
            'requisiti' => $calcolo['requisiti'],
            'intervenuti' => $calcolo['intervenuti'],
            'favorevoli' => $calcolo['favorevoli'],
            'contrari' => $calcolo['contrari'],
            'astenuti' => $calcolo['astenuti'],
            'non_espressi' => $calcolo['non_espressi'],
            'conflitto' => $conflitto,
            'decisivi' => $decisivi,
            'spiegazione' => $spiegazione,
        ];
    }

    /** @param list<TestaAssemblea> $teste */
    private function calcola(array $teste, int $totaleValore, RegolaMaggioranza $regola, int $decimali): array
    {
        $presenti = array_values(array_filter($teste, fn (TestaAssemblea $t) => $t->presente));
        $per = fn (?string $voto) => array_values(array_filter($presenti, fn (TestaAssemblea $t) => $t->voto === $voto));

        $favorevoli = $per(EspressioneVoto::FAVOREVOLE->value);
        $contrari = $per(EspressioneVoto::CONTRARIO->value);
        $astenuti = $per(EspressioneVoto::ASTENUTO->value);
        $nonEspressi = $per(null);

        $testeFavorevoli = count($favorevoli);
        $valoreFavorevoli = array_sum(array_map(fn ($t) => $t->valore, $favorevoli));

        $base = $regola->baseTeste === RegolaMaggioranza::BASE_INTERVENUTI ? count($presenti) : count($teste);
        $nomeBase = $regola->baseTeste === RegolaMaggioranza::BASE_INTERVENUTI ? 'intervenuti' : 'partecipanti';

        $requisiti = [
            $this->requisitoTeste($regola, $testeFavorevoli, $base, 'favorevoli', $nomeBase),
            $this->requisitoValore($regola, $valoreFavorevoli, $totaleValore, 'favorevoli', $decimali),
        ];

        $votiEspressi = count($favorevoli) + count($contrari) + count($astenuti);

        $esito = $votiEspressi === 0
            ? 'non_votata'
            : (($requisiti[0]['raggiunto'] && $requisiti[1]['raggiunto']) ? 'approvata' : 'respinta');

        return [
            'esito' => $esito,
            'requisiti' => $requisiti,
            'intervenuti' => $this->conteggio($presenti, $decimali),
            'favorevoli' => $this->conteggio($favorevoli, $decimali),
            'contrari' => $this->conteggio($contrari, $decimali),
            'astenuti' => $this->conteggio($astenuti, $decimali),
            'non_espressi' => $this->conteggio($nonEspressi, $decimali),
        ];
    }

    /** @param iterable<TestaAssemblea> $teste */
    private function conteggio(iterable $teste, int $decimali): array
    {
        $teste = is_array($teste) ? array_values($teste) : iterator_to_array($teste, false);
        $valore = array_sum(array_map(fn (TestaAssemblea $t) => $t->valore, $teste));

        return [
            'teste' => count($teste),
            'valore' => $valore,
            'valore_testo' => Millesimi::formatta($valore, $decimali),
            'nomi' => array_map(fn (TestaAssemblea $t) => [
                'chiave' => $t->chiave,
                'etichetta' => $t->etichetta,
                'valore_testo' => Millesimi::formatta($t->valore, $decimali),
                'rappresentante' => $t->rappresentante,
            ], $teste),
        ];
    }

    private function requisitoTeste(RegolaMaggioranza $r, int $teste, int $base, string $chi, string $nomeBase): array
    {
        $raggiunto = $r->testeRaggiunte($teste, $base);
        $operatore = $r->testeStretta ? ($raggiunto ? '>' : '≤') : ($raggiunto ? '≥' : '<');

        return [
            'oggetto' => 'teste',
            'richiesto' => $r->testoTeste(),
            'calcolo' => sprintf(
                '%s %d su %d %s: %d × %d = %d %s %d × %d = %d',
                $chi, $teste, $base, $nomeBase,
                $teste, $r->testeDen, $teste * $r->testeDen,
                $operatore,
                $r->testeNum, $base, $r->testeNum * $base
            ),
            'raggiunto' => $raggiunto,
        ];
    }

    private function requisitoValore(RegolaMaggioranza $r, int $valore, int $totale, string $chi, int $decimali): array
    {
        $raggiunto = $r->valoreRaggiunto($valore, $totale);
        $operatore = $r->valoreStretta ? ($raggiunto ? '>' : '≤') : ($raggiunto ? '≥' : '<');
        $f = fn (int $v) => Millesimi::formatta($v, $decimali);

        return [
            'oggetto' => 'valore',
            'richiesto' => $r->testoValore(),
            'calcolo' => sprintf(
                '%s %s su %s millesimi: %s × %d = %s %s %d × %s = %s',
                $chi, $f($valore), $f($totale),
                $f($valore), $r->valoreDen, $f($valore * $r->valoreDen),
                $operatore,
                $r->valoreNum, $f($totale), $f($r->valoreNum * $totale)
            ),
            'raggiunto' => $raggiunto,
        ];
    }

    private function spiegazione(string $titolo, array $requisiti, string $chiusura): string
    {
        $righe = [$titolo];
        foreach ($requisiti as $req) {
            $righe[] = sprintf(
                '• %s richiesti %s — %s → %s',
                $req['oggetto'] === 'teste' ? 'Teste:' : 'Millesimi:',
                $req['richiesto'],
                $req['calcolo'],
                $req['raggiunto'] ? 'raggiunto' : 'NON raggiunto'
            );
        }
        $righe[] = $chiusura;

        return implode("\n", $righe);
    }

    public function etichettaEsito(string $esito): string
    {
        return match ($esito) {
            'approvata' => 'approvata',
            'respinta' => 'non approvata',
            'non_votata' => 'non votata',
            'senza_votazione' => 'senza votazione',
            default => $esito,
        };
    }
}
