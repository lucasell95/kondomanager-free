<?php

namespace App\Enums\Assemblee;

use App\Services\Assemblee\RegolaMaggioranza;

/**
 * La materia di un punto all'ordine del giorno, e quindi la maggioranza che serve per deliberare.
 *
 * Fonte: art. 1136 c.c. e tabella Confedilizia delle maggioranze, verificata sugli articoli
 * (vedi `analisi/ricerca-ia-assemblee.md` §1.4). La scelta della materia la fa l'utente: il
 * programma non classifica il punto, mostra la norma e la maggioranza richiesta.
 *
 * | Regola                                   | 1ª convocazione         | 2ª convocazione         |
 * |------------------------------------------|-------------------------|-------------------------|
 * | ordinaria (1136 c. 2-3)                  | intervenuti + 1/2 val.  | intervenuti + 1/3 val.  |
 * | art. 1136 c. 4                           | intervenuti + 1/2 val.  | intervenuti + 1/2 val.  |
 * | art. 1136 c. 5 (innovazioni)             | intervenuti + 2/3 val.  | intervenuti + 2/3 val.  |
 * | art. 1117-ter (destinazione d'uso)       | 4/5 partecipanti + 4/5  | 4/5 partecipanti + 4/5  |
 * | unanimità                                | tutti                   | tutti                   |
 *
 * Il diritto di voto con usufrutto (art. 67 c. 6-7 disp. att.): l'usufruttuario vota
 * sull'ordinaria amministrazione e sul semplice godimento delle cose comuni, il nudo proprietario
 * su tutto il resto. `votaUsufruttuario()` dice da che parte sta ciascuna materia.
 */
enum MateriaDelibera: string
{
    case ORDINARIA = 'ordinaria';
    case RENDICONTO_PREVENTIVO = 'rendiconto_preventivo';
    case NOMINA_AMMINISTRATORE = 'nomina_amministratore';
    case REVISORE = 'revisore_contabilita';
    case RIPARAZIONI_STRAORDINARIE = 'riparazioni_straordinarie';
    case LITI = 'liti';
    case INNOVAZIONI_AGEVOLATE = 'innovazioni_agevolate';
    case INNOVAZIONI = 'innovazioni';
    case IMPIANTI_NON_CENTRALIZZATI = 'impianti_non_centralizzati';
    case VIDEOSORVEGLIANZA = 'videosorveglianza';
    case DESTINAZIONE_USO = 'modifica_destinazione_uso';
    case TUTELA_DESTINAZIONI = 'tutela_destinazioni_uso';
    case REGOLAMENTO = 'regolamento';
    case RETTIFICA_TABELLE = 'rettifica_tabelle';
    case FORMAZIONE_TABELLE = 'formazione_tabelle';
    case SITO_INTERNET = 'sito_internet';
    case UNANIMITA = 'unanimita';
    case INFORMATIVA = 'informativa';

    public function label(): string
    {
        return match ($this) {
            self::ORDINARIA => 'Ordinaria amministrazione',
            self::RENDICONTO_PREVENTIVO => 'Rendiconto, preventivo e riparto delle spese',
            self::NOMINA_AMMINISTRATORE => 'Nomina, conferma o revoca dell\'amministratore',
            self::REVISORE => 'Nomina del revisore della contabilità',
            self::RIPARAZIONI_STRAORDINARIE => 'Riparazioni straordinarie di notevole entità',
            self::LITI => 'Liti oltre le attribuzioni dell\'amministratore',
            self::INNOVAZIONI_AGEVOLATE => 'Innovazioni agevolate (sicurezza, barriere, energia, parcheggi)',
            self::INNOVAZIONI => 'Innovazioni (miglioramento o uso più comodo)',
            self::IMPIANTI_NON_CENTRALIZZATI => 'Impianti non centralizzati e accesso alle parti comuni',
            self::VIDEOSORVEGLIANZA => 'Videosorveglianza delle parti comuni',
            self::DESTINAZIONE_USO => 'Modifica della destinazione d\'uso delle parti comuni',
            self::TUTELA_DESTINAZIONI => 'Tutela delle destinazioni d\'uso',
            self::REGOLAMENTO => 'Regolamento di condominio (non contrattuale)',
            self::RETTIFICA_TABELLE => 'Rettifica delle tabelle millesimali',
            self::FORMAZIONE_TABELLE => 'Formazione o modifica delle tabelle senza errori',
            self::SITO_INTERNET => 'Sito internet del condominio',
            self::UNANIMITA => 'Atti che richiedono l\'unanimità',
            self::INFORMATIVA => 'Comunicazione senza votazione',
        };
    }

    public function norma(): string
    {
        return match ($this) {
            self::ORDINARIA => 'art. 1135 e art. 1136 c. 2-3 c.c.',
            self::RENDICONTO_PREVENTIVO => 'art. 1135 n. 2-3 e art. 1136 c. 2-3 c.c.',
            self::NOMINA_AMMINISTRATORE => 'art. 1129 e art. 1136 c. 4 c.c.',
            self::REVISORE => 'art. 1130-bis e art. 1136 c. 4 c.c.',
            self::RIPARAZIONI_STRAORDINARIE => 'art. 1136 c. 4 c.c.',
            self::LITI => 'art. 1136 c. 4 c.c.',
            self::INNOVAZIONI_AGEVOLATE => 'art. 1120 c. 2 e art. 1136 c. 4 c.c.',
            self::INNOVAZIONI => 'art. 1120 c. 1 e art. 1136 c. 5 c.c.',
            self::IMPIANTI_NON_CENTRALIZZATI => 'art. 1122-bis c. 3 e art. 1136 c. 5 c.c.',
            self::VIDEOSORVEGLIANZA => 'art. 1122-ter e art. 1136 c. 2 c.c.',
            self::DESTINAZIONE_USO => 'art. 1117-ter c.c.',
            self::TUTELA_DESTINAZIONI => 'art. 1117-quater e art. 1136 c. 2 c.c.',
            self::REGOLAMENTO => 'art. 1138 e art. 1136 c. 2 c.c.',
            self::RETTIFICA_TABELLE => 'art. 69 disp. att. e art. 1136 c. 2 c.c.',
            self::FORMAZIONE_TABELLE => 'art. 69 disp. att. c.c.',
            self::SITO_INTERNET => 'art. 71-ter disp. att. e art. 1136 c. 2 c.c.',
            self::UNANIMITA => 'artt. 1108, 1118, 1120 c. 4, 1127, 1128 c.c.',
            self::INFORMATIVA => '—',
        };
    }

    public function richiedeVotazione(): bool
    {
        return $this !== self::INFORMATIVA;
    }

    /**
     * L'usufruttuario vota su questa materia? (art. 67 c. 6 disp. att.: ordinaria amministrazione
     * e semplice godimento delle cose e dei servizi comuni). Negli altri casi vota il nudo
     * proprietario (c. 7).
     *
     * ⚠️ È un default prudente, non un giudizio: sui casi di confine (per esempio la nomina
     * dell'amministratore) la dottrina non è unanime. La materia la sceglie l'utente.
     */
    public function votaUsufruttuario(): bool
    {
        return in_array($this, [
            self::ORDINARIA,
            self::RENDICONTO_PREVENTIVO,
            self::NOMINA_AMMINISTRATORE,
            self::REVISORE,
            self::SITO_INTERNET,
            self::INFORMATIVA,
        ], true);
    }

    /** La maggioranza che serve per approvare, nella convocazione data. `null` se non si vota. */
    public function regola(Convocazione $convocazione): ?RegolaMaggioranza
    {
        return match ($this) {
            self::INFORMATIVA => null,

            self::ORDINARIA, self::RENDICONTO_PREVENTIVO, self::VIDEOSORVEGLIANZA,
            self::TUTELA_DESTINAZIONI, self::REGOLAMENTO, self::RETTIFICA_TABELLE,
            self::SITO_INTERNET => $convocazione === Convocazione::PRIMA
                ? RegolaMaggioranza::intervenutiEValore(1, 2, 'Maggioranza degli intervenuti e almeno la metà del valore', 'art. 1136 c. 2 c.c.')
                : RegolaMaggioranza::intervenutiEValore(1, 3, 'Maggioranza degli intervenuti e almeno un terzo del valore', 'art. 1136 c. 3 c.c.'),

            self::NOMINA_AMMINISTRATORE, self::REVISORE, self::RIPARAZIONI_STRAORDINARIE,
            self::LITI, self::INNOVAZIONI_AGEVOLATE => RegolaMaggioranza::intervenutiEValore(
                1, 2, 'Maggioranza degli intervenuti e almeno la metà del valore', 'art. 1136 c. 4 c.c.'
            ),

            self::INNOVAZIONI, self::IMPIANTI_NON_CENTRALIZZATI => RegolaMaggioranza::intervenutiEValore(
                2, 3, 'Maggioranza degli intervenuti e almeno i due terzi del valore', 'art. 1136 c. 5 c.c.'
            ),

            self::DESTINAZIONE_USO => new RegolaMaggioranza(
                RegolaMaggioranza::BASE_PARTECIPANTI, 4, 5, false, 4, 5, false, false,
                'Quattro quinti dei partecipanti e quattro quinti del valore', 'art. 1117-ter c.c.'
            ),

            self::FORMAZIONE_TABELLE, self::UNANIMITA => RegolaMaggioranza::unanimita($this->norma()),
        };
    }

    /**
     * Avvertenza da mostrare accanto alla materia, quando la regola è discussa o la scelta delicata.
     */
    public function avvertenza(): ?string
    {
        return match ($this) {
            self::FORMAZIONE_TABELLE => 'Regola incerta: Confedilizia indica l\'unanimità, ma secondo Cass. SU 18477/2010 le tabelle si approvano con la maggioranza dell\'art. 1136 c. 2. Verificare con un legale.',
            self::RIPARAZIONI_STRAORDINARIE => 'Solo per le riparazioni di «notevole entità»: per le altre la materia è l\'ordinaria amministrazione.',
            self::NOMINA_AMMINISTRATORE => 'La delibera di nomina deve indicare il compenso, anche se gratuito (art. 1129 c. 14 c.c.).',
            self::INNOVAZIONI => 'Le innovazioni che rendono inservibili parti comuni o ledono il decoro sono vietate (art. 1120 c. 4): serve l\'unanimità.',
            default => null,
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Per i menu a tendina, con la maggioranza scritta per esteso. */
    public static function opzioni(): array
    {
        return array_map(function (self $m) {
            $prima = $m->regola(Convocazione::PRIMA);
            $seconda = $m->regola(Convocazione::SECONDA);

            return [
                'value' => $m->value,
                'label' => $m->label(),
                'norma' => $m->norma(),
                'maggioranza_prima' => $prima?->descrizione,
                'maggioranza_seconda' => $seconda?->descrizione,
                'vota_usufruttuario' => $m->votaUsufruttuario(),
                'richiede_votazione' => $m->richiedeVotazione(),
                'avvertenza' => $m->avvertenza(),
            ];
        }, self::cases());
    }
}
