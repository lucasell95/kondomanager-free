<?php

namespace App\Services\IA;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Chiamate HTTP all'API Anthropic con il client di Laravel (niente SDK: è ancora in beta).
 *
 * - Header `x-api-key` e `anthropic-version` (https://platform.claude.com/docs/en/api/versioning).
 * - Nuovi tentativi solo sugli errori temporanei: rete, 500/502/503/504, 529 (sovraccarico) e 429
 *   **con** `retry-after`. Un 429 senza `retry-after` è il tetto di spesa della Console: ritentare
 *   non serve (https://platform.claude.com/docs/en/api/errors).
 * - Ogni errore diventa un `ErroreAnthropic` con un messaggio in italiano. Né la chiave né il
 *   contenuto della richiesta finiscono nei messaggi o nei log.
 */
class ClientAnthropic
{
    private const STATI_TEMPORANEI = [500, 502, 503, 504, 529];

    /**
     * POST /v1/messages.
     *
     * @param  array<string, mixed>  $corpo
     * @return array{risposta: array<string, mixed>, request_id: ?string}
     */
    public function messaggi(string $chiave, array $corpo): array
    {
        $risposta = $this->invia($chiave, 'post', '/v1/messages', $corpo);

        return ['risposta' => (array) $risposta->json(), 'request_id' => $risposta->header('request-id') ?: null];
    }

    /**
     * GET /v1/models/{modello}: verifica chiave e modello senza consumare token.
     *
     * @return array<string, mixed>
     */
    public function modello(string $chiave, string $modello): array
    {
        return (array) $this->invia($chiave, 'get', '/v1/models/'.rawurlencode($modello))->json();
    }

    private function invia(string $chiave, string $metodo, string $percorso, array $corpo = []): Response
    {
        $attese = array_values((array) config('ia.attese_nuovi_tentativi', [2000, 6000]));
        $url = rtrim((string) config('ia.url_base'), '/').$percorso;

        for ($tentativo = 0; ; $tentativo++) {
            $ultimo = $tentativo >= count($attese);

            try {
                $richiesta = Http::withHeaders([
                    'x-api-key'         => $chiave,
                    'anthropic-version' => (string) config('ia.versione_api'),
                ])
                    ->acceptJson()
                    ->asJson()
                    ->timeout((int) config('ia.timeout', 120))
                    ->connectTimeout((int) config('ia.timeout_connessione', 10));

                $risposta = $metodo === 'get' ? $richiesta->get($url) : $richiesta->post($url, $corpo);
            } catch (ConnectionException) {
                if ($ultimo) {
                    throw new ErroreAnthropic(
                        "Impossibile contattare api.anthropic.com. Controlla che il server sia collegato a Internet e possa uscire in HTTPS, poi riprova.",
                        'rete',
                    );
                }
                Sleep::for($attese[$tentativo])->milliseconds();

                continue;
            }

            if ($risposta->successful()) {
                return $risposta;
            }

            $stato = $risposta->status();
            $temporaneo = in_array($stato, self::STATI_TEMPORANEI, true)
                || ($stato === 429 && $risposta->header('retry-after') !== '');

            if (! $temporaneo || $ultimo) {
                throw $this->errore($risposta);
            }

            $attesa = $attese[$tentativo];
            if ($stato === 429 && is_numeric($risposta->header('retry-after'))) {
                // Si rispetta retry-after, ma senza tenere ferma la pagina per più di 30 secondi.
                $attesa = min(30_000, max($attesa, (int) $risposta->header('retry-after') * 1000));
            }
            Sleep::for($attesa)->milliseconds();
        }
    }

    private function errore(Response $risposta): ErroreAnthropic
    {
        $stato = $risposta->status();
        $tipo = (string) ($risposta->json('error.type') ?? '');
        $requestId = $risposta->header('request-id') ?: ($risposta->json('request_id') ?: null);

        $messaggio = match (true) {
            $stato === 401 => 'La chiave API non è valida, è scaduta o è stata revocata. Controllala nelle impostazioni IA.',
            $stato === 403 => 'La chiave API non ha i permessi per questa operazione. Controlla organizzazione e workspace nella Console Anthropic.',
            $stato === 402 => 'Problema di fatturazione sull\'account Anthropic: controlla il metodo di pagamento o il credito nella Console.',
            $stato === 404 => 'Modello non trovato: controlla il nome del modello nelle impostazioni IA.',
            $stato === 413 => 'Il contesto inviato è troppo grande per una singola richiesta.',
            $stato === 429 => 'Limite di richieste o di spesa raggiunto presso Anthropic. Se è il limite di spesa della Console, va alzato lì; altrimenti riprova fra qualche minuto.',
            $stato === 400 => 'Richiesta rifiutata dall\'API Anthropic'.$this->dettaglio($risposta).'. Se hai impostato un limite di spesa nella Console, potrebbe essere stato raggiunto.',
            $stato === 529 => 'Il servizio Anthropic è momentaneamente sovraccarico. Riprova fra qualche minuto.',
            $stato === 504 => 'Il servizio Anthropic non ha risposto in tempo. Riprova fra qualche minuto.',
            $stato >= 500  => 'Il servizio Anthropic ha un problema temporaneo. Riprova fra qualche minuto.',
            default        => "Errore inatteso dall'API Anthropic (HTTP {$stato}).",
        };

        if ($requestId) {
            $messaggio .= " (codice richiesta: {$requestId})";
        }

        return new ErroreAnthropic($messaggio, 'http_'.$stato.($tipo !== '' ? ':'.$tipo : ''), $stato, $requestId);
    }

    /** Il messaggio d'errore dell'API (che descrive la richiesta, non i dati), accorciato. */
    private function dettaglio(Response $risposta): string
    {
        $testo = trim((string) $risposta->json('error.message'));

        return $testo === '' ? '' : ': '.mb_substr($testo, 0, 200);
    }
}
