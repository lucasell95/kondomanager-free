import { Component } from 'vue';

export interface Esercizio {
    id: number
    nome: string
    descrizione: string
    note: string,
    data_inizio: string,
    data_fine: string | null,
    stato: 'aperto' | 'chiuso';         
    serie_protocollo?: string | null;
}

export interface StatusType {
  value: string;
  label: string;
  icon: Component;
  colorClass: string;
}