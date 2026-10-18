<script setup lang="ts">
/**
 * Prospetto rate unificato (pannello) — per ogni condòmino, le rate di tutti i piani dell'esercizio
 * sommate per data di scadenza. Usato nella lista dei piani rate e nella pagina dedicata.
 *
 * Cliccando un piano fra i «Piani inclusi» il pannello mostra quel piano da solo (le stesse
 * sottorighe del dettaglio per piano), e al posto dei piani compare «Torna a unificato».
 *
 * Sola lettura. Tutti i numeri arrivano già calcolati dal server
 * (`ProspettoRateUnificatoService`, che somma la stessa `quotePerAnagrafica` della pagina del
 * piano): qui non si ricalcola niente, si mostra e al più si somma il sottoinsieme filtrato.
 */
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { usePermission } from '@/composables/permissions';
import { useDateConverter } from '@/composables/useDateConverter';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { trans } from 'laravel-vue-i18n';
import { AlertCircle, Ban, CalendarDays, CheckCircle2, ArrowLeft, ChevronDown, ChevronRight, ExternalLink, ChevronsDownUp, ChevronsUpDown, Clock, Coins, FileText, Info, Layers, PieChart, Printer, ShieldCheck } from 'lucide-vue-next';
import type { Building } from '@/types/buildings';
import type { Esercizio } from '@/types/gestionale/esercizi';
import type { CellaProspetto, EmissioneProspetto, ProspettoRateUnificato, RigaProspetto, ScadenzaProspetto, StatoRataProspetto } from '@/types/gestionale/piani-rate';

const props = withDefaults(defineProps<{
  condominio: Building;
  esercizio: Esercizio;
  prospetto: ProspettoRateUnificato;
  /** Titolo del pannello con il menu Stampe (nella pagina dedicata il titolo è già nell'intestazione). */
  showHeader?: boolean;
}>(), { showHeader: true });

const { generateRoute } = usePermission();
const { toItalian } = useDateConverter();
const { euro } = useCurrencyFormatter();

const t = (chiave: string, sostituzioni?: Record<string, string | number>) =>
  trans(`gestionale.piani_rate.prospetto_unificato.${chiave}`, sostituzioni as Record<string, string> | undefined);

const urlPiano = (pianoId: number) =>
  route(generateRoute('gestionale.esercizi.piani-rate.show'), { condominio: props.condominio.id, esercizio: props.esercizio.id, pianoRate: pianoId });

const stampa = (conDettaglio: boolean) => {
  const url = route(generateRoute('gestionale.esercizi.piani-rate.prospetto-unificato.print'), {
    condominio: props.condominio.id,
    esercizio: props.esercizio.id,
    dettaglio: conDettaglio ? 1 : 0,
  });
  window.open(url, '_blank');
};

// --- Filtro ed espansione delle righe -------------------------------------------------------

// --- Vista di un solo piano -----------------------------------------------------------------
// Le sottorighe del dettaglio per piano, promosse a righe: stessi numeri della riga espansa.

const pianoSelezionato = ref<number | null>(null);
const pianoAttivo = computed(() => props.prospetto.piani.find((p) => p.id === pianoSelezionato.value) ?? null);
const selezionaPiano = (id: number | null) => {
  pianoSelezionato.value = id;
  espanse.value = [];
};

const righeBase = computed<RigaProspetto[]>(() => {
  const id = pianoSelezionato.value;
  if (id === null) return props.prospetto.righe;
  return props.prospetto.righe.flatMap((r) => {
    const sotto = r.sottorighe.find((s) => s.piano_id === id);
    return sotto ? [{ ...sotto, anagrafica: r.anagrafica, sottorighe: [] } as unknown as RigaProspetto] : [];
  });
});

/** Le colonne: tutte nel prospetto unificato, solo quelle del piano nella vista di un piano. */
const scadenze = computed<ScadenzaProspetto[]>(() => {
  const id = pianoSelezionato.value;
  if (id === null) return props.prospetto.scadenze;
  return props.prospetto.scadenze
    .map((col) => {
      const piani = col.piani.filter((p) => p.piano_id === id);
      const emesse = piani.filter((p) => p.is_emessa).length;
      const emissione: EmissioneProspetto = emesse === piani.length ? 'emessa' : emesse === 0 ? 'bozza' : 'parziale';
      return { ...col, piani, emissione };
    })
    .filter((col) => col.piani.length > 0);
});

const filtro = ref('');
const espanse = ref<number[]>([]);

const righe = computed<RigaProspetto[]>(() => {
  const cerca = filtro.value.trim().toLowerCase();
  if (!cerca) return righeBase.value;
  return righeBase.value.filter((r) => r.anagrafica.nome.toLowerCase().includes(cerca));
});

const isEspansa = (id: number) => espanse.value.includes(id);
const toggleRiga = (id: number) => {
  espanse.value = isEspansa(id) ? espanse.value.filter((x) => x !== id) : [...espanse.value, id];
};
const tutteEspanse = computed(() => righe.value.length > 0 && righe.value.every((r) => isEspansa(r.anagrafica.id)));
const toggleTutte = () => {
  espanse.value = tutteEspanse.value ? [] : righe.value.map((r) => r.anagrafica.id);
};

// --- Totali ---------------------------------------------------------------------------------
// Senza filtro coincidono con `prospetto.totali` calcolato dal server; con il filtro sono la
// somma delle sole righe visibili, così il piede della tabella torna sempre con le celle mostrate.

const totali = computed(() => {
  const perScadenza: Record<string, number> = {};
  let totale = 0; let versato = 0; let scaduto = 0; let crediti = 0;
  righe.value.forEach((r) => {
    totale += r.totale; versato += r.versato; scaduto += r.scaduto; crediti += r.crediti;
    Object.entries(r.celle ?? {}).forEach(([chiave, cella]) => {
      perScadenza[chiave] = (perScadenza[chiave] ?? 0) + cella.importo;
    });
  });
  return { totale, versato, daSaldare: totale - versato, scaduto, crediti, perScadenza };
});

// --- Presentazione --------------------------------------------------------------------------

const etichettaScadenza = (col: ScadenzaProspetto, indice: number) =>
  col.data ? t('columns.installment', { n: indice + 1 }) : t('columns.no_date');

const etichettaRata = (numero: number) => (numero === 0 ? t('tooltip.opening_balances') : t('tooltip.installment_n', { n: numero }));

const stileEmissione = (emissione: EmissioneProspetto) => {
  if (emissione === 'emessa') return 'bg-emerald-500';
  if (emissione === 'parziale') return 'bg-amber-400';
  return 'bg-slate-300';
};

/** Stesso linguaggio visivo delle celle di `PianiRateShow.vue` (`getRataStyle`). */
const stileCella = (cella: { stato: StatoRataProspetto; credito: number }, scaduta: boolean) => {
  if (cella.stato === 'annullata') return { container: 'bg-gray-50 border-gray-200 text-gray-400 opacity-60', icon: Ban, label: t('status.annullata') };
  if (cella.stato === 'credito') return { container: 'bg-blue-50 border-blue-200 text-blue-700', icon: Coins, label: t('status.credito') };
  if (cella.stato === 'pagata') return { container: 'bg-emerald-50 border-emerald-200 text-emerald-700', icon: CheckCircle2, label: t('status.pagata') };
  if (cella.stato === 'parzialmente_pagata') return { container: 'bg-amber-50 border-amber-300 text-amber-800', icon: PieChart, label: t('status.parzialmente_pagata') };
  if (scaduta) return { container: 'bg-white border-red-300 text-red-700 shadow-sm', icon: AlertCircle, label: t('status.scaduta') };
  return { container: 'bg-white border-gray-200 text-gray-600', icon: Clock, label: t('status.da_pagare') };
};

const coloreSaldo = (valore: number) => (valore > 0 ? 'text-red-600' : valore < 0 ? 'text-blue-600' : 'text-emerald-600');
const etichettaSaldo = (valore: number) => (valore > 0 ? t('owes') : valore < 0 ? t('credit') : t('ok'));

const cellaDi = (riga: { celle: Record<string, CellaProspetto> }, chiave: string): CellaProspetto | undefined => riga.celle?.[chiave];
</script>

<template>
  <section class="w-full space-y-6">

  <!-- TITOLO E STAMPE -->
  <div v-if="props.showHeader" class="flex items-center justify-between gap-3">
    <h2 class="text-base font-semibold text-slate-800 dark:text-slate-100 flex items-center gap-2">
      <Layers class="w-4 h-4 text-indigo-500" />
      {{ t('section_title') }}
    </h2>
    <DropdownMenu>
      <DropdownMenuTrigger as-child>
        <Button variant="outline" class="h-8 px-3 border-slate-200 text-slate-700 bg-white hover:bg-slate-50 shadow-sm shrink-0 gap-2">
          <Printer class="w-4 h-4" />
          <span class="hidden sm:inline">{{ t('print.label') }}</span>
          <ChevronDown class="w-3 h-3 opacity-60" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" class="w-60 shadow-xl rounded-xl border-slate-100 p-1.5">
        <DropdownMenuLabel class="text-[10px] text-slate-400 uppercase tracking-widest px-2 py-1.5 font-bold">{{ t('print.section') }}</DropdownMenuLabel>
        <DropdownMenuSeparator class="bg-slate-100" />
        <DropdownMenuItem class="cursor-pointer flex items-center gap-2.5 px-2 py-2 rounded-lg hover:bg-indigo-50 focus:bg-indigo-50 text-slate-700" @click="stampa(false)">
          <FileText class="w-3.5 h-3.5 text-indigo-500" />
          <div>
            <div class="text-xs font-medium">{{ t('print.summary') }}</div>
            <div class="text-[10px] text-slate-400">{{ t('print.summary_help') }}</div>
          </div>
        </DropdownMenuItem>
        <DropdownMenuItem class="cursor-pointer flex items-center gap-2.5 px-2 py-2 rounded-lg hover:bg-indigo-50 focus:bg-indigo-50 text-slate-700" @click="stampa(true)">
          <Layers class="w-3.5 h-3.5 text-indigo-500" />
          <div>
            <div class="text-xs font-medium">{{ t('print.detailed') }}</div>
            <div class="text-[10px] text-slate-400">{{ t('print.detailed_help') }}</div>
          </div>
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  </div>

  <!-- AVVISO: SOLA LETTURA -->
  <div class="rounded-md border border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-900/30 px-4 py-3 text-xs text-slate-600 dark:text-slate-400 flex items-start gap-2">
    <Info class="w-4 h-4 shrink-0 text-slate-400 mt-0.5" />
    <span>{{ t('read_only_notice') }}</span>
  </div>

  <!-- PIANI INCLUSI: un clic mostra il piano da solo; al loro posto compare «Torna a unificato» -->
  <div v-if="props.prospetto.piani.length" class="flex flex-wrap items-center gap-2 min-h-8">
    <template v-if="pianoAttivo === null">
      <span class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mr-1">{{ t('plans_included') }}</span>
      <button
        v-for="piano in props.prospetto.piani"
        :key="piano.id"
        type="button"
        :title="t('show_plan')"
        class="inline-flex items-center gap-2 h-8 px-2.5 rounded-md border bg-white dark:bg-slate-950 text-xs shadow-sm hover:border-slate-300 hover:bg-slate-50 transition-colors"
        @click="selezionaPiano(piano.id)"
      >
        <span class="font-semibold text-slate-800 dark:text-slate-200">{{ piano.nome }}</span>
        <span class="text-slate-400">{{ piano.gestione.nome }}</span>
        <span class="text-slate-500">{{ euro(piano.totale) }}</span>
        <Badge
          variant="outline"
          class="text-[9px] uppercase tracking-wide px-1.5 py-0"
          :class="piano.stato === 'approvato' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-gray-200 bg-gray-100 text-gray-500'"
        >
          {{ piano.stato === 'approvato' ? t('plan_approved') : t('plan_draft') }}
        </Badge>
      </button>
    </template>
    <template v-else>
      <Button variant="outline" class="h-8 px-3 text-xs gap-2 border-indigo-200 text-indigo-700 bg-indigo-50 hover:bg-indigo-100" @click="selezionaPiano(null)">
        <ArrowLeft class="w-3.5 h-3.5" />
        {{ t('back_to_unified') }}
      </Button>
      <span class="text-[10px] font-bold uppercase tracking-widest text-slate-400 ml-2">{{ t('single_plan_notice') }}:</span>
      <span class="inline-flex items-center gap-2 h-8 px-2.5 rounded-md border border-indigo-200 bg-white dark:bg-slate-950 text-xs shadow-sm">
        <span class="font-semibold text-slate-800 dark:text-slate-200">{{ pianoAttivo.nome }}</span>
        <span class="text-slate-400">{{ pianoAttivo.gestione.nome }}</span>
        <span class="text-slate-500">{{ euro(pianoAttivo.totale) }}</span>
      </span>
      <Link :href="urlPiano(pianoAttivo.id)" class="inline-flex items-center gap-1 text-xs text-slate-500 hover:text-slate-800 hover:underline ml-1">
        <ExternalLink class="w-3 h-3" />
        {{ t('open_plan_page') }}
      </Link>
    </template>
  </div>

  <!-- CARD DI RIEPILOGO -->
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
    <Card class="bg-white shadow-sm border">
      <CardHeader class="p-4 pb-2"><CardTitle class="text-xs uppercase text-gray-400 tracking-wider">{{ t('cards.total') }}</CardTitle></CardHeader>
      <CardContent class="p-4 pt-0 text-xl font-bold text-gray-900">{{ euro(totali.totale) }}</CardContent>
    </Card>
    <Card class="bg-emerald-50/40 shadow-sm border-emerald-100">
      <CardHeader class="p-4 pb-2"><CardTitle class="text-xs uppercase text-emerald-400 tracking-wider">{{ t('cards.collected') }}</CardTitle></CardHeader>
      <CardContent class="p-4 pt-0 text-xl font-bold text-emerald-600">{{ euro(totali.versato) }}</CardContent>
    </Card>
    <Card class="bg-gray-50 shadow-sm border">
      <CardHeader class="p-4 pb-2"><CardTitle class="text-xs uppercase text-gray-500 tracking-wider">{{ t('cards.to_settle') }}</CardTitle></CardHeader>
      <CardContent class="p-4 pt-0">
        <div class="text-xl font-bold" :class="coloreSaldo(totali.daSaldare)">{{ euro(totali.daSaldare) }}</div>
        <div v-if="totali.crediti > 0" class="text-[10px] text-blue-600 mt-1">{{ t('cards.credits_note', { amount: euro(totali.crediti) }) }}</div>
      </CardContent>
    </Card>
    <Card class="bg-red-50/40 shadow-sm border-red-100">
      <CardHeader class="p-4 pb-2 flex flex-row items-center justify-between space-y-0">
        <CardTitle class="text-xs uppercase text-red-400 tracking-wider">{{ t('cards.overdue') }}</CardTitle>
        <TooltipProvider>
          <Tooltip>
            <TooltipTrigger><Info class="w-3 h-3 text-red-300 hover:text-red-500 transition-colors cursor-help" /></TooltipTrigger>
            <TooltipContent><p class="text-xs max-w-[240px]">{{ t('cards.overdue_help') }}</p></TooltipContent>
          </Tooltip>
        </TooltipProvider>
      </CardHeader>
      <CardContent class="p-4 pt-0 text-xl font-bold text-red-600">{{ euro(totali.scaduto) }}</CardContent>
    </Card>
  </div>

  <!-- TABELLA -->
  <div v-if="righeBase.length" class="space-y-3">
    <div class="flex items-center justify-between gap-2">
      <Input v-model="filtro" :placeholder="t('filter_placeholder')" class="h-8 w-[200px] lg:w-[260px]" />
      <Button v-if="pianoAttivo === null" variant="outline" class="h-8 px-3 text-xs gap-2" @click="toggleTutte">
        <component :is="tutteEspanse ? ChevronsDownUp : ChevronsUpDown" class="w-3.5 h-3.5" />
        {{ tutteEspanse ? t('collapse_all') : t('expand_all') }}
      </Button>
    </div>

    <div class="overflow-x-auto border rounded-lg shadow-sm">
      <table class="w-full text-sm border-collapse bg-white dark:bg-slate-950 whitespace-nowrap">
        <thead class="sticky top-0 bg-white z-20 shadow-sm">
          <tr class="border-b bg-gray-50/80 text-gray-500">
            <th class="text-left px-6 py-3 sticky left-0 bg-gray-50 z-30 min-w-[240px] font-semibold uppercase text-xs tracking-wider shadow-[2px_0_5px_-2px_rgba(0,0,0,0.1)]">
              {{ t('columns.anagrafica') }}
            </th>
            <th v-for="(col, i) in scadenze" :key="col.chiave" class="text-center px-3 py-3 min-w-[110px]">
              <TooltipProvider :delayDuration="100">
                <Tooltip>
                  <TooltipTrigger class="cursor-help">
                    <div class="font-semibold text-gray-700 flex items-center justify-center gap-1.5">
                      {{ etichettaScadenza(col, i) }}
                      <span class="inline-block h-2 w-2 rounded-full ring-1 ring-white" :class="stileEmissione(col.emissione)"></span>
                    </div>
                    <div class="text-[10px] opacity-75 font-normal">{{ col.data ? toItalian(col.data) : '—' }}</div>
                  </TooltipTrigger>
                  <TooltipContent class="text-xs bg-slate-900 text-white border-slate-800">
                    <p class="font-bold mb-1">{{ t(`emission.${col.emissione}`) }}</p>
                    <div v-for="p in col.piani" :key="`${p.piano_id}-${p.numero_rata}`" class="flex justify-between gap-4 text-slate-300">
                      <span>{{ p.piano_nome }} · {{ etichettaRata(p.numero_rata) }}</span>
                      <span :class="p.is_emessa ? 'text-emerald-300' : 'text-slate-400'">{{ p.is_emessa ? t('emission.emessa') : t('emission.bozza') }}</span>
                    </div>
                  </TooltipContent>
                </Tooltip>
              </TooltipProvider>
            </th>
            <th class="text-right px-4 py-3 min-w-[110px] border-l text-gray-700">{{ t('columns.total_year') }}</th>
            <th class="text-right px-4 py-3 min-w-[100px] bg-emerald-50/20 text-emerald-600">{{ t('columns.paid') }}</th>
            <th class="text-right px-4 py-3 min-w-[120px]">{{ t('columns.to_settle') }}</th>
            <th class="text-right px-4 py-3 min-w-[100px] bg-red-50/20 text-red-600">{{ t('columns.overdue') }}</th>
          </tr>
        </thead>

        <tbody class="divide-y divide-gray-100">
          <template v-for="riga in righe" :key="riga.anagrafica.id">
            <tr class="hover:bg-gray-50 transition-colors group">
              <td class="px-4 py-3 font-medium sticky left-0 bg-white group-hover:bg-gray-50 z-10 border-r border-gray-100 shadow-[2px_0_5px_-2px_rgba(0,0,0,0.05)]">
                <div class="flex items-center gap-2">
                  <button
                    v-if="pianoAttivo === null"
                    type="button"
                    class="h-6 w-6 shrink-0 inline-flex items-center justify-center rounded text-slate-400 hover:text-slate-700 hover:bg-slate-100"
                    :title="t('toggle_detail')"
                    :aria-expanded="isEspansa(riga.anagrafica.id)"
                    @click="toggleRiga(riga.anagrafica.id)"
                  >
                    <component :is="isEspansa(riga.anagrafica.id) ? ChevronDown : ChevronRight" class="w-4 h-4" />
                  </button>
                  <div>
                    <Link
                      :href="route('admin.gestionale.anagrafiche.estratto-conto', { condominio: props.condominio.id, anagrafica: riga.anagrafica.id })"
                      class="font-semibold text-primary hover:text-primary/80 hover:underline transition-colors block"
                    >
                      {{ riga.anagrafica.nome }}
                    </Link>
                    <div v-if="riga.anagrafica.indirizzo" class="text-xs text-muted-foreground mt-0.5">{{ riga.anagrafica.indirizzo }}</div>
                  </div>
                </div>
              </td>

              <td v-for="col in scadenze" :key="col.chiave" class="px-2 py-2 text-center align-middle">
                <template v-if="cellaDi(riga, col.chiave)">
                  <TooltipProvider>
                    <Tooltip :delayDuration="200">
                      <TooltipTrigger as-child>
                        <div
                          class="relative flex flex-col items-center justify-center rounded-lg border p-1.5 h-[56px] w-[96px] mx-auto transition-all cursor-help"
                          :class="stileCella(cellaDi(riga, col.chiave)!, col.scaduta).container"
                          :style="cellaDi(riga, col.chiave)!.emissione !== 'emessa' ? 'border-style: dashed;' : ''"
                        >
                          <div class="flex items-center gap-1 font-bold">
                            <component :is="stileCella(cellaDi(riga, col.chiave)!, col.scaduta).icon" class="w-3 h-3 opacity-60" />
                            <span class="text-xs">{{ euro(cellaDi(riga, col.chiave)!.importo) }}</span>
                          </div>
                          <div class="mt-0.5">
                            <span v-if="cellaDi(riga, col.chiave)!.emissione !== 'emessa'" class="text-[9px] font-bold text-gray-400 uppercase tracking-wide bg-gray-100 px-1.5 rounded-sm">
                              {{ t(`emission.${cellaDi(riga, col.chiave)!.emissione}`) }}
                            </span>
                            <span v-else-if="cellaDi(riga, col.chiave)!.stato === 'parzialmente_pagata'" class="text-[10px] opacity-80">
                              {{ t('tooltip.residual') }} {{ euro(cellaDi(riga, col.chiave)!.residuo) }}
                            </span>
                            <span v-else class="text-[10px] opacity-75">{{ stileCella(cellaDi(riga, col.chiave)!, col.scaduta).label }}</span>
                          </div>
                          <div
                            v-if="cellaDi(riga, col.chiave)!.n_piani > 1"
                            class="absolute -top-1.5 -right-1.5 bg-slate-700 text-white text-[8px] font-bold rounded-full h-4 min-w-4 px-1 flex items-center justify-center ring-1 ring-white"
                          >
                            {{ cellaDi(riga, col.chiave)!.n_piani }}
                          </div>
                        </div>
                      </TooltipTrigger>
                      <TooltipContent side="top" class="bg-slate-900 text-white border-slate-800 shadow-xl">
                        <div class="text-xs min-w-[220px]">
                          <p class="font-bold mb-1 text-center">{{ stileCella(cellaDi(riga, col.chiave)!, col.scaduta).label }}</p>
                          <div class="space-y-1 pb-1.5 mb-1.5 border-b border-white/20">
                            <div v-for="pezzo in cellaDi(riga, col.chiave)!.dettaglio" :key="`${pezzo.piano_id}-${pezzo.numero_rata}`" class="flex justify-between gap-4">
                              <span class="text-slate-300">
                                {{ pezzo.piano_nome }}
                                <span class="text-slate-500">· {{ etichettaRata(pezzo.numero_rata) }}<template v-if="!pezzo.is_emessa"> · {{ t('emission.bozza') }}</template></span>
                              </span>
                              <span :class="pezzo.importo < 0 ? 'text-blue-300' : ''">{{ euro(pezzo.importo) }}</span>
                            </div>
                          </div>
                          <div class="flex justify-between gap-4 font-bold"><span>{{ t('tooltip.total') }}</span><span>{{ euro(cellaDi(riga, col.chiave)!.importo) }}</span></div>
                          <div v-if="cellaDi(riga, col.chiave)!.versato" class="flex justify-between gap-4 text-emerald-300"><span>{{ t('tooltip.paid') }}</span><span>{{ euro(cellaDi(riga, col.chiave)!.versato) }}</span></div>
                          <div v-if="cellaDi(riga, col.chiave)!.residuo" class="flex justify-between gap-4 text-red-200"><span>{{ t('tooltip.residual') }}</span><span>{{ euro(cellaDi(riga, col.chiave)!.residuo) }}</span></div>
                          <div v-if="cellaDi(riga, col.chiave)!.credito" class="flex justify-between gap-4 text-blue-300"><span>{{ t('tooltip.credit') }}</span><span>{{ euro(cellaDi(riga, col.chiave)!.credito) }}</span></div>
                        </div>
                      </TooltipContent>
                    </Tooltip>
                  </TooltipProvider>
                </template>
                <span v-else class="text-gray-200 text-xs">—</span>
              </td>

              <td class="px-4 py-2 text-right font-semibold text-xs text-gray-800 border-l">{{ euro(riga.totale) }}</td>
              <td class="px-4 py-2 text-right text-emerald-600 font-medium text-xs bg-emerald-50/10">{{ euro(riga.versato) }}</td>
              <td class="px-4 py-2 text-right">
                <div class="flex flex-col items-end" :class="coloreSaldo(riga.da_saldare)">
                  <span class="font-bold text-sm">{{ euro(riga.da_saldare) }}</span>
                  <span class="text-[9px] uppercase font-bold opacity-70">{{ etichettaSaldo(riga.da_saldare) }}</span>
                </div>
              </td>
              <td class="px-4 py-2 text-right text-red-600 font-medium text-xs bg-red-50/10">{{ riga.scaduto ? euro(riga.scaduto) : '—' }}</td>
            </tr>

            <!-- DETTAGLIO PER PIANO -->
            <template v-if="isEspansa(riga.anagrafica.id)">
              <tr v-for="sotto in riga.sottorighe" :key="`${riga.anagrafica.id}-${sotto.piano_id}`" class="bg-slate-50/60 dark:bg-slate-900/40 text-xs text-slate-600">
                <td class="pl-14 pr-4 py-2 sticky left-0 bg-slate-50 dark:bg-slate-900 z-10 border-r border-gray-100">
                  <div class="flex items-center gap-2">
                    <span class="text-slate-400">{{ t('detail_of') }}</span>
                    <Link :href="urlPiano(sotto.piano_id)" class="font-medium text-slate-700 hover:underline">{{ sotto.piano_nome }}</Link>
                    <Badge v-if="sotto.piano_stato !== 'approvato'" variant="outline" class="text-[8px] uppercase px-1 py-0 border-gray-200 bg-gray-100 text-gray-500">{{ t('plan_draft') }}</Badge>
                  </div>
                  <div class="text-[10px] text-slate-400">{{ sotto.gestione_nome }}</div>
                </td>
                <td v-for="col in scadenze" :key="col.chiave" class="px-2 py-2 text-center">
                  <span
                    v-if="cellaDi(sotto, col.chiave)"
                    class="inline-flex items-center gap-1"
                    :class="stileCella(cellaDi(sotto, col.chiave)!, col.scaduta).container.split(' ').filter(c => c.startsWith('text-')).join(' ')"
                  >
                    <component :is="stileCella(cellaDi(sotto, col.chiave)!, col.scaduta).icon" class="w-3 h-3 opacity-60" />
                    {{ euro(cellaDi(sotto, col.chiave)!.importo) }}
                  </span>
                  <span v-else class="text-gray-200">—</span>
                </td>
                <td class="px-4 py-2 text-right border-l">{{ euro(sotto.totale) }}</td>
                <td class="px-4 py-2 text-right text-emerald-600">{{ euro(sotto.versato) }}</td>
                <td class="px-4 py-2 text-right" :class="coloreSaldo(sotto.da_saldare)">{{ euro(sotto.da_saldare) }}</td>
                <td class="px-4 py-2 text-right text-red-600">{{ sotto.scaduto ? euro(sotto.scaduto) : '—' }}</td>
              </tr>
            </template>
          </template>
        </tbody>

        <tfoot class="sticky bottom-0 bg-white z-20 shadow-[0_-2px_5px_rgba(0,0,0,0.05)]">
          <tr class="border-t-2 border-muted bg-gray-50 font-bold text-gray-700">
            <td class="px-6 py-3 sticky left-0 bg-gray-50 z-30 border-r shadow-[2px_0_5px_-2px_rgba(0,0,0,0.05)]">{{ t('columns.total_row') }}</td>
            <td v-for="col in scadenze" :key="col.chiave" class="text-center px-3 py-3">
              {{ euro(totali.perScadenza[col.chiave] ?? 0) }}
            </td>
            <td class="px-4 py-3 text-right border-l">{{ euro(totali.totale) }}</td>
            <td class="px-4 py-3 text-right text-emerald-600 bg-emerald-50/20">{{ euro(totali.versato) }}</td>
            <td class="px-4 py-3 text-right" :class="coloreSaldo(totali.daSaldare)">{{ euro(totali.daSaldare) }}</td>
            <td class="px-4 py-3 text-right text-red-600 bg-red-50/20">{{ euro(totali.scaduto) }}</td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

  <div v-else class="text-center py-12 text-muted-foreground bg-gray-50 rounded-lg border border-dashed">
    {{ t('empty') }}
  </div>

</section>
</template>
