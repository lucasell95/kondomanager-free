<?php

namespace App\Http\Controllers\Gestionale\Banca;

use App\Exceptions\Gestionale\AbbinamentoBancarioException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Gestionale\Banca\Concerns\DatiBanca;
use App\Http\Requests\Gestionale\Banca\AbbinaIncassoRequest;
use App\Http\Requests\Gestionale\Banca\AbbinaPagamentoRequest;
use App\Http\Requests\Gestionale\Banca\AbbinaRegolazioneRequest;
use App\Http\Requests\Gestionale\Banca\StoreRegolaBancariaRequest;
use App\Models\Condominio;
use App\Models\Gestionale\Banca\MovimentoBancario;
use App\Models\Gestionale\Banca\RegolaBancaria;
use App\Models\Gestionale\FatturaPassiva;
use App\Services\Gestionale\Banca\AbbinamentoBancarioService;
use App\Services\Gestionale\Banca\ProposteAbbinamentoService;
use App\Services\Gestionale\Banca\Testo;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Banca → Movimenti da abbinare: elenco con filtri, proposte e conferme.
 *
 * Ogni conferma passa da `AbbinamentoBancarioService`, che usa i flussi contabili esistenti.
 */
class MovimentiBancariController extends Controller
{
    use DatiBanca, HandleFlashMessages, HasCondomini, HasEsercizio;

    public function __construct(
        private readonly AbbinamentoBancarioService $abbinamento,
        private readonly ProposteAbbinamentoService $proposte,
    ) {}

    public function index(Request $request, Condominio $condominio): Response
    {
        $filtri = $request->validate([
            'cassa_id' => ['nullable', 'integer'],
            'stato' => ['nullable', Rule::in([...MovimentoBancario::STATI, 'tutti'])],
            'verso' => ['nullable', Rule::in(['entrate', 'uscite'])],
            'data_da' => ['nullable', 'date'],
            'data_a' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $stato = $filtri['stato'] ?? MovimentoBancario::STATO_DA_ABBINARE;

        $base = MovimentoBancario::where('condominio_id', $condominio->id)
            ->when($filtri['cassa_id'] ?? null, fn ($q, $id) => $q->where('cassa_id', $id))
            ->when(($filtri['verso'] ?? null) === 'entrate', fn ($q) => $q->where('importo', '>', 0))
            ->when(($filtri['verso'] ?? null) === 'uscite', fn ($q) => $q->where('importo', '<', 0))
            ->when($filtri['data_da'] ?? null, fn ($q, $d) => $q->whereDate('data_contabile', '>=', $d))
            ->when($filtri['data_a'] ?? null, fn ($q, $d) => $q->whereDate('data_contabile', '<=', $d))
            ->when($filtri['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('descrizione', 'like', '%'.$s.'%')
                ->orWhere('controparte', 'like', '%'.$s.'%')));

        $conteggi = (clone $base)->selectRaw('stato, COUNT(*) as n')->groupBy('stato')->pluck('n', 'stato');

        $movimenti = (clone $base)
            ->when($stato !== 'tutti', fn ($q) => $q->where('stato', $stato))
            ->with(['cassa:id,nome', 'scrittura:id,numero_protocollo,causale,tipo_movimento'])
            ->orderBy('data_contabile', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (MovimentoBancario $m) => $this->perElenco($m));

        return Inertia::render('gestionale/banca/BancaMovimenti', $this->datiIntestazione($condominio) + [
            'casse' => $this->casseImportabili($condominio),
            'movimenti' => $movimenti,
            'conteggi' => [
                'da_abbinare' => (int) ($conteggi[MovimentoBancario::STATO_DA_ABBINARE] ?? 0),
                'abbinato' => (int) ($conteggi[MovimentoBancario::STATO_ABBINATO] ?? 0),
                'ignorato' => (int) ($conteggi[MovimentoBancario::STATO_IGNORATO] ?? 0),
            ],
            'filtri' => ['stato' => $stato] + $filtri,
            'capitoli' => $this->capitoliDiSpesa($condominio),
            'gestioni' => $this->gestioniAttive($condominio),
            'anagrafiche' => $this->proposte->anagraficheDelCondominio($condominio->id)
                ->map(fn ($nome, $id) => ['id' => $id, 'nome' => $nome])->values(),
            'regole' => RegolaBancaria::where('condominio_id', $condominio->id)
                ->with('conto:id,nome')
                ->orderBy('nome')
                ->get()
                ->map(fn (RegolaBancaria $r) => [
                    'id' => $r->id,
                    'nome' => $r->nome,
                    'testo_contiene' => $r->testo_contiene,
                    'verso' => $r->verso,
                    'azione' => $r->azione,
                    'conto' => $r->conto?->nome,
                    'attiva' => $r->attiva,
                ]),
        ]);
    }

    /** Le proposte per un movimento, più quanto serve a scegliere a mano. */
    public function proposte(Condominio $condominio, int|string $movimento): JsonResponse
    {
        $m = $this->assicuraMovimento($condominio, $movimento);
        $proposte = $this->proposte->proponi($m);

        if ($m->importo < 0) {
            $proposte['fatture_aperte'] = FatturaPassiva::where('condominio_id', $condominio->id)
                ->pagabili()
                ->with('fornitore:id,ragione_sociale')
                ->orderBy('data_scadenza')
                ->limit(100)
                ->get()
                ->filter(fn ($f) => $f->residuo > 0 && ($f->tipo_documento ?? 'fattura') !== 'nota_credito')
                ->map(fn ($f) => [
                    'fattura_id' => $f->id,
                    'numero' => $f->numero_documento,
                    'data' => $f->data_documento?->toDateString(),
                    'fornitore' => ['id' => $f->fornitore_id, 'nome' => $f->fornitore?->ragione_sociale],
                    'residuo' => $f->residuo,
                ])
                ->values();
        }

        return response()->json(['movimento' => $this->perElenco($m), 'proposte' => $proposte]);
    }

    /** Le rate aperte di un altro condòmino, quando la proposta ha indovinato male. */
    public function rateAperte(Request $request, Condominio $condominio, int|string $movimento): JsonResponse
    {
        $m = $this->assicuraMovimento($condominio, $movimento);
        $anagraficaId = (int) $request->validate(['anagrafica_id' => ['required', 'integer']])['anagrafica_id'];

        abort_unless($this->proposte->anagraficheDelCondominio($condominio->id)->has($anagraficaId), 404);

        [$quote, $eccedenza] = $this->proposte->allocaFifo($anagraficaId, $condominio->id, max(0, $m->importo));

        return response()->json([
            'rate' => $this->proposte->rateAperte($anagraficaId, $condominio->id),
            'proposta' => $quote,
            'eccedenza' => $eccedenza,
        ]);
    }

    public function incasso(AbbinaIncassoRequest $request, Condominio $condominio, int|string $movimento): RedirectResponse
    {
        $m = $this->assicuraMovimento($condominio, $movimento);

        return $this->esegui(fn () => $this->abbinamento->registraIncasso(
            $m,
            $request->integer('pagante_id'),
            array_map(fn ($r) => ['quota_id' => (int) $r['quota_id'], 'importo' => (int) $r['importo']], $request->input('righe', [])),
            $request->integer('eccedenza'),
            $request->input('descrizione'),
            $request->user()?->id,
        ), 'Incasso registrato e abbinato al movimento della banca.');
    }

    public function pagamento(AbbinaPagamentoRequest $request, Condominio $condominio, int|string $movimento): RedirectResponse
    {
        $m = $this->assicuraMovimento($condominio, $movimento);

        $allocazioni = [];

        foreach ($request->input('allocazioni', []) as $a) {
            $allocazioni[(int) $a['fattura_id']] = ($allocazioni[(int) $a['fattura_id']] ?? 0) + (int) $a['importo'];
        }

        return $this->esegui(fn () => $this->abbinamento->registraPagamento(
            $m,
            $allocazioni,
            $request->integer('commissioni'),
            [
                'iban_confermato_manualmente' => $request->boolean('iban_confermato_manualmente'),
                'allow_overdraft' => $request->boolean('allow_overdraft'),
                'conferma_duplicato_verificato' => $request->boolean('conferma_duplicato_verificato'),
                'nota_override' => $request->input('nota_override'),
            ],
            $request->user()?->id,
        ), 'Pagamento fornitore registrato e abbinato al movimento della banca.');
    }

    public function regolazione(AbbinaRegolazioneRequest $request, Condominio $condominio, int|string $movimento): RedirectResponse
    {
        $m = $this->assicuraMovimento($condominio, $movimento);

        return $this->esegui(function () use ($request, $m, $condominio) {
            $scrittura = $this->abbinamento->registraRegolazione(
                $m,
                $request->integer('conto_id'),
                $request->integer('gestione_id'),
                $request->input('causale'),
                $request->user()?->id,
            );

            if ($request->boolean('crea_regola')) {
                RegolaBancaria::create([
                    'condominio_id' => $condominio->id,
                    'cassa_id' => $m->cassa_id,
                    'nome' => mb_substr((string) $request->input('testo_regola'), 0, 255),
                    'verso' => 'uscita',
                    'testo_contiene' => (string) $request->input('testo_regola'),
                    'azione' => RegolaBancaria::AZIONE_REGOLAZIONE,
                    'conto_id' => $request->integer('conto_id'),
                    'gestione_id' => $request->integer('gestione_id'),
                    'causale' => $request->input('causale'),
                ]);
            }

            return $scrittura;
        }, 'Spesa registrata sul capitolo scelto e abbinata al movimento della banca.');
    }

    public function collega(Request $request, Condominio $condominio, int|string $movimento): RedirectResponse
    {
        $m = $this->assicuraMovimento($condominio, $movimento);
        $dati = $request->validate([
            'scrittura_id' => ['required', 'integer', Rule::exists('scritture_contabili', 'id')->where('condominio_id', $condominio->id)],
            'forza' => ['nullable', 'boolean'],
        ]);

        return $this->esegui(
            fn () => $this->abbinamento->collegaEsistente($m, (int) $dati['scrittura_id'], (bool) ($dati['forza'] ?? false), $request->user()?->id),
            'Movimento collegato alla scrittura già registrata.'
        );
    }

    public function ignora(Request $request, Condominio $condominio, int|string $movimento): RedirectResponse
    {
        $m = $this->assicuraMovimento($condominio, $movimento);
        $dati = $request->validate(['nota' => ['nullable', 'string', 'max:1000']]);

        return $this->esegui(
            fn () => $this->abbinamento->ignora($m, $dati['nota'] ?? null, $request->user()?->id),
            'Movimento segnato come da non registrare.'
        );
    }

    public function scollega(Condominio $condominio, int|string $movimento): RedirectResponse
    {
        $m = $this->assicuraMovimento($condominio, $movimento);
        $eraAbbinato = $m->stato === MovimentoBancario::STATO_ABBINATO;

        $this->abbinamento->scollega($m);

        return back()->with($this->flashSuccess($eraAbbinato
            ? 'Abbinamento annullato. La scrittura resta a giornale: se era sbagliata, stornala dal suo registro.'
            : 'Il movimento è di nuovo da abbinare.'));
    }

    /** Conferma multipla: applica le sole proposte sicure, ciascuna con il suo flusso. */
    public function multipla(Request $request, Condominio $condominio): RedirectResponse
    {
        $dati = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        $esiti = ['abbinato' => 0, 'ignorato' => 0, 'saltato' => 0, 'errore' => 0];
        $errori = [];

        $movimenti = MovimentoBancario::where('condominio_id', $condominio->id)
            ->whereIn('id', $dati['ids'])
            ->orderBy('data_contabile')
            ->orderBy('id')
            ->get();

        foreach ($movimenti as $m) {
            $esito = $this->abbinamento->applicaMigliore($m, $request->user()?->id);
            $esiti[$esito['esito']]++;

            if ($esito['esito'] === 'errore') {
                $errori[] = $m->data_contabile->format('d/m/Y').' '.mb_substr((string) $m->descrizione, 0, 40).': '.$esito['messaggio'];
            }
        }

        $messaggio = sprintf(
            'Abbinati %d, ignorati %d, lasciati da guardare %d%s.',
            $esiti['abbinato'],
            $esiti['ignorato'],
            $esiti['saltato'],
            $esiti['errore'] ? ", con errore {$esiti['errore']}" : ''
        );

        $risposta = back()->with($esiti['errore'] ? $this->flashWarning($messaggio) : $this->flashSuccess($messaggio));

        return $errori ? $risposta->withErrors(['abbinamento' => implode("\n", array_slice($errori, 0, 10))]) : $risposta;
    }

    public function storeRegola(StoreRegolaBancariaRequest $request, Condominio $condominio): RedirectResponse
    {
        RegolaBancaria::create($request->validated() + ['condominio_id' => $condominio->id, 'attiva' => true]);

        return back()->with($this->flashSuccess('Regola salvata.'));
    }

    public function destroyRegola(Condominio $condominio, int|string $regola): RedirectResponse
    {
        $r = $this->assicuraRegola($condominio, $regola);
        $r->delete();

        return back()->with($this->flashSuccess('Regola eliminata.'));
    }

    private function assicuraRegola(Condominio $condominio, int|string $regola): RegolaBancaria
    {
        return RegolaBancaria::where('condominio_id', $condominio->id)->findOrFail((int) $regola);
    }

    private function esegui(callable $operazione, string $successo): RedirectResponse
    {
        try {
            $operazione();
        } catch (AbbinamentoBancarioException $e) {
            return back()->withErrors(array_filter([
                'abbinamento' => $e->getMessage(),
                'codice' => $e->codice,
            ]));
        }

        return back()->with($this->flashSuccess($successo));
    }

    /** @return array<string, mixed> */
    private function perElenco(MovimentoBancario $m): array
    {
        return [
            'id' => $m->id,
            'cassa_id' => $m->cassa_id,
            'cassa' => $m->cassa?->nome,
            'data_contabile' => $m->data_contabile->toDateString(),
            'data_valuta' => $m->data_valuta?->toDateString(),
            'importo' => $m->importo,
            'divisa' => $m->divisa,
            'descrizione' => $m->descrizione,
            'controparte' => $m->controparte,
            'iban_controparte' => $m->iban_controparte,
            'riferimento' => $m->riferimento,
            'stato' => $m->stato,
            'tipo_abbinamento' => $m->tipo_abbinamento,
            'note' => $m->note,
            'testo_regola' => $this->suggerimentoRegola($m),
            'scrittura' => $m->scrittura ? [
                'id' => $m->scrittura->id,
                'protocollo' => $m->scrittura->numero_protocollo,
                'causale' => $m->scrittura->causale,
            ] : null,
        ];
    }

    /** Le prime parole della descrizione, come testo proposto per una regola («commissioni»…). */
    private function suggerimentoRegola(MovimentoBancario $m): string
    {
        $parole = array_slice(explode(' ', Testo::normalizza($m->descrizione)), 0, 3);

        return implode(' ', array_filter($parole, fn ($p) => ! ctype_digit($p)));
    }
}
