<?php

namespace App\Actions\Gestionale\Movimenti;

use App\Enums\TipoMovimentoContabile;
use App\Exceptions\Gestionale\RegolazioneImmediataNonAmmessaException;
use App\Helpers\DateHelper;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\DoubleEntryValidator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Storno di una registrazione a regolazione immediata.
 *
 * Il giornale è append-only: la scrittura originale non si cancella e non si
 * riscrive, si neutralizza con la sua inversa (DARE ↔ AVERE). È la regola cardine
 * del sigillo contabile — lo storno è SEMPRE ammesso, anche su una scrittura
 * sigillata, perché aggiunge un fatto nuovo invece di riscriverne uno passato.
 *
 * Data dello storno: lo storno di una regolazione immediata è quasi sempre la
 * **rettifica di una registrazione sbagliata** (una spesa da riclassificare come
 * fattura, un importo errato), non un fatto nuovo. Per questo, se l'esercizio
 * dell'originale è aperto, la competenza è **quella dell'originale**: i due
 * movimenti si annullano alla stessa data e il saldo di cassa «al giorno» resta
 * giusto anche nei mesi in mezzo. Prima lo storno prendeva la data di oggi (in
 * UTC): nel collaudo 1 (E2) due storni del 18/10 di regolazioni del 22 e 28/07
 * lasciavano la banca a -135,54 € al 31/07 e 354,00 € di entrate inesistenti nel
 * registro di cassa. `data_registrazione` resta il giorno in cui si registra.
 *
 * Cross-esercizio: se l'esercizio dell'originale è nel frattempo stato chiuso, lo
 * storno viene registrato nell'esercizio corrente aperto, con la data di oggi e la
 * provenienza indicata in causale (l'esercizio chiuso non si tocca). Stesso
 * paradigma della Variante B1 già adottata per lo storno dei pagamenti fornitore e
 * dei giroconti. «Oggi» è sempre nel fuso dell'utente (DateHelper::oggiUtente).
 */
class StornaRegolazioneImmediataAction
{
    public function execute(ScritturaContabile $scrittura, Condominio $condominio, string $motivo): ScritturaContabile
    {
        if ($scrittura->condominio_id !== $condominio->id) {
            throw new RegolazioneImmediataNonAmmessaException(
                'La scrittura non appartiene a questo condominio.'
            );
        }

        if ($scrittura->tipo_movimento !== TipoMovimentoContabile::REGOLAZIONE_IMMEDIATA) {
            throw new RegolazioneImmediataNonAmmessaException(
                'Questa operazione storna solo le registrazioni a regolazione immediata. '
                .'Per gli altri movimenti usa lo storno del documento che li ha generati.'
            );
        }

        // Anti storno-dello-storno e anti doppio click.
        $giaStornata = ScritturaContabile::where('scrittura_padre_id', $scrittura->id)
            ->where('tipo_movimento', TipoMovimentoContabile::STORNO_REGOLAZIONE_IMMEDIATA)
            ->exists();

        if ($giaStornata) {
            throw new RegolazioneImmediataNonAmmessaException(
                "La registrazione {$scrittura->numero_protocollo} è già stata stornata."
            );
        }

        return DB::transaction(function () use ($scrittura, $condominio, $motivo) {

            $esercizioOriginale = $scrittura->esercizio;
            $crossEsercizio = false;
            $esercizioTarget = $esercizioOriginale;

            if (! $esercizioOriginale || $esercizioOriginale->stato === 'chiuso') {
                $esercizioTarget = Esercizio::where('condominio_id', $condominio->id)
                    ->where('stato', 'aperto')
                    ->first();

                if (! $esercizioTarget) {
                    throw new RegolazioneImmediataNonAmmessaException(
                        "L'esercizio della registrazione è chiuso e non esiste un esercizio "
                        .'aperto in cui appoggiare lo storno.'
                    );
                }

                $crossEsercizio = true;
            }

            $causale = $crossEsercizio
                ? sprintf(
                    'Storno %s: %s. Originale in esercizio «%s».',
                    $scrittura->numero_protocollo, $motivo, $esercizioOriginale?->nome ?? '—'
                )
                : sprintf('Storno %s: %s', $scrittura->numero_protocollo, $motivo);

            $storno = ScritturaContabile::create([
                'condominio_id' => $condominio->id,
                'esercizio_id' => $esercizioTarget->id,
                'gestione_id' => $scrittura->gestione_id,
                'scrittura_padre_id' => $scrittura->id,
                'data_registrazione' => DateHelper::oggiUtente(),
                'data_competenza' => $crossEsercizio
                    ? DateHelper::oggiUtente()
                    : ($scrittura->data_competenza?->toDateString() ?? DateHelper::oggiUtente()),
                'causale' => mb_substr($causale, 0, 255),
                'tipo_movimento' => TipoMovimentoContabile::STORNO_REGOLAZIONE_IMMEDIATA,
                'stato' => 'registrata',
                'created_by' => Auth::id(),
                'note' => 'Annullamento prot. '.$scrittura->numero_protocollo,
            ]);

            // Righe speculari: il capitolo di spesa si scarica, la cassa rientra.
            // voce_spesa_id viene propagato così il budget del capitolo torna libero.
            foreach ($scrittura->righe as $riga) {
                $storno->righe()->create([
                    'conto_contabile_id' => $riga->conto_contabile_id,
                    'cassa_id' => $riga->cassa_id,
                    'voce_spesa_id' => $riga->voce_spesa_id,
                    'anagrafica_id' => $riga->anagrafica_id,
                    'immobile_id' => $riga->immobile_id,
                    'tipo_riga' => $riga->tipo_riga === 'dare' ? 'avere' : 'dare',
                    'importo' => $riga->importo,
                    'note' => 'Storno — '.$riga->note,
                ]);
            }

            DoubleEntryValidator::validateOrFail($storno->id);

            return $storno;
        });
    }
}
