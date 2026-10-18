// Utilità condivise dalle pagine del modulo Assemblee.

import type { EsitoDelibera, StatoAssemblea } from '@/types/gestionale/assemblee';

export function dataOra(iso: string | null | undefined): string {
  if (!iso) return '—';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '—';
  return d.toLocaleString('it-IT', { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

export function data(iso: string | null | undefined): string {
  if (!iso) return '—';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '—';
  return d.toLocaleDateString('it-IT', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

export const classeStato: Record<StatoAssemblea, string> = {
  bozza: 'bg-slate-100 text-slate-700 border-slate-200 dark:bg-slate-800 dark:text-slate-200',
  convocata: 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950 dark:text-blue-200',
  svolta: 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950 dark:text-amber-200',
  verbalizzata: 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950 dark:text-emerald-200',
};

export const etichettaEsito: Record<EsitoDelibera, string> = {
  approvata: 'Approvata',
  respinta: 'Non approvata',
  non_votata: 'Non votata',
  senza_votazione: 'Senza votazione',
};

export const classeEsito: Record<EsitoDelibera, string> = {
  approvata: 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950 dark:text-emerald-200',
  respinta: 'bg-red-50 text-red-700 border-red-200 dark:bg-red-950 dark:text-red-200',
  non_votata: 'bg-slate-100 text-slate-600 border-slate-200 dark:bg-slate-800 dark:text-slate-300',
  senza_votazione: 'bg-slate-100 text-slate-600 border-slate-200 dark:bg-slate-800 dark:text-slate-300',
};

export const classeLivello: Record<string, string> = {
  error: 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200',
  warning: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200',
  info: 'border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300',
  ok: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200',
};
