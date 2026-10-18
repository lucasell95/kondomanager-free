// Tipi del modulo Assemblee: rispecchiano i dati preparati da AssembleaController@scheda.

export type StatoAssemblea = 'bozza' | 'convocata' | 'svolta' | 'verbalizzata';
export type EsitoDelibera = 'approvata' | 'respinta' | 'non_votata' | 'senza_votazione';
export type Voto = 'favorevole' | 'contrario' | 'astenuto' | null;

export interface Opzione {
  value: string;
  label: string;
}

export interface MateriaOpzione extends Opzione {
  norma: string;
  maggioranza_prima: string | null;
  maggioranza_seconda: string | null;
  vota_usufruttuario: boolean;
  richiede_votazione: boolean;
  avvertenza: string | null;
}

export interface MezzoOpzione extends Opzione {
  valido: boolean;
}

export interface OpzioniAssemblea {
  tipi: Opzione[];
  materie: MateriaOpzione[];
  mezzi: MezzoOpzione[];
  esercizi: { id: number; nome: string; stato: string }[];
  tabelle: { id: number; nome: string; tipo: string; attiva: boolean }[];
  anagrafiche: { id: number; nome: string }[];
}

export interface Requisito {
  oggetto: 'teste' | 'valore';
  richiesto: string;
  calcolo: string;
  raggiunto: boolean;
}

export interface Testa {
  chiave: string;
  etichetta: string;
  valore: number;
  valore_testo: string;
  presente: boolean;
  voto: Voto;
  conflitto: boolean;
  anagrafica_ids: number[];
  unita: string[];
  rappresentante: string | null;
}

export interface Conteggio {
  teste: number;
  valore: number;
  valore_testo: string;
  nomi: { chiave: string; etichetta: string; valore_testo: string; rappresentante: string | null }[];
}

export interface EsitoCostitutivo {
  convocazione: 'prima' | 'seconda';
  raggiunto: boolean;
  regola: string;
  norma: string;
  teste_presenti: number;
  teste_totali: number;
  valore_presente_testo: string;
  valore_totale_testo: string;
  requisiti: Requisito[];
  spiegazione: string;
  teste: Testa[];
}

export interface EsitoVotazione {
  esito: EsitoDelibera;
  materia: string;
  materia_label: string;
  regola: string | null;
  norma: string;
  requisiti: Requisito[];
  intervenuti: Conteggio;
  favorevoli: Conteggio;
  contrari: Conteggio;
  astenuti: Conteggio;
  non_espressi: Conteggio;
  conflitto: null | {
    teste: string[];
    esito_senza: EsitoDelibera;
    determinante: boolean;
    spiegazione: string;
  };
  decisivi: string[];
  spiegazione: string;
}

export interface Votazione {
  teste: Testa[];
  totale: number;
  totale_testo: string;
  esito: EsitoVotazione;
}

export interface Punto {
  id: number;
  ordine: number;
  titolo: string;
  testo: string | null;
  materia: string;
  materia_label: string;
  norma: string;
  maggioranza_prima: string | null;
  maggioranza_seconda: string | null;
  vota_usufruttuario: boolean;
  richiede_votazione: boolean;
  avvertenza: string | null;
  tabella_id: number | null;
  discussione: string | null;
  testo_delibera: string | null;
  votazione: Votazione;
}

export interface Presenza {
  modo: 'presente' | 'delegato' | 'assente';
  delegato_anagrafica_id: number | null;
  delegato_nome: string | null;
  delegato: string | null;
  delega_scritta: boolean;
  ora_ingresso: string | null;
  ora_uscita: string | null;
  note: string | null;
}

export interface Persona {
  anagrafica_id: number;
  nome: string;
  unita: string[];
  ruoli: string[];
  presenza: Presenza | null;
}

export interface Destinatario {
  id: number;
  anagrafica_id: number | null;
  nominativo: string;
  ruolo: string | null;
  mezzo: string;
  recapito: string | null;
  data_invio: string | null;
  data_ricezione: string | null;
  riferimento: string | null;
  note: string | null;
  stato: { codice: string; livello: 'ok' | 'warning' | 'error'; messaggio: string; giorni: number | null };
}

export interface Assente {
  anagrafica_id: number;
  nome: string;
  unita: string[];
  ruoli: string[];
  destinatario_id: number | null;
  verbale_mezzo: string | null;
  verbale_data_invio: string | null;
  verbale_data_ricezione: string | null;
  verbale_riferimento: string | null;
  scadenza_impugnazione: string | null;
}

export interface Dichiarazione {
  id: number;
  assemblea_punto_id: number | null;
  anagrafica_id: number | null;
  dichiarante: string;
  testo: string;
}

export interface Controllo {
  livello: 'error' | 'warning' | 'info';
  messaggio: string;
}

export interface Assemblea {
  id: number;
  titolo: string | null;
  titolo_leggibile: string;
  tipo: string;
  stato: StatoAssemblea;
  stato_label: string;
  transizioni: Opzione[];
  dati_modificabili: boolean;
  presenze_modificabili: boolean;
  esercizio_id: number | null;
  tabella_id: number | null;
  tabella_nome: string | null;
  prima_convocazione_at: string | null;
  prima_luogo: string | null;
  seconda_convocazione_at: string | null;
  seconda_luogo: string | null;
  videoconferenza: boolean;
  piattaforma_video: string | null;
  link_video: string | null;
  consenso_video_acquisito: boolean;
  convocante: string | null;
  amministratore_anagrafica_id: number | null;
  amministratore_nome: string | null;
  convocazione_tenuta: 'prima' | 'seconda' | null;
  convocazione_effettiva: 'prima' | 'seconda';
  prima_deserta: boolean;
  presidente: string | null;
  segretario: string | null;
  ora_apertura: string | null;
  ora_chiusura: string | null;
  allegati: string | null;
  note: string | null;
  numero_registro: string | null;
  verbale_registrato_at: string | null;
  documento_verbale: { id: number; name: string; is_published: boolean } | null;
}

export interface RigaAssemblea {
  id: number;
  titolo: string;
  tipo: string;
  tipo_label: string;
  stato: StatoAssemblea;
  stato_label: string;
  esercizio_id: number | null;
  prima_convocazione_at: string | null;
  seconda_convocazione_at: string | null;
  data_svolgimento: string | null;
  punti_count: number | null;
  numero_registro: string | null;
  verbale_registrato_at: string | null;
  documento_verbale_id: number | null;
}

export interface BozzaPunto {
  id: number;
  n: number;
  titolo?: string;
  testo?: string;
  discussione?: string;
  testo_delibera?: string;
}

export interface Bozza {
  fonte: 'ia' | 'modello';
  punti: BozzaPunto[];
  avvisi: string[];
  note_per_revisore: string[];
  modello?: string | null;
  versione_prompt?: string | null;
  generata_il?: string;
}
