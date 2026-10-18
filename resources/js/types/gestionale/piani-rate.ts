import type { Gestione } from './gestioni'
import type { Conto } from './conti'

export interface PianoRate {
  id: number
  nome: string
  descrizione: string
  note?: string
  numero_rate: number
  stato: string // o 'bozza' | 'approvato' se preferisci string literal types
  giorno_scadenza?: number
  metodo_distribuzione?: string
  data_inizio: string
  totale_capitoli?: number
  totale_piano?: number
  gestione?: Gestione
  has_saldi: boolean
}

/**
 * Stato contabile del piano nell'esercizio
 */
export interface PianoRateContabile extends PianoRate {
  capitoli: Conto[]
  budget_movements?: BudgetMovement[]
}

export interface BudgetMovement {
  source_conto_id?: number
  destination_conto_id?: number
}
/*
 * Prospetto rate unificato — payload di `ProspettoRateUnificatoService::calcola()`.
 * Tutti gli importi sono in centesimi interi; i crediti sono negativi.
 */

export type StatoRataProspetto = 'pagata' | 'parzialmente_pagata' | 'da_pagare' | 'credito' | 'annullata'
export type EmissioneProspetto = 'emessa' | 'bozza' | 'parziale'

/** I valori di una scadenza (cella), sia unificata sia del singolo piano. */
export interface ValoriScadenzaProspetto {
  importo: number
  importo_pagato: number
  versato: number
  residuo: number
  credito: number
}

/** La parte di una scadenza che viene da un singolo piano. */
export interface PezzoProspetto extends ValoriScadenzaProspetto {
  piano_id: number
  piano_nome: string
  piano_tipo: string | null
  piano_stato: string
  gestione_nome: string | null
  gestione_tipo: string | null
  numero_rata: number
  stato: StatoRataProspetto
  is_emessa: boolean
  data_pagamento: string | null
}

export interface CellaProspetto extends ValoriScadenzaProspetto {
  stato: StatoRataProspetto
  emissione: EmissioneProspetto
  n_piani: number
  dettaglio: PezzoProspetto[]
}

export interface TotaliRigaProspetto {
  totale: number
  versato: number
  da_saldare: number
  scaduto: number
  crediti: number
}

export interface SottorigaProspetto extends TotaliRigaProspetto {
  piano_id: number
  piano_nome: string
  piano_tipo: string | null
  piano_stato: string
  gestione_nome: string | null
  gestione_tipo: string | null
  celle: Record<string, CellaProspetto>
}

export interface RigaProspetto extends TotaliRigaProspetto {
  anagrafica: { id: number; nome: string; indirizzo?: string | null }
  celle: Record<string, CellaProspetto>
  sottorighe: SottorigaProspetto[]
}

export interface ScadenzaProspetto extends ValoriScadenzaProspetto {
  chiave: string
  data: string | null
  scaduta: boolean
  emissione: EmissioneProspetto
  piani: Array<{ piano_id: number; piano_nome: string; numero_rata: number; is_emessa: boolean }>
}

export interface PianoProspetto {
  id: number
  nome: string
  tipo: string | null
  stato: string
  numero_rate: number
  gestione: { id: number | null; nome: string | null; tipo: string | null }
  totale: number
}

export interface ProspettoRateUnificato {
  oggi: string
  piani: PianoProspetto[]
  scadenze: ScadenzaProspetto[]
  righe: RigaProspetto[]
  totali: TotaliRigaProspetto
}
