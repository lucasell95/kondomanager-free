<?php

namespace App\Services\Gestionale;

use App\Actions\Gestionale\Movimenti\GeneraDelegheF24Action;
use App\Models\CategoriaEvento;
use App\Models\Condominio;
use App\Models\Evento;
use App\Models\Gestionale\PagamentoFornitore;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Promemoria «F24 Ritenuta» nell'Inbox e in agenda: uno per pagamento con ritenuta, con la
 * scadenza che gli assegna lo **scadenzario F24**.
 *
 * ## Perché un servizio a parte
 *
 * Il listener `SyncF24WithPagamento` è nato prima del modulo F24 e calcolava la scadenza per
 * conto suo: il 16 del mese successivo a ogni pagamento. Con le soglie di legge (4% appalti:
 * cumulo di 500 €, comunque entro 16/06 e 16/12, dicembre → 16/01; 20% lavoro autonomo: 100 €,
 * entro il 16/12) era sbagliato quasi sempre: nel collaudo 1 (E1) l'Inbox diceva di versare
 * due ritenute da 6,00 € il 17/08 e lo scadenziario una sola delega da 12,00 € entro il 16/12.
 *
 * La scadenza ora viene da `GeneraDelegheF24Action::scadenzePerPagamento()`, che usa lo stesso
 * `PlafondRitenuteService` del ricalcolo delle deleghe: una regola sola. E poiché un pagamento
 * può spostare la scadenza degli altri (quello che fa superare la soglia anticipa anche i
 * precedenti, uno storno la posticipa), si risincronizzano **tutti** i promemoria del
 * condominio, non solo quello del pagamento appena toccato.
 *
 * ## Cosa fa
 *
 * - ritenuta da versare → promemoria creato o aggiornato (scadenza, importo, codice tributo);
 * - ritenuta versata con F24, o pagamento stornato → promemoria chiuso;
 * - un promemoria già chiuso a mano resta chiuso, salvo i pagamenti indicati in `$riapri`
 *   (modifica del pagamento, storno del versamento F24).
 *
 * Non tocca la contabilità né le deleghe.
 */
class PromemoriaRitenuteService
{
    public const TIPO_EVENTO = 'versamento_ritenuta';

    public function __construct(private GeneraDelegheF24Action $deleghe) {}

    /**
     * Allinea i promemoria del condominio allo scadenzario F24. Non solleva eccezioni: un
     * promemoria non aggiornato non deve bloccare la registrazione di un pagamento o di un F24.
     *
     * @param  array<int,int>  $riapri  id dei pagamenti il cui promemoria va riaperto se è da versare
     */
    public function sincronizza(Condominio $condominio, array $riapri = []): bool
    {
        try {
            $scadenze = $this->deleghe->scadenzePerPagamento($condominio);

            $this->chiudiNonPiuDovuti($condominio, array_keys($scadenze));

            if ($scadenze !== []) {
                $this->aggiornaDovuti($condominio, $scadenze, $riapri);
            }

            InboxService::clearAdminCache();

            return true;

        } catch (\Throwable $e) {
            Log::error("PromemoriaRitenuteService: errore per il condominio #{$condominio->id}: ".$e->getMessage());

            return false;
        }
    }

    /**
     * @param  array<int,array<string,mixed>>  $scadenze
     * @param  array<int,int>  $riapri
     */
    private function aggiornaDovuti(Condominio $condominio, array $scadenze, array $riapri): void
    {
        $categoria = CategoriaEvento::where('name', 'Scadenze amministrative')->firstOrFail();

        $pagamenti = PagamentoFornitore::with('fornitore')
            ->whereIn('id', array_keys($scadenze))
            ->get()
            ->keyBy('id');

        // Totale per data di scadenza: è ciò che si versa con un solo F24.
        $totali = [];
        foreach ($scadenze as $s) {
            $chiave = $s['scadenza']->toDateString();
            $totali[$chiave] = ($totali[$chiave] ?? 0) + $s['importo'];
        }

        foreach ($scadenze as $pagamentoId => $s) {
            $pagamento = $pagamenti->get($pagamentoId);

            if (! $pagamento) {
                continue;
            }

            $nomeFornitore = $pagamento->fornitore?->ragione_sociale
                ?? ($pagamento->fornitore_snapshot['ragione_sociale'] ?? "Fornitore #{$pagamento->fornitore_id}");

            $inizio = $s['scadenza']->toMutable()->setTime(9, 0);
            $totaleScadenza = $totali[$s['scadenza']->toDateString()];

            $evento = Evento::where('meta->context->pagamento_id', $pagamento->id)
                ->where('meta->type', self::TIPO_EVENTO)
                ->first();

            $attributi = [
                'title' => "F24 Ritenuta — {$nomeFornitore} ({$condominio->nome})",
                'start_time' => $inizio,
                'end_time' => $inizio->copy()->addHour(),
                'description' => $this->descrizione($pagamento, $s, $totaleScadenza),
                'category_id' => $categoria->id,
                'visibility' => 'hidden',
                'is_approved' => true,
                'meta' => [
                    'type' => self::TIPO_EVENTO,
                    'requires_action' => true,
                    'is_completed' => false,
                    'condominio_nome' => $condominio->nome,
                    'importo' => $s['importo'],
                    'fornitore' => $nomeFornitore,
                    'codice_tributo' => $s['codice_tributo'],
                    'scadenza_f24' => $s['scadenza']->toDateString(),
                    'totale_scadenza' => $totaleScadenza,
                    'motivo_scadenza' => $s['motivo'],
                    'titolo_azione' => 'Vedi pagamento',
                    'action_url' => route('admin.gestionale.pagamenti-fornitori.show', [
                        'condominio' => $condominio->id,
                        'pagamento' => $pagamento->id,
                    ]),
                    'context' => [
                        'pagamento_id' => $pagamento->id,
                        'fornitore_id' => $pagamento->fornitore_id,
                        'delega_f24_id' => $s['delega_id'],
                        'is_f24' => true,
                    ],
                ],
            ];

            if (! $evento) {
                $evento = Evento::create($attributi + [
                    // Evento di sistema, ma il database richiede un autore valido.
                    'created_by' => $pagamento->user_id ?? User::first()?->id,
                    'is_completed' => false,
                ]);
            } else {
                if (in_array($pagamento->id, $riapri, true)) {
                    $attributi['is_completed'] = false;
                }
                $evento->update($attributi);
            }

            $evento->condomini()->syncWithoutDetaching([$condominio->id]);
        }
    }

    /**
     * Chiude i promemoria aperti dei pagamenti del condominio che non hanno più una ritenuta
     * da versare: versata con F24, pagamento stornato, ritenuta azzerata.
     *
     * @param  array<int,int>  $dovuti  id dei pagamenti con ritenuta ancora da versare
     */
    private function chiudiNonPiuDovuti(Condominio $condominio, array $dovuti): void
    {
        $nonDovuti = PagamentoFornitore::where('condominio_id', $condominio->id)
            ->whereNotIn('id', $dovuti)
            ->pluck('id')
            ->all();

        foreach (array_chunk($nonDovuti, 500) as $blocco) {
            Evento::where('meta->type', self::TIPO_EVENTO)
                ->whereIn('meta->context->pagamento_id', $blocco)
                ->where('is_completed', false)
                ->update(['is_completed' => true]);
        }
    }

    /** @param  array<string,mixed>  $s */
    private function descrizione(PagamentoFornitore $pagamento, array $s, int $totaleScadenza): string
    {
        $euro = fn (int $cents) => number_format($cents / 100, 2, ',', '.').' €';
        $scadenza = $s['scadenza']->format('d/m/Y');

        $perche = match ($s['motivo']) {
            'soglia_raggiunta' => 'il cumulo delle ritenute ha raggiunto la soglia di legge',
            'termine_di_legge' => 'ritenute sotto soglia: si versano entro il termine di legge',
            'ritenute_dicembre' => 'ritenute operate a dicembre: si versano entro il 16 gennaio',
            'mensile_obbligatorio' => 'ritenuta da versare entro il 16 del mese successivo',
            default => 'come da delega F24 confermata',
        };

        $righe = [
            "Versare ritenuta d'acconto (cod. tributo {$s['codice_tributo']}).",
            'Importo: '.$euro($s['importo']),
            'Pagamento del: '.($pagamento->data_pagamento?->format('d/m/Y') ?? '—'),
            "Scadenza {$scadenza}, come nello scadenzario F24: {$perche}.",
        ];

        if ($totaleScadenza !== $s['importo']) {
            $righe[] = "Con le altre ritenute in scadenza il {$scadenza}: un solo F24 da ".$euro($totaleScadenza).'.';
        }

        return implode("\n", $righe);
    }
}
