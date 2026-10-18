<?php

namespace App\Services\Gestionale\Fiscale;

use App\Enums\Fiscale\NaturaPercipiente;
use App\Models\Comune;
use App\Models\Fornitore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/**
 * I dati anagrafici del percipiente come li chiede la Certificazione Unica, e i controlli su di essi.
 *
 * La CU distingue la persona fisica (cognome, nome, sesso, data e luogo di nascita) dal soggetto
 * diverso (sola denominazione). Il programma lo decide dal **codice fiscale**: 16 caratteri nella
 * forma della persona fisica, oppure natura del percipiente «persona fisica» quando il CF manca.
 * Una ditta individuale è una persona fisica: il suo CF è quello del titolare.
 *
 * Dal codice fiscale si ricavano anche sesso, data e comune di nascita: servono a **controllare**
 * i dati inseriti e a suggerire quelli mancanti, mai a sostituirli in silenzio — l'omocodia e i
 * secoli ambigui rendono la ricostruzione affidabile solo come indizio.
 */
class AnagraficaPercipiente
{
    private const REGEX_CF_PF = '/^[A-Z]{6}[0-9LMNPQRSTUV]{2}[ABCDEHLMPRST][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]$/';

    private const MESI = ['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5, 'H' => 6, 'L' => 7, 'M' => 8, 'P' => 9, 'R' => 10, 'S' => 11, 'T' => 12];

    private const OMOCODIA = ['L' => '0', 'M' => '1', 'N' => '2', 'P' => '3', 'Q' => '4', 'R' => '5', 'S' => '6', 'T' => '7', 'U' => '8', 'V' => '9'];

    public static function cfPersonaFisica(?string $cf): bool
    {
        return $cf !== null && preg_match(self::REGEX_CF_PF, strtoupper(trim($cf))) === 1;
    }

    /**
     * Sesso, data di nascita e codice catastale ricavati dal codice fiscale, o `null`.
     *
     * Il secolo è ambiguo: si assume il più recente che non porti la data nel futuro.
     *
     * @return array{sesso:string, data_nascita:string, codice_catastale:string}|null
     */
    public static function datiDaCodiceFiscale(?string $cf, ?CarbonImmutable $oggi = null): ?array
    {
        if (! self::cfPersonaFisica($cf)) {
            return null;
        }

        $cf = strtoupper(trim($cf));
        $numero = fn (string $s) => (int) strtr($s, self::OMOCODIA);

        $anno2 = $numero(substr($cf, 6, 2));
        $mese = self::MESI[$cf[8]];
        $giorno = $numero(substr($cf, 9, 2));
        $sesso = $giorno > 40 ? 'F' : 'M';
        $giorno = $giorno > 40 ? $giorno - 40 : $giorno;

        $oggi ??= CarbonImmutable::today();
        $anno = 2000 + $anno2;
        if ($anno > $oggi->year) {
            $anno -= 100;
        }

        if (! checkdate($mese, $giorno, $anno)) {
            return null;
        }

        $catastale = $cf[11].strtr(substr($cf, 12, 3), self::OMOCODIA);

        return [
            'sesso' => $sesso,
            'data_nascita' => sprintf('%04d-%02d-%02d', $anno, $mese, $giorno),
            'codice_catastale' => $catastale,
        ];
    }

    /**
     * Il quadro anagrafico del percipiente per la CU, con le anomalie che impediscono di compilarla.
     *
     * @param  array<string,mixed>  $snapshot  lo scatto del fornitore su un pagamento, per i
     *         fornitori cancellati o rinominati: i dati di oggi hanno la precedenza.
     * @return array<string,mixed>
     */
    public function quadro(?Fornitore $fornitore, array $snapshot = []): array
    {
        $cf = strtoupper(trim((string) ($fornitore?->codice_fiscale ?: ($snapshot['codice_fiscale'] ?? ''))));
        $piva = strtoupper(trim((string) ($fornitore?->partita_iva ?: ($snapshot['partita_iva'] ?? ''))));

        $natura = $fornitore?->natura_percipiente;
        $personaFisica = self::cfPersonaFisica($cf)
            || ($cf === '' && $natura === NaturaPercipiente::PERSONA_FISICA_IRPEF);

        $quadro = [
            'codice_fiscale' => $cf ?: null,
            'partita_iva' => $piva ?: null,
            'persona_fisica' => $personaFisica,
            'denominazione' => $fornitore?->ragione_sociale ?? ($snapshot['ragione_sociale'] ?? null),
            'cognome' => $fornitore?->cognome,
            'nome' => $fornitore?->nome,
            'sesso' => $fornitore?->sesso,
            'data_nascita' => $fornitore?->data_nascita?->toDateString(),
            'comune_nascita' => $fornitore?->comune_nascita,
            'provincia_nascita' => $fornitore?->provincia_nascita,
            'codice_catastale_nascita' => $fornitore?->codice_catastale_nascita
                ?: $this->codiceCatastale($fornitore?->comune_nascita, $fornitore?->provincia_nascita),
            'domicilio' => [
                'indirizzo' => $fornitore?->indirizzo ?? ($snapshot['indirizzo'] ?? null),
                'cap' => $fornitore?->cap,
                'comune' => $fornitore?->comune,
                'provincia' => $fornitore?->provincia,
            ],
            'suggeriti_da_cf' => null,
            'anomalie' => [],
        ];

        $anomalie = [];

        if ($cf === '') {
            $anomalie[] = 'Manca il codice fiscale del percipiente: la CU non si può compilare.';
        } elseif (! $personaFisica && ! preg_match('/^[0-9]{11}$/', $cf)) {
            $anomalie[] = "Il codice fiscale «{$cf}» non ha né la forma della persona fisica (16 caratteri) né quella del soggetto diverso (11 cifre).";
        }

        if ($personaFisica) {
            $daCf = self::datiDaCodiceFiscale($cf);
            if ($daCf !== null) {
                $daCf['comune'] = $this->comuneDaCatastale($daCf['codice_catastale']);
                $quadro['suggeriti_da_cf'] = $daCf;
            }

            $mancanti = array_keys(array_filter([
                'cognome' => blank($quadro['cognome']),
                'nome' => blank($quadro['nome']),
                'sesso' => blank($quadro['sesso']),
                'data di nascita' => blank($quadro['data_nascita']),
                'comune di nascita' => blank($quadro['comune_nascita']),
                'provincia di nascita' => blank($quadro['provincia_nascita']),
            ]));

            if ($mancanti !== []) {
                $anomalie[] = 'Persona fisica: mancano '.implode(', ', $mancanti).' (scheda fornitore, riquadro «Dati per la Certificazione Unica»).';
            }

            if ($daCf !== null) {
                if (filled($quadro['sesso']) && strtoupper($quadro['sesso']) !== $daCf['sesso']) {
                    $anomalie[] = "Il sesso indicato ({$quadro['sesso']}) non corrisponde al codice fiscale ({$daCf['sesso']}).";
                }
                if (filled($quadro['data_nascita']) && $quadro['data_nascita'] !== $daCf['data_nascita']) {
                    $anomalie[] = 'La data di nascita indicata non corrisponde al codice fiscale ('
                        .CarbonImmutable::parse($daCf['data_nascita'])->format('d/m/Y').').';
                }
                if (filled($quadro['codice_catastale_nascita']) && strtoupper($quadro['codice_catastale_nascita']) !== $daCf['codice_catastale']) {
                    $anomalie[] = "Il comune di nascita (codice {$quadro['codice_catastale_nascita']}) non corrisponde al codice fiscale ({$daCf['codice_catastale']}).";
                }
            }
        }

        if (blank($quadro['domicilio']['comune']) || blank($quadro['domicilio']['provincia'])) {
            $anomalie[] = 'Manca il domicilio fiscale (comune e provincia della sede) del percipiente.';
        }

        $quadro['anomalie'] = $anomalie;

        return $quadro;
    }

    /** Il codice catastale di un comune, cercato nell'elenco ISTAT caricato in `comuni`. */
    public function codiceCatastale(?string $comune, ?string $provincia): ?string
    {
        if (blank($comune) || ! Schema::hasTable('comuni')) {
            return null;
        }

        $cercato = Comune::normalizza($comune);

        return Comune::query()
            ->when(filled($provincia), fn ($q) => $q->where('sigla', strtoupper($provincia)))
            ->where('nome_ricerca', 'like', $cercato.'%')
            ->get(['nome', 'codice_catasto'])
            ->first(fn ($c) => Comune::normalizza($c->nome) === $cercato)
            ?->codice_catasto;
    }

    /** Il comune (nome e sigla) di un codice catastale, se l'elenco lo conosce. */
    public function comuneDaCatastale(string $codice): ?array
    {
        if (! Schema::hasTable('comuni')) {
            return null;
        }

        $c = Comune::where('codice_catasto', $codice)->first(['nome', 'sigla']);

        return $c ? ['nome' => $c->nome, 'provincia' => $c->sigla] : null;
    }
}
