<?php

namespace App\Services\IA;

use InvalidArgumentException;

/**
 * Carica i prompt da resources/prompts/ia/*.md: `sistema.md` comune più il file del tipo di
 * documento (`verbale.md`, `convocazione.md`…). Per aggiungere un tipo basta aggiungere il file.
 *
 * I commenti HTML (`<!-- … -->`) non vengono inviati; la riga `<!-- versione: N -->` dà la
 * versione, che si salva con la bozza per poter ricostruire con quale prompt è nata.
 */
class PromptIA
{
    public function __construct(private readonly ?string $cartella = null) {}

    public function supportato(string $tipoDocumento): bool
    {
        return $this->percorso($tipoDocumento) !== null;
    }

    /** @return array{testo: string, versione: string} */
    public function sistema(string $tipoDocumento): array
    {
        $percorsoTipo = $this->percorso($tipoDocumento);

        if ($percorsoTipo === null) {
            throw new InvalidArgumentException("Tipo di documento non previsto per l'IA: «{$tipoDocumento}».");
        }

        [$comune, $versioneComune] = $this->leggi($this->cartella().'/sistema.md');
        [$specifico, $versioneTipo] = $this->leggi($percorsoTipo);

        return [
            'testo'    => $comune."\n\n".$specifico,
            'versione' => "sistema.v{$versioneComune}+{$tipoDocumento}.v{$versioneTipo}",
        ];
    }

    private function percorso(string $tipoDocumento): ?string
    {
        // Solo lettere minuscole e trattino basso: il tipo diventa un nome di file.
        if (! preg_match('/^[a-z][a-z_]{1,48}$/', $tipoDocumento) || $tipoDocumento === 'sistema') {
            return null;
        }

        $percorso = $this->cartella().'/'.$tipoDocumento.'.md';

        return is_file($percorso) ? $percorso : null;
    }

    private function cartella(): string
    {
        return rtrim($this->cartella ?? (string) config('ia.cartella_prompt'), '/');
    }

    /** @return array{0: string, 1: string} testo senza commenti, versione */
    private function leggi(string $percorso): array
    {
        $contenuto = (string) file_get_contents($percorso);
        $versione = preg_match('/<!--\s*versione:\s*([\w.\-]+)\s*-->/u', $contenuto, $m) ? $m[1] : '0';
        $testo = trim(preg_replace('/<!--.*?-->/su', '', $contenuto));

        return [$testo, $versione];
    }
}
