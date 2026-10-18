/**
 * Rendiconto condominiale (art. 1130-bis c.c.) — forma dei dati prodotti da
 * `App\Services\Gestionale\Rendiconto\RendicontoService::calcola()`. Importi in centesimi.
 */

export type EsitoVerifica = 'ok' | 'avviso' | 'errore';

export interface RigaRegistro {
  scrittura_id: number;
  data: string;
  protocollo: string;
  descrizione: string;
  tipo: string;
  tipo_label: string;
  categoria: string;
  categoria_label: string;
  gestione_id: number | null;
  gestione: string;
  conto_id: number;
  cassa: string;
  entrata: number;
  uscita: number;
  annullata: boolean;
}

export interface TotaliCassa {
  saldo_iniziale: number;
  entrate: number;
  uscite: number;
  saldo_finale: number;
}

export interface ContoLiquidita extends TotaliCassa {
  conto_id: number;
  cassa_id: number | null;
  codice: string;
  nome: string;
  tipo: string;
  sottotipo_fondo: string | null;
  sottotipo_label: string | null;
  vincolo: string | null;
  saldo_contabile: number;
  estratto_conto: number | null;
  differenza_estratto: number | null;
}

export interface CategoriaRiepilogo {
  chiave: string;
  nome: string;
  entrate: number;
  uscite: number;
  netto: number;
  interna: boolean;
}

export interface VoceSp {
  codice: string;
  nome: string;
  categoria: string;
  saldo: number;
}

export interface Riepilogo {
  conti: ContoLiquidita[];
  totali: TotaliCassa;
  categorie: CategoriaRiepilogo[];
  fondi: ContoLiquidita[];
  fondo_speciale: number;
  fondi_passivi: { codice: string; nome: string; saldo: number }[];
  crediti: {
    righe: { anagrafica_id: number; nome: string; crediti: number; anticipi: number; saldo: number }[];
    totale_a_debito: number;
    totale_a_credito: number;
    totale: number;
    condomini_a_debito: number;
    anticipazioni_ad_personam: number;
  };
  debiti: {
    fatture: { fattura_id: number; fornitore: string; numero: string; data: string; scadenza: string | null; residuo: number }[];
    totale_fatture: number;
    mastro_fornitori: number;
    ritenute_da_versare: number;
  };
  stato_patrimoniale: {
    attivo: { totale: number; voci: VoceSp[] };
    passivo: { totale: number; voci: VoceSp[] };
    costi: number;
    ricavi: number;
    risultato_esercizio: number;
    risultato_esercizi_precedenti: number;
    liquidita_non_contabilizzata: number;
    sbilancio: number;
    quadra: boolean;
  };
}

export interface VoceConsuntivo {
  id: number;
  codice: string | null;
  nome: string;
  livello: number;
  tecnico: boolean;
  preventivo: number;
  consuntivo: number;
  consuntivo_proprio: number;
  scostamento: number;
  scostamento_perc: number | null;
  ha_figli: boolean;
}

export interface GestioneConsuntivo {
  id: number;
  nome: string;
  tipo: string;
  piano_conto_id: number | null;
  voci: VoceConsuntivo[];
  sopravvenienze: VoceConsuntivo[];
  ad_personam: { immobile_id: number; nome: string; importo: number }[];
  senza_voce: { conto_contabile_id: number; conto: string; importo: number }[];
  totali: {
    preventivo: number;
    voci: number;
    sopravvenienze: number;
    ad_personam: number;
    senza_voce: number;
    consuntivo: number;
    scostamento: number;
    scostamento_perc: number | null;
    ripartibile: number;
  };
}

export interface UnitaRiparto {
  immobile_id: number | null;
  nome: string;
  ruolo: string | null;
  saldo_iniziale: number;
  quota: number;
  contributi: number;
  versato: number;
  conguaglio: number;
}

export interface RigaRiparto {
  anagrafica_id: number;
  nome: string;
  unita: UnitaRiparto[];
  saldo_iniziale: number;
  quota: number;
  per_capitolo: Record<string, number>;
  contributi: number;
  versato: number;
  conguaglio: number;
}

export interface RipartoGestione {
  gestione_id: number;
  gestione: string;
  tipo: string;
  colonne: { chiave: string; nome: string; totale: number }[];
  righe: RigaRiparto[];
  totali: {
    spese: number;
    ripartito: number;
    non_ripartito: number;
    saldo_iniziale: number;
    contributi: number;
    versato: number;
    conguaglio: number;
    a_debito: number;
    a_credito: number;
  };
  non_ripartite: { voce: string; importo: number }[];
  contributi_non_attribuiti: number;
  millesimi_non_compilati: number;
}

export interface Verifica {
  codice: string;
  titolo: string;
  esito: EsitoVerifica;
  dettaglio: string;
}

export interface Rendiconto {
  condominio: { id: number; nome: string; indirizzo: string; codice_fiscale: string };
  esercizio: { id: number; nome: string; data_inizio: string | null; data_fine: string | null; stato: string };
  generato_il: string;
  registro: { righe: RigaRegistro[]; conti: ContoLiquidita[]; totali: TotaliCassa };
  riepilogo: Riepilogo;
  consuntivo: { gestioni: GestioneConsuntivo[] };
  riparto: RipartoGestione[];
  verifiche: Verifica[];
  esito_verifiche: EsitoVerifica;
}

export interface NotaRendiconto {
  testo: string;
  salvata: boolean;
  fonte: 'modello' | 'ia' | 'manuale';
  modello_ia: string | null;
  versione_prompt_ia: string | null;
  modello: string;
  compilata: string;
  mancanti: string[];
  fatti: Record<string, { valore: string; descrizione: string }>;
  aggiornata_il: string | null;
}
