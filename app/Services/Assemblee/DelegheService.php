<?php

namespace App\Services\Assemblee;

use App\Enums\Assemblee\ModoPresenza;
use App\Models\Anagrafica;
use App\Models\Assemblee\Assemblea;
use App\Support\Assemblee\Millesimi;

/**
 * Controlli sulle deleghe (art. 67 disp. att. c.c.).
 *
 * - c. 1: la delega è **scritta**. Se più di venti condòmini, il delegato non può rappresentare più
 *   di un quinto dei condòmini **e** del valore proporzionale.
 * - c. 5: all'amministratore non possono essere conferite deleghe, per nessuna assemblea. Vale
 *   anche quando l'amministratore è un condòmino, che è il caso del condominio minimo autogestito.
 *
 * Il quinto si conta sulle teste del regime «proprietà» e sul valore della tabella dell'assemblea,
 * con il confronto su interi `5 × rappresentati ≤ partecipanti`.
 */
class DelegheService
{
    public const SOGLIA_CONDOMINI = 20;

    public function __construct(private TesteAssembleaService $teste) {}

    /**
     * Errori della delega che `$anagraficaId` vuole conferire. Lista vuota = delega ammessa.
     *
     * @return list<string>
     */
    public function errori(Assemblea $assemblea, int $anagraficaId, ?int $delegatoId, ?string $delegatoNome, bool $scritta = true): array
    {
        $errori = [];
        $delegatoNome = $delegatoNome !== null ? trim($delegatoNome) : null;

        if (! $delegatoId && blank($delegatoNome)) {
            return ['Indicare a chi è conferita la delega.'];
        }

        if ($delegatoId && $delegatoId === $anagraficaId) {
            $errori[] = 'Il condòmino non può delegare sé stesso.';
        }

        if (! $scritta) {
            $errori[] = 'La delega deve essere conferita per iscritto (art. 67 c. 1 disp. att. c.c.).';
        }

        if ($this->eAmministratore($assemblea, $delegatoId, $delegatoNome)) {
            $errori[] = 'All\'amministratore non possono essere conferite deleghe (art. 67 c. 5 disp. att. c.c.), nemmeno se è anche condòmino.';
        }

        $limite = $this->erroreLimiteQuinto($assemblea, $anagraficaId, $delegatoId, $delegatoNome);
        if ($limite) {
            $errori[] = $limite;
        }

        return $errori;
    }

    public function eAmministratore(Assemblea $assemblea, ?int $delegatoId, ?string $delegatoNome): bool
    {
        if ($delegatoId && $assemblea->amministratore_anagrafica_id && $delegatoId === (int) $assemblea->amministratore_anagrafica_id) {
            return true;
        }

        $nomiAmministratore = array_filter([
            $this->normalizza($assemblea->amministratore_nome),
            $this->normalizza($assemblea->amministratoreAnagrafica?->nome),
        ]);

        if ($nomiAmministratore === []) {
            return false;
        }

        $nomeDelegato = $this->normalizza($delegatoId ? Anagrafica::whereKey($delegatoId)->value('nome') : $delegatoNome);

        return $nomeDelegato !== null && in_array($nomeDelegato, $nomiAmministratore, true);
    }

    /**
     * Il limite di un quinto (solo con più di venti condòmini): considera le deleghe già registrate
     * allo stesso delegato più quella nuova.
     */
    public function erroreLimiteQuinto(Assemblea $assemblea, int $anagraficaId, ?int $delegatoId, ?string $delegatoNome): ?string
    {
        ['teste' => $teste, 'totale' => $totale] = $this->teste->testeApertura($assemblea);
        $partecipanti = count($teste);

        if ($partecipanti <= self::SOGLIA_CONDOMINI) {
            return null;
        }

        $chiaveDelegato = $this->chiaveDelegato($delegatoId, $delegatoNome);

        // Deleganti: quelli già registrati verso lo stesso delegato, più il nuovo.
        $deleganti = $assemblea->presenze()
            ->where('modo', ModoPresenza::DELEGATO->value)
            ->get()
            ->filter(fn ($p) => $this->chiaveDelegato($p->delegato_anagrafica_id, $p->delegato_nome) === $chiaveDelegato)
            ->pluck('anagrafica_id')
            ->push($anagraficaId)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        $rappresentate = array_values(array_filter(
            $teste,
            fn (TestaAssemblea $t) => array_intersect($t->anagraficaIds, $deleganti) !== []
        ));

        $numero = count($rappresentate);
        $valore = array_sum(array_map(fn ($t) => $t->valore, $rappresentate));

        $oltreTeste = 5 * $numero > $partecipanti;
        $oltreValore = 5 * $valore > $totale;

        if (! $oltreTeste && ! $oltreValore) {
            return null;
        }

        return sprintf(
            'Con più di venti condòmini il delegato non può rappresentare più di un quinto dei condòmini e del valore (art. 67 c. 1 disp. att. c.c.): con questa delega rappresenterebbe %d condòmini su %d e %s millesimi su %s.',
            $numero,
            $partecipanti,
            Millesimi::formatta($valore),
            Millesimi::formatta($totale)
        );
    }

    private function chiaveDelegato(?int $id, ?string $nome): string
    {
        return $id ? 'a'.$id : 'n'.($this->normalizza($nome) ?? '');
    }

    private function normalizza(?string $nome): ?string
    {
        if ($nome === null) {
            return null;
        }

        $n = preg_replace('/\s+/u', ' ', mb_strtolower(trim($nome)));

        return $n === '' ? null : $n;
    }
}
