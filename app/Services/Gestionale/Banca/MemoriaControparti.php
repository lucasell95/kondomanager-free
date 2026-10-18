<?php

namespace App\Services\Gestionale\Banca;

use App\Models\Gestionale\Banca\RiconoscimentoControparte;

/**
 * Ricorda chi c'è dietro un IBAN o un nome della controparte.
 *
 * Idea dalle «recognitions» di LiDeCo: ogni abbinamento confermato insegna qualcosa. Un condòmino
 * paga quasi sempre dallo stesso conto, quindi dal secondo mese l'IBAN basta a proporlo; il nome
 * serve quando la banca non riporta l'IBAN. L'ultima conferma vince: se un IBAN passa a un
 * altro condòmino (vendita dell'unità) la memoria si aggiorna al primo abbinamento nuovo.
 */
final class MemoriaControparti
{
    public function ricorda(int $condominioId, ?string $iban, ?string $nome, ?int $anagraficaId, ?int $fornitoreId): void
    {
        if (! $anagraficaId && ! $fornitoreId) {
            return;
        }

        $voci = [];

        if ($ibanNorm = Testo::normalizzaIban($iban)) {
            $voci[] = [RiconoscimentoControparte::TIPO_IBAN, $ibanNorm];
        }

        $nomeNorm = Testo::normalizza($nome);

        if (strlen($nomeNorm) >= 3) {
            $voci[] = [RiconoscimentoControparte::TIPO_NOME, mb_substr($nomeNorm, 0, 255)];
        }

        foreach ($voci as [$tipo, $valore]) {
            $voce = RiconoscimentoControparte::firstOrNew([
                'condominio_id' => $condominioId,
                'tipo' => $tipo,
                'valore' => $valore,
            ]);

            // Un IBAN o un nome possono essere sia di chi paga sia di chi riceve (il condòmino
            // che è anche fornitore): si aggiorna solo il lato confermato.
            if ($anagraficaId) {
                $voce->anagrafica_id = $anagraficaId;
            }

            if ($fornitoreId) {
                $voce->fornitore_id = $fornitoreId;
            }

            $voce->conteggio = $voce->exists ? $voce->conteggio + 1 : 1;
            $voce->ultimo_uso_at = now();
            $voce->save();
        }
    }

    /**
     * Chi è, secondo la memoria. L'IBAN vale più del nome.
     *
     * @return array{anagrafica_id: int|null, fornitore_id: int|null, tramite: string}|null
     */
    public function riconosci(int $condominioId, ?string $iban, ?string $nome): ?array
    {
        $cerca = function (string $tipo, ?string $valore) use ($condominioId) {
            if (! $valore) {
                return null;
            }

            return RiconoscimentoControparte::where('condominio_id', $condominioId)
                ->where('tipo', $tipo)
                ->where('valore', $valore)
                ->first();
        };

        $perIban = $cerca(RiconoscimentoControparte::TIPO_IBAN, Testo::normalizzaIban($iban));
        $nomeNorm = Testo::normalizza($nome);
        $perNome = strlen($nomeNorm) >= 3 ? $cerca(RiconoscimentoControparte::TIPO_NOME, $nomeNorm) : null;

        $voce = $perIban ?? $perNome;

        if (! $voce) {
            return null;
        }

        return [
            'anagrafica_id' => $voce->anagrafica_id ?? $perNome?->anagrafica_id,
            'fornitore_id' => $voce->fornitore_id ?? $perNome?->fornitore_id,
            'tramite' => $perIban ? 'iban' : 'nome',
        ];
    }
}
