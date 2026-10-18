<script setup lang="ts">
/**
 * Rendiconto condominiale annuale (art. 1130-bis c.c.) dell'esercizio.
 *
 * Tutti i numeri arrivano già calcolati dal server (`RendicontoService`): qui si mostrano e
 * basta. Le sole cose che si scrivono sono la nota sintetica (con i segnaposto {{F:chiave}},
 * sostituiti con i numeri in anteprima e in stampa) e i saldi degli estratti conto.
 */
import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide, { type GuideItem } from '@/components/PageHeaderGuide.vue';
import Alert from '@/components/Alert.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { usePermission } from '@/composables/permissions';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { useDateConverter } from '@/composables/useDateConverter';
import { AlertTriangle, BookOpenCheck, CheckCircle2, ChevronDown, ChevronRight, FileSpreadsheet, Printer, Save, Scale, Sparkles, Undo2, XCircle } from 'lucide-vue-next';
import type { Building } from '@/types/buildings';
import type { Esercizio } from '@/types/gestionale/esercizi';
import type { Flash } from '@/types/flash';
import type { EsitoVerifica, NotaRendiconto, Rendiconto } from '@/types/gestionale/rendiconto';

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
  esercizio: Esercizio;
  esercizi: Esercizio[];
  rendiconto: Rendiconto;
  nota: NotaRendiconto;
  iaDisponibile: boolean;
}>();

const page = usePage<{ flash?: { message?: Flash } }>();
const { generatePath, generateRoute } = usePermission();
const { euro } = useCurrencyFormatter();
const { toItalian } = useDateConverter();

const r = computed(() => props.rendiconto);
const rie = computed(() => props.rendiconto.riepilogo);
const data = (d: string | null | undefined) => (d ? toItalian(d) : '—');
const perc = (p: number | null) => (p === null ? '—' : `${p.toLocaleString('it-IT', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`);
const vuoto = (c: number) => (c === 0 ? '—' : euro(c));

const parametri = computed(() => ({ condominio: props.condominio.id, esercizio: props.esercizio.id }));

const breadcrumbs = computed(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: 'Esercizi', href: generatePath('gestionale/:condominio/esercizi', { condominio: props.condominio.id }) },
  { title: 'Rendiconto' },
]);

const guide = computed<GuideItem[]>(() => [
  { title: 'Tre parti di legge', description: 'Registro di contabilità, riepilogo finanziario e nota sintetica (art. 1130-bis c.c.), più consuntivo e riparto con i conguagli.', icon: BookOpenCheck, colorVariant: 'blue' },
  { title: 'Gestioni separate', description: "L'ordinaria e ogni straordinaria hanno consuntivo e riparto propri: non si mescolano.", icon: Scale, colorVariant: 'emerald' },
  { title: 'I numeri tornano?', description: 'Le verifiche controllano saldi, estratto conto, totali del riparto e conguagli prima della stampa.', icon: CheckCircle2, colorVariant: 'amber' },
]);

// --- Stampe ---------------------------------------------------------------------------------

const sezioniStampa = [
  { valore: 'tutto', etichetta: 'Rendiconto completo' },
  { valore: 'riepilogo', etichetta: 'Riepilogo finanziario' },
  { valore: 'registro', etichetta: 'Registro di contabilità' },
  { valore: 'consuntivo', etichetta: 'Consuntivo e preventivo' },
  { valore: 'riparto', etichetta: 'Riparto e conguagli' },
  { valore: 'nota', etichetta: 'Nota sintetica' },
];

const stampa = (sezione: string) => {
  window.open(route(generateRoute('gestionale.esercizi.rendiconto.pdf'), { ...parametri.value, sezione }), '_blank');
};

const esportaExcel = () => {
  window.location.href = route(generateRoute('gestionale.esercizi.rendiconto.xlsx'), parametri.value);
};

// --- Verifiche ------------------------------------------------------------------------------

const stileEsito = (esito: EsitoVerifica) =>
  esito === 'ok'
    ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300'
    : esito === 'avviso'
      ? 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300'
      : 'border-red-200 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-300';

const etichettaEsito = (esito: EsitoVerifica) => (esito === 'ok' ? 'Tutto torna' : esito === 'avviso' ? 'Con avvisi' : 'Da correggere');
const problemi = computed(() => r.value.verifiche.filter((v) => v.esito !== 'ok'));

// --- Estratti conto -------------------------------------------------------------------------

const estratti = ref<Record<number, string>>(
  Object.fromEntries(
    rie.value.conti.map((c) => [c.conto_id, c.estratto_conto === null ? '' : (c.estratto_conto / 100).toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 })]),
  ),
);
const salvataggioEstratti = ref(false);
const salvaEstratti = () => {
  salvataggioEstratti.value = true;
  router.put(route(generateRoute('gestionale.esercizi.rendiconto.aggiorna'), parametri.value), { saldi_estratto_conto: estratti.value }, {
    preserveScroll: true,
    onFinish: () => { salvataggioEstratti.value = false; },
  });
};

// --- Registro -------------------------------------------------------------------------------

const filtroRegistro = ref('');
const contoRegistro = ref<number | 'tutti'>('tutti');
const righeRegistro = computed(() => {
  const cerca = filtroRegistro.value.trim().toLowerCase();
  return r.value.registro.righe.filter((m) =>
    (contoRegistro.value === 'tutti' || m.conto_id === contoRegistro.value)
    && (!cerca || `${m.descrizione} ${m.protocollo} ${m.gestione}`.toLowerCase().includes(cerca)),
  );
});
const totaliRegistro = computed(() => ({
  entrate: righeRegistro.value.reduce((s, m) => s + m.entrata, 0),
  uscite: righeRegistro.value.reduce((s, m) => s + m.uscita, 0),
}));

// --- Riparto --------------------------------------------------------------------------------

const espanse = ref<string[]>([]);
const chiaveRiga = (g: number, a: number) => `${g}|${a}`;
const toggle = (k: string) => {
  espanse.value = espanse.value.includes(k) ? espanse.value.filter((x) => x !== k) : [...espanse.value, k];
};
const dettaglioCapitoli = ref<number[]>([]);
const toggleCapitoli = (g: number) => {
  dettaglioCapitoli.value = dettaglioCapitoli.value.includes(g) ? dettaglioCapitoli.value.filter((x) => x !== g) : [...dettaglioCapitoli.value, g];
};
const ruolo = (r: string | null) => ({ proprietario: 'proprietario', inquilino: 'inquilino', usufruttuario: 'usufruttuario' } as Record<string, string>)[r ?? ''] ?? (r ?? '');

// --- Nota sintetica -------------------------------------------------------------------------

const testoNota = ref(props.nota.testo);
const fonteNota = ref<'modello' | 'ia' | 'manuale'>(props.nota.fonte);
const anteprima = ref(true);
const avvisiNota = ref<string[]>([]);
const notePerRevisore = ref<string[]>([]);
const modelloIa = ref<string | null>(props.nota.modello_ia);
const versionePromptIa = ref<string | null>(props.nota.versione_prompt_ia);
const generando = ref(false);
const salvandoNota = ref(false);
const notaModificata = computed(() => testoNota.value !== props.nota.testo);

const SEGNAPOSTO = /\{\{F:([a-z0-9_.]+)\}\}/gi;
const notaCompilata = computed(() =>
  testoNota.value.replace(SEGNAPOSTO, (_, chiave: string) => props.nota.fatti[chiave.toLowerCase()]?.valore ?? `[dato non disponibile: ${chiave}]`),
);
const segnapostoMancanti = computed(() => {
  const mancanti = new Set<string>();
  for (const m of testoNota.value.matchAll(SEGNAPOSTO)) {
    if (!props.nota.fatti[m[1].toLowerCase()]) mancanti.add(m[1]);
  }
  return [...mancanti];
});

const inserisci = (chiave: string) => {
  testoNota.value = `${testoNota.value}{{F:${chiave}}}`;
  fonteNota.value = 'manuale';
  anteprima.value = false;
};

const ripristinaModello = () => {
  testoNota.value = props.nota.modello;
  fonteNota.value = 'modello';
  avvisiNota.value = [];
};

const generaBozza = async () => {
  generando.value = true;
  avvisiNota.value = [];
  notePerRevisore.value = [];
  try {
    const { data: esito } = await axios.post(route(generateRoute('gestionale.esercizi.rendiconto.bozza-nota'), parametri.value), {});
    testoNota.value = esito.testo;
    fonteNota.value = esito.fonte === 'ia' ? 'ia' : 'modello';
    avvisiNota.value = esito.avvisi ?? [];
    notePerRevisore.value = esito.note_per_revisore ?? [];
    modelloIa.value = esito.modello_ia ?? null;
    versionePromptIa.value = esito.versione_prompt ?? null;
  } catch {
    avvisiNota.value = ['Generazione non riuscita. Riprova o scrivi la nota a mano.'];
  } finally {
    generando.value = false;
  }
};

const salvaNota = () => {
  salvandoNota.value = true;
  router.put(route(generateRoute('gestionale.esercizi.rendiconto.aggiorna'), parametri.value), {
    nota_sintetica: testoNota.value,
    fonte_nota: notaModificata.value && fonteNota.value !== 'ia' ? 'manuale' : fonteNota.value,
    modello_ia: fonteNota.value === 'ia' ? modelloIa.value : null,
    versione_prompt_ia: fonteNota.value === 'ia' ? versionePromptIa.value : null,
  }, {
    preserveScroll: true,
    onFinish: () => { salvandoNota.value = false; },
  });
};

const backUrl = computed(() => generatePath('gestionale/:condominio/esercizi', { condominio: props.condominio.id }));
</script>

<template>
  <Head :title="`Rendiconto ${props.esercizio.nome}`" />

  <GestionaleLayout>
    <div class="space-y-4 px-6 py-8">
      <PageHeaderGuide
        page-title="Rendiconto condominiale"
        :page-subtitle="`Esercizio ${props.esercizio.nome}: dal ${data(r.esercizio.data_inizio)} al ${data(r.esercizio.data_fine)} — art. 1130-bis c.c.`"
        :guides="guide"
        :breadcrumbs="breadcrumbs"
        :condominio="props.condominio"
        :condomini="props.condomini"
        :esercizio="props.esercizio"
        :esercizi="props.esercizi"
        :back-url="backUrl"
        back-text="Esercizi"
      />

      <div v-if="page.props.flash?.message" class="py-1">
        <Alert :message="page.props.flash.message.message" :type="page.props.flash.message.type" />
      </div>

      <!-- Esito e azioni -->
      <div class="flex flex-wrap items-center gap-3">
        <div :class="['flex items-center gap-2 rounded-md border px-3 py-1.5 text-sm font-medium', stileEsito(r.esito_verifiche)]">
          <CheckCircle2 v-if="r.esito_verifiche === 'ok'" class="h-4 w-4" />
          <AlertTriangle v-else-if="r.esito_verifiche === 'avviso'" class="h-4 w-4" />
          <XCircle v-else class="h-4 w-4" />
          Verifiche: {{ etichettaEsito(r.esito_verifiche) }}
          <span v-if="problemi.length" class="font-normal">({{ problemi.length }})</span>
        </div>
        <div class="ml-auto flex gap-2">
          <DropdownMenu>
            <DropdownMenuTrigger as-child>
              <Button variant="outline"><Printer class="mr-2 h-4 w-4" />Stampa PDF<ChevronDown class="ml-1 h-4 w-4" /></Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuLabel>Cosa stampare</DropdownMenuLabel>
              <DropdownMenuSeparator />
              <DropdownMenuItem v-for="s in sezioniStampa" :key="s.valore" @click="stampa(s.valore)">{{ s.etichetta }}</DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
          <Button variant="outline" @click="esportaExcel"><FileSpreadsheet class="mr-2 h-4 w-4" />Excel</Button>
        </div>
      </div>

      <!-- Totali di cassa -->
      <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        <Card><CardContent class="p-4"><div class="text-xs text-muted-foreground">Saldo al {{ data(r.esercizio.data_inizio) }}</div><div class="text-lg font-semibold">{{ euro(rie.totali.saldo_iniziale) }}</div></CardContent></Card>
        <Card><CardContent class="p-4"><div class="text-xs text-muted-foreground">Entrate</div><div class="text-lg font-semibold text-emerald-700 dark:text-emerald-400">{{ euro(rie.totali.entrate) }}</div></CardContent></Card>
        <Card><CardContent class="p-4"><div class="text-xs text-muted-foreground">Uscite</div><div class="text-lg font-semibold text-red-700 dark:text-red-400">{{ euro(rie.totali.uscite) }}</div></CardContent></Card>
        <Card><CardContent class="p-4"><div class="text-xs text-muted-foreground">Saldo al {{ data(r.esercizio.data_fine) }}</div><div class="text-lg font-semibold">{{ euro(rie.totali.saldo_finale) }}</div></CardContent></Card>
      </div>

      <Tabs default-value="riepilogo" class="w-full">
        <TabsList class="flex h-auto w-full flex-wrap justify-start">
          <TabsTrigger value="riepilogo">Riepilogo finanziario</TabsTrigger>
          <TabsTrigger value="registro">Registro ({{ r.registro.righe.length }})</TabsTrigger>
          <TabsTrigger value="consuntivo">Consuntivo</TabsTrigger>
          <TabsTrigger value="riparto">Riparto e conguagli</TabsTrigger>
          <TabsTrigger value="nota">Nota sintetica</TabsTrigger>
          <TabsTrigger value="verifiche">Verifiche</TabsTrigger>
        </TabsList>

        <!-- ═══ RIEPILOGO ═══════════════════════════════════════════════════════════ -->
        <TabsContent value="riepilogo" class="space-y-4">
          <Card>
            <CardHeader class="pb-2"><CardTitle class="text-base">Cassa, banca e fondi</CardTitle></CardHeader>
            <CardContent class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead class="border-b text-xs text-muted-foreground">
                  <tr>
                    <th class="py-2 text-left">Conto</th>
                    <th class="py-2 text-right">Saldo iniziale</th>
                    <th class="py-2 text-right">Entrate</th>
                    <th class="py-2 text-right">Uscite</th>
                    <th class="py-2 text-right">Saldo finale</th>
                    <th class="py-2 text-right">Estratto conto al {{ data(r.esercizio.data_fine) }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="c in rie.conti" :key="c.conto_id" class="border-b last:border-0">
                    <td class="py-2">
                      <div class="font-medium">{{ c.nome }}</div>
                      <div v-if="c.sottotipo_label" class="text-xs text-muted-foreground">{{ c.sottotipo_label }}<span v-if="c.vincolo"> — {{ c.vincolo }}</span></div>
                    </td>
                    <td class="py-2 text-right tabular-nums">{{ euro(c.saldo_iniziale) }}</td>
                    <td class="py-2 text-right tabular-nums">{{ euro(c.entrate) }}</td>
                    <td class="py-2 text-right tabular-nums">{{ euro(c.uscite) }}</td>
                    <td class="py-2 text-right font-semibold tabular-nums">{{ euro(c.saldo_finale) }}</td>
                    <td class="py-2 text-right">
                      <Input v-model="estratti[c.conto_id]" class="ml-auto h-8 w-36 text-right" placeholder="0,00" inputmode="decimal" />
                      <div v-if="c.differenza_estratto" class="mt-1 text-xs text-red-600">differenza {{ euro(c.differenza_estratto) }}</div>
                      <div v-else-if="c.estratto_conto !== null" class="mt-1 text-xs text-emerald-600">coincide</div>
                    </td>
                  </tr>
                  <tr v-if="!rie.conti.length"><td colspan="6" class="py-4 text-center text-muted-foreground">Nessuna cassa o conto bancario.</td></tr>
                </tbody>
                <tfoot class="border-t font-semibold">
                  <tr>
                    <td class="py-2">Totale</td>
                    <td class="py-2 text-right tabular-nums">{{ euro(rie.totali.saldo_iniziale) }}</td>
                    <td class="py-2 text-right tabular-nums">{{ euro(rie.totali.entrate) }}</td>
                    <td class="py-2 text-right tabular-nums">{{ euro(rie.totali.uscite) }}</td>
                    <td class="py-2 text-right tabular-nums">{{ euro(rie.totali.saldo_finale) }}</td>
                    <td class="py-2 text-right">
                      <Button size="sm" :disabled="salvataggioEstratti" @click="salvaEstratti"><Save class="mr-1 h-4 w-4" />Salva estratti conto</Button>
                    </td>
                  </tr>
                </tfoot>
              </table>
              <p class="mt-2 text-xs text-muted-foreground">I fondi sono partizioni del conto corrente: un giroconto compare come uscita da un conto ed entrata nell'altro.</p>
            </CardContent>
          </Card>

          <div class="grid gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader class="pb-2"><CardTitle class="text-base">Entrate e uscite per categoria</CardTitle></CardHeader>
              <CardContent>
                <table class="w-full text-sm">
                  <thead class="border-b text-xs text-muted-foreground"><tr><th class="py-2 text-left">Categoria</th><th class="py-2 text-right">Entrate</th><th class="py-2 text-right">Uscite</th></tr></thead>
                  <tbody>
                    <tr v-for="c in rie.categorie" :key="c.chiave" class="border-b last:border-0" :class="c.interna ? 'text-muted-foreground italic' : ''">
                      <td class="py-1.5">{{ c.nome }}</td>
                      <td class="py-1.5 text-right tabular-nums">{{ vuoto(c.entrate) }}</td>
                      <td class="py-1.5 text-right tabular-nums">{{ vuoto(c.uscite) }}</td>
                    </tr>
                    <tr v-if="!rie.categorie.length"><td colspan="3" class="py-3 text-center text-muted-foreground">Nessun movimento.</td></tr>
                  </tbody>
                </table>
              </CardContent>
            </Card>

            <Card>
              <CardHeader class="pb-2"><CardTitle class="text-base">Fondi e riserve</CardTitle></CardHeader>
              <CardContent class="space-y-1 text-sm">
                <div class="flex justify-between"><span>Fondo speciale lavori straordinari (art. 1135 n. 4)</span><span class="font-semibold tabular-nums">{{ euro(rie.fondo_speciale) }}</span></div>
                <div v-for="f in rie.fondi.filter((x) => x.sottotipo_fondo !== 'vincolato_lavori')" :key="f.conto_id" class="flex justify-between"><span>{{ f.nome }} <span class="text-muted-foreground">({{ f.sottotipo_label }})</span></span><span class="tabular-nums">{{ euro(f.saldo_finale) }}</span></div>
                <div v-for="f in rie.fondi_passivi" :key="f.codice" class="flex justify-between text-muted-foreground"><span>{{ f.codice }} {{ f.nome }}</span><span class="tabular-nums">{{ euro(f.saldo) }}</span></div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader class="pb-2"><CardTitle class="text-base">Crediti verso condòmini al {{ data(r.esercizio.data_fine) }}</CardTitle></CardHeader>
              <CardContent>
                <table class="w-full text-sm">
                  <thead class="border-b text-xs text-muted-foreground"><tr><th class="py-2 text-left">Condòmino</th><th class="py-2 text-right">Rate non pagate</th><th class="py-2 text-right">Anticipi</th><th class="py-2 text-right">Saldo</th></tr></thead>
                  <tbody>
                    <tr v-for="c in rie.crediti.righe" :key="c.anagrafica_id" class="border-b last:border-0">
                      <td class="py-1.5">{{ c.nome }}</td>
                      <td class="py-1.5 text-right tabular-nums">{{ vuoto(c.crediti) }}</td>
                      <td class="py-1.5 text-right tabular-nums">{{ vuoto(c.anticipi) }}</td>
                      <td class="py-1.5 text-right font-medium tabular-nums" :class="c.saldo > 0 ? 'text-red-600' : ''">{{ euro(c.saldo) }}</td>
                    </tr>
                    <tr v-if="!rie.crediti.righe.length"><td colspan="4" class="py-3 text-center text-muted-foreground">Nessun credito aperto.</td></tr>
                  </tbody>
                  <tfoot class="border-t font-semibold"><tr><td class="py-2" colspan="3">Totale</td><td class="py-2 text-right tabular-nums">{{ euro(rie.crediti.totale) }}</td></tr></tfoot>
                </table>
              </CardContent>
            </Card>

            <Card>
              <CardHeader class="pb-2"><CardTitle class="text-base">Debiti al {{ data(r.esercizio.data_fine) }}</CardTitle></CardHeader>
              <CardContent>
                <table class="w-full text-sm">
                  <thead class="border-b text-xs text-muted-foreground"><tr><th class="py-2 text-left">Fornitore</th><th class="py-2 text-left">Documento</th><th class="py-2 text-right">Da pagare</th></tr></thead>
                  <tbody>
                    <tr v-for="d in rie.debiti.fatture" :key="d.fattura_id" class="border-b last:border-0">
                      <td class="py-1.5">{{ d.fornitore }}</td>
                      <td class="py-1.5">{{ d.numero }} <span class="text-xs text-muted-foreground">del {{ data(d.data) }}</span></td>
                      <td class="py-1.5 text-right tabular-nums">{{ euro(d.residuo) }}</td>
                    </tr>
                    <tr v-if="!rie.debiti.fatture.length"><td colspan="3" class="py-3 text-center text-muted-foreground">Nessuna fattura da pagare.</td></tr>
                    <tr><td class="py-1.5" colspan="2">Ritenute d'acconto da versare</td><td class="py-1.5 text-right tabular-nums">{{ euro(rie.debiti.ritenute_da_versare) }}</td></tr>
                  </tbody>
                </table>
              </CardContent>
            </Card>
          </div>

          <Card>
            <CardHeader class="pb-2">
              <CardTitle class="flex items-center gap-2 text-base">
                Situazione patrimoniale al {{ data(r.esercizio.data_fine) }}
                <Badge :variant="rie.stato_patrimoniale.quadra ? 'secondary' : 'destructive'">{{ rie.stato_patrimoniale.quadra ? 'quadra' : 'non quadra' }}</Badge>
              </CardTitle>
            </CardHeader>
            <CardContent class="grid gap-6 text-sm md:grid-cols-2">
              <div>
                <div class="mb-1 font-medium">Attività</div>
                <div v-for="v in rie.stato_patrimoniale.attivo.voci" :key="v.codice" class="flex justify-between border-b py-1 last:border-0"><span>{{ v.codice }} {{ v.nome }}</span><span class="tabular-nums">{{ euro(v.saldo) }}</span></div>
                <div class="flex justify-between pt-1 font-semibold"><span>Totale</span><span class="tabular-nums">{{ euro(rie.stato_patrimoniale.attivo.totale) }}</span></div>
              </div>
              <div>
                <div class="mb-1 font-medium">Passività e fondi</div>
                <div v-for="v in rie.stato_patrimoniale.passivo.voci" :key="v.codice" class="flex justify-between border-b py-1 last:border-0"><span>{{ v.codice }} {{ v.nome }}</span><span class="tabular-nums">{{ euro(v.saldo) }}</span></div>
                <div class="flex justify-between border-b py-1"><span>Risultato dell'esercizio</span><span class="tabular-nums">{{ euro(rie.stato_patrimoniale.risultato_esercizio) }}</span></div>
                <div v-if="rie.stato_patrimoniale.risultato_esercizi_precedenti" class="flex justify-between border-b py-1"><span>Risultato esercizi precedenti</span><span class="tabular-nums">{{ euro(rie.stato_patrimoniale.risultato_esercizi_precedenti) }}</span></div>
                <div class="flex justify-between pt-1 font-semibold"><span>Totale</span><span class="tabular-nums">{{ euro(rie.stato_patrimoniale.passivo.totale + rie.stato_patrimoniale.risultato_esercizio + rie.stato_patrimoniale.risultato_esercizi_precedenti) }}</span></div>
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        <!-- ═══ REGISTRO ════════════════════════════════════════════════════════════ -->
        <TabsContent value="registro">
          <Card>
            <CardHeader class="pb-2">
              <CardTitle class="text-base">Registro di contabilità</CardTitle>
              <div class="flex flex-wrap gap-2 pt-2">
                <Input v-model="filtroRegistro" placeholder="Cerca descrizione, protocollo, gestione…" class="h-8 w-72" />
                <select v-model="contoRegistro" class="h-8 rounded-md border bg-background px-2 text-sm">
                  <option value="tutti">Tutti i conti</option>
                  <option v-for="c in r.registro.conti" :key="c.conto_id" :value="c.conto_id">{{ c.nome }}</option>
                </select>
              </div>
            </CardHeader>
            <CardContent class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead class="border-b text-xs text-muted-foreground">
                  <tr><th class="py-2 text-left">Data</th><th class="py-2 text-left">Protocollo</th><th class="py-2 text-left">Descrizione</th><th class="py-2 text-left">Conto</th><th class="py-2 text-right">Entrate</th><th class="py-2 text-right">Uscite</th></tr>
                </thead>
                <tbody>
                  <tr v-for="m in righeRegistro" :key="`${m.scrittura_id}-${m.conto_id}`" class="border-b last:border-0" :class="m.annullata ? 'text-muted-foreground line-through' : ''">
                    <td class="whitespace-nowrap py-1.5">{{ data(m.data) }}</td>
                    <td class="whitespace-nowrap py-1.5 font-mono text-xs">{{ m.protocollo }}</td>
                    <td class="py-1.5">{{ m.descrizione }}<div class="text-xs text-muted-foreground">{{ m.categoria_label }}</div></td>
                    <td class="py-1.5">{{ m.cassa }}</td>
                    <td class="py-1.5 text-right tabular-nums text-emerald-700 dark:text-emerald-400">{{ m.entrata ? euro(m.entrata) : '' }}</td>
                    <td class="py-1.5 text-right tabular-nums text-red-700 dark:text-red-400">{{ m.uscita ? euro(m.uscita) : '' }}</td>
                  </tr>
                  <tr v-if="!righeRegistro.length"><td colspan="6" class="py-4 text-center text-muted-foreground">Nessun movimento.</td></tr>
                </tbody>
                <tfoot class="border-t font-semibold">
                  <tr><td colspan="4" class="py-2">Totale{{ righeRegistro.length !== r.registro.righe.length ? ' (righe filtrate)' : '' }}</td><td class="py-2 text-right tabular-nums">{{ euro(totaliRegistro.entrate) }}</td><td class="py-2 text-right tabular-nums">{{ euro(totaliRegistro.uscite) }}</td></tr>
                </tfoot>
              </table>
            </CardContent>
          </Card>
        </TabsContent>

        <!-- ═══ CONSUNTIVO ══════════════════════════════════════════════════════════ -->
        <TabsContent value="consuntivo" class="space-y-4">
          <Card v-for="g in r.consuntivo.gestioni" :key="g.id">
            <CardHeader class="pb-2">
              <CardTitle class="flex items-center gap-2 text-base">{{ g.nome }} <Badge variant="outline">{{ g.tipo }}</Badge></CardTitle>
            </CardHeader>
            <CardContent class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead class="border-b text-xs text-muted-foreground"><tr><th class="py-2 text-left">Voce</th><th class="py-2 text-right">Preventivo</th><th class="py-2 text-right">Consuntivo</th><th class="py-2 text-right">Scostamento</th><th class="py-2 text-right">%</th></tr></thead>
                <tbody>
                  <tr v-for="v in g.voci" :key="v.id" class="border-b last:border-0" :class="v.ha_figli ? 'font-medium' : ''">
                    <td class="py-1.5" :style="{ paddingLeft: `${v.livello * 1.25}rem` }">{{ v.nome }}<span v-if="v.tecnico" class="text-xs text-muted-foreground"> (fuori preventivo)</span></td>
                    <td class="py-1.5 text-right tabular-nums">{{ euro(v.preventivo) }}</td>
                    <td class="py-1.5 text-right tabular-nums">{{ euro(v.consuntivo) }}</td>
                    <td class="py-1.5 text-right tabular-nums" :class="v.scostamento > 0 ? 'text-red-600' : 'text-emerald-700'">{{ euro(v.scostamento, { forcePlus: true }) }}</td>
                    <td class="py-1.5 text-right tabular-nums">{{ perc(v.scostamento_perc) }}</td>
                  </tr>
                  <template v-if="g.sopravvenienze.length">
                    <tr><td colspan="5" class="bg-muted/40 py-1.5 text-xs font-semibold uppercase">Fuori preventivo</td></tr>
                    <tr v-for="v in g.sopravvenienze" :key="v.id" class="border-b"><td class="py-1.5" :style="{ paddingLeft: `${v.livello * 1.25}rem` }">{{ v.nome }}</td><td class="py-1.5 text-right">—</td><td class="py-1.5 text-right tabular-nums">{{ euro(v.consuntivo) }}</td><td class="py-1.5 text-right tabular-nums">{{ euro(v.scostamento) }}</td><td class="py-1.5 text-right">—</td></tr>
                  </template>
                  <template v-if="g.ad_personam.length">
                    <tr><td colspan="5" class="bg-muted/40 py-1.5 text-xs font-semibold uppercase">Spese individuali</td></tr>
                    <tr v-for="a in g.ad_personam" :key="a.immobile_id" class="border-b"><td class="py-1.5 pl-4">{{ a.nome }}</td><td class="py-1.5 text-right">—</td><td class="py-1.5 text-right tabular-nums">{{ euro(a.importo) }}</td><td class="py-1.5 text-right tabular-nums">{{ euro(a.importo) }}</td><td class="py-1.5 text-right">—</td></tr>
                  </template>
                  <template v-if="g.senza_voce.length">
                    <tr><td colspan="5" class="bg-amber-50 py-1.5 text-xs font-semibold uppercase text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">Costi senza voce (non ripartiti)</td></tr>
                    <tr v-for="a in g.senza_voce" :key="a.conto_contabile_id" class="border-b"><td class="py-1.5 pl-4">{{ a.conto }}</td><td class="py-1.5 text-right">—</td><td class="py-1.5 text-right tabular-nums">{{ euro(a.importo) }}</td><td class="py-1.5 text-right tabular-nums">{{ euro(a.importo) }}</td><td class="py-1.5 text-right">—</td></tr>
                  </template>
                  <tr v-if="!g.voci.length && !g.sopravvenienze.length && !g.ad_personam.length"><td colspan="5" class="py-3 text-center text-muted-foreground">Nessuna voce con preventivo o spese.</td></tr>
                </tbody>
                <tfoot class="border-t font-semibold">
                  <tr><td class="py-2">Totale gestione</td><td class="py-2 text-right tabular-nums">{{ euro(g.totali.preventivo) }}</td><td class="py-2 text-right tabular-nums">{{ euro(g.totali.consuntivo) }}</td><td class="py-2 text-right tabular-nums">{{ euro(g.totali.scostamento, { forcePlus: true }) }}</td><td class="py-2 text-right tabular-nums">{{ perc(g.totali.scostamento_perc) }}</td></tr>
                </tfoot>
              </table>
            </CardContent>
          </Card>
          <p v-if="!r.consuntivo.gestioni.length" class="text-sm text-muted-foreground">Nessuna gestione collegata all'esercizio.</p>
        </TabsContent>

        <!-- ═══ RIPARTO ═════════════════════════════════════════════════════════════ -->
        <TabsContent value="riparto" class="space-y-4">
          <Card v-for="rip in r.riparto" :key="rip.gestione_id">
            <CardHeader class="pb-2">
              <CardTitle class="flex flex-wrap items-center gap-2 text-base">
                {{ rip.gestione }} <Badge variant="outline">{{ rip.tipo }}</Badge>
                <Badge v-if="rip.totali.non_ripartito !== 0" variant="destructive">non ripartito {{ euro(rip.totali.non_ripartito) }}</Badge>
                <Button v-if="rip.colonne.length" variant="ghost" size="sm" class="ml-auto" @click="toggleCapitoli(rip.gestione_id)">
                  {{ dettaglioCapitoli.includes(rip.gestione_id) ? 'Nascondi' : 'Mostra' }} dettaglio per capitolo
                </Button>
              </CardTitle>
              <p class="text-xs text-muted-foreground">
                Conguaglio = saldo iniziale + quota consuntiva − già versato − versato. Positivo a debito, negativo a credito.
                <span v-if="rip.tipo !== 'ordinaria'">Nelle gestioni straordinarie il credito è di norma il fondo versato e non ancora speso, da riportare sulla stessa gestione.</span>
              </p>
            </CardHeader>
            <CardContent class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead class="border-b text-xs text-muted-foreground">
                  <tr>
                    <th class="py-2 text-left">Condòmino</th>
                    <template v-if="dettaglioCapitoli.includes(rip.gestione_id)">
                      <th v-for="col in rip.colonne" :key="col.chiave" class="py-2 text-right">{{ col.nome }}</th>
                    </template>
                    <th class="py-2 text-right">Saldo iniziale</th>
                    <th class="py-2 text-right">Quota consuntiva</th>
                    <th v-if="rip.totali.contributi" class="py-2 text-right">Già versato</th>
                    <th class="py-2 text-right">Versato</th>
                    <th class="py-2 text-right">Conguaglio</th>
                  </tr>
                </thead>
                <tbody>
                  <template v-for="riga in rip.righe" :key="riga.anagrafica_id">
                    <tr class="cursor-pointer border-b hover:bg-muted/40" @click="toggle(chiaveRiga(rip.gestione_id, riga.anagrafica_id))">
                      <td class="py-1.5">
                        <div class="flex items-center gap-1">
                          <ChevronDown v-if="espanse.includes(chiaveRiga(rip.gestione_id, riga.anagrafica_id))" class="h-3.5 w-3.5" />
                          <ChevronRight v-else class="h-3.5 w-3.5" />
                          <span class="font-medium">{{ riga.nome }}</span>
                        </div>
                        <div class="pl-5 text-xs text-muted-foreground">{{ riga.unita.map((u) => u.nome + (u.ruolo ? ` (${ruolo(u.ruolo)})` : '')).join(', ') }}</div>
                      </td>
                      <template v-if="dettaglioCapitoli.includes(rip.gestione_id)">
                        <td v-for="col in rip.colonne" :key="col.chiave" class="py-1.5 text-right tabular-nums">{{ vuoto(riga.per_capitolo[col.chiave] ?? 0) }}</td>
                      </template>
                      <td class="py-1.5 text-right tabular-nums">{{ vuoto(riga.saldo_iniziale) }}</td>
                      <td class="py-1.5 text-right tabular-nums">{{ euro(riga.quota) }}</td>
                      <td v-if="rip.totali.contributi" class="py-1.5 text-right tabular-nums">{{ vuoto(riga.contributi) }}</td>
                      <td class="py-1.5 text-right tabular-nums">{{ euro(riga.versato) }}</td>
                      <td class="py-1.5 text-right font-semibold tabular-nums" :class="riga.conguaglio > 0 ? 'text-red-600' : riga.conguaglio < 0 ? 'text-emerald-700' : ''">
                        {{ euro(Math.abs(riga.conguaglio)) }} <span class="text-xs font-normal">{{ riga.conguaglio > 0 ? 'a debito' : riga.conguaglio < 0 ? 'a credito' : '' }}</span>
                      </td>
                    </tr>
                    <template v-if="espanse.includes(chiaveRiga(rip.gestione_id, riga.anagrafica_id))">
                      <tr v-for="u in riga.unita" :key="`${riga.anagrafica_id}-${u.immobile_id}`" class="border-b bg-muted/20 text-xs text-muted-foreground">
                        <td class="py-1 pl-6">{{ u.nome }}<span v-if="u.ruolo"> · {{ ruolo(u.ruolo) }}</span></td>
                        <td v-if="dettaglioCapitoli.includes(rip.gestione_id)" :colspan="rip.colonne.length"></td>
                        <td class="py-1 text-right tabular-nums">{{ vuoto(u.saldo_iniziale) }}</td>
                        <td class="py-1 text-right tabular-nums">{{ euro(u.quota) }}</td>
                        <td v-if="rip.totali.contributi" class="py-1 text-right tabular-nums">{{ vuoto(u.contributi) }}</td>
                        <td class="py-1 text-right tabular-nums">{{ euro(u.versato) }}</td>
                        <td class="py-1 text-right tabular-nums">{{ euro(u.conguaglio) }}</td>
                      </tr>
                    </template>
                  </template>
                  <tr v-if="!rip.righe.length"><td colspan="8" class="py-3 text-center text-muted-foreground">Nessuna spesa né versamento.</td></tr>
                </tbody>
                <tfoot class="border-t font-semibold">
                  <tr>
                    <td class="py-2">Totale</td>
                    <template v-if="dettaglioCapitoli.includes(rip.gestione_id)">
                      <td v-for="col in rip.colonne" :key="col.chiave" class="py-2 text-right tabular-nums">{{ euro(col.totale) }}</td>
                    </template>
                    <td class="py-2 text-right tabular-nums">{{ euro(rip.totali.saldo_iniziale) }}</td>
                    <td class="py-2 text-right tabular-nums">{{ euro(rip.totali.ripartito) }}</td>
                    <td v-if="rip.totali.contributi" class="py-2 text-right tabular-nums">{{ euro(rip.totali.contributi) }}</td>
                    <td class="py-2 text-right tabular-nums">{{ euro(rip.totali.versato) }}</td>
                    <td class="py-2 text-right tabular-nums">{{ euro(rip.totali.conguaglio) }}</td>
                  </tr>
                </tfoot>
              </table>
              <div class="mt-2 flex flex-wrap gap-4 text-xs text-muted-foreground">
                <span>Spese da ripartire: <strong>{{ euro(rip.totali.spese) }}</strong></span>
                <span>Conguagli a debito: <strong class="text-red-600">{{ euro(rip.totali.a_debito) }}</strong></span>
                <span>a credito: <strong class="text-emerald-700">{{ euro(Math.abs(rip.totali.a_credito)) }}</strong></span>
              </div>
              <ul v-if="rip.non_ripartite.length" class="mt-2 list-disc pl-5 text-xs text-red-600">
                <li v-for="n in rip.non_ripartite" :key="n.voce">{{ n.voce }}: {{ euro(n.importo) }} non ripartiti (tabella millesimale o titolare mancanti)</li>
              </ul>
            </CardContent>
          </Card>
          <p v-if="!r.riparto.length" class="text-sm text-muted-foreground">Nessuna gestione da ripartire.</p>
        </TabsContent>

        <!-- ═══ NOTA SINTETICA ══════════════════════════════════════════════════════ -->
        <TabsContent value="nota" class="space-y-4">
          <Card>
            <CardHeader class="pb-2">
              <CardTitle class="flex flex-wrap items-center gap-2 text-base">
                Nota sintetica esplicativa
                <Badge variant="outline">{{ fonteNota === 'ia' ? 'bozza IA' : fonteNota === 'modello' ? 'modello predefinito' : 'scritta a mano' }}</Badge>
                <Badge v-if="!props.nota.salvata" variant="secondary">non ancora salvata</Badge>
                <div class="ml-auto flex flex-wrap gap-2">
                  <Button variant="outline" size="sm" @click="anteprima = !anteprima">{{ anteprima ? 'Modifica' : 'Anteprima' }}</Button>
                  <Button variant="outline" size="sm" @click="ripristinaModello"><Undo2 class="mr-1 h-4 w-4" />Modello predefinito</Button>
                  <Button v-if="props.iaDisponibile" variant="outline" size="sm" :disabled="generando" @click="generaBozza"><Sparkles class="mr-1 h-4 w-4" />{{ generando ? 'Generazione…' : 'Genera bozza' }}</Button>
                  <Button size="sm" :disabled="salvandoNota" @click="salvaNota"><Save class="mr-1 h-4 w-4" />Salva nota</Button>
                </div>
              </CardTitle>
              <p class="text-xs text-muted-foreground">
                I numeri si scrivono con i segnaposto <code v-pre>{{F:chiave}}</code>: in anteprima e in stampa vengono sostituiti con i valori calcolati dal rendiconto, così il testo resta allineato ai conti.
                La legge chiede almeno i rapporti in corso e le questioni pendenti: completare le parti fra parentesi quadre.
              </p>
            </CardHeader>
            <CardContent class="space-y-3">
              <ul v-if="avvisiNota.length" class="list-disc rounded-md border border-amber-200 bg-amber-50 py-2 pl-8 pr-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300">
                <li v-for="a in avvisiNota" :key="a">{{ a }}</li>
              </ul>
              <div v-if="generando" class="rounded-md border bg-muted/30 px-3 py-2 text-sm text-muted-foreground">Generazione della bozza in corso: può richiedere fino a un paio di minuti. Non chiudere la pagina.</div>
              <div v-if="notePerRevisore.length" class="rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-sm text-blue-900 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-200">
                <div class="font-medium">Note per chi rivede la bozza</div>
                <ul class="list-disc pl-5"><li v-for="n in notePerRevisore" :key="n">{{ n }}</li></ul>
              </div>
              <div v-if="fonteNota === 'ia' && modelloIa" class="text-xs text-muted-foreground">Bozza assistita da IA ({{ modelloIa }}<span v-if="versionePromptIa">, prompt {{ versionePromptIa }}</span>): va riletta e approvata.</div>
              <div v-if="segnapostoMancanti.length" class="text-sm text-red-600">Segnaposto sconosciuti: {{ segnapostoMancanti.join(', ') }}</div>

              <div v-if="anteprima" class="whitespace-pre-wrap rounded-md border bg-muted/20 p-4 text-sm leading-relaxed">{{ notaCompilata }}</div>
              <Textarea v-else v-model="testoNota" rows="22" class="font-mono text-sm" @input="fonteNota = 'manuale'" />

              <details class="text-sm">
                <summary class="cursor-pointer text-muted-foreground">Segnaposto disponibili ({{ Object.keys(props.nota.fatti).length }})</summary>
                <div class="mt-2 grid gap-1 md:grid-cols-2">
                  <button v-for="(f, chiave) in props.nota.fatti" :key="chiave" type="button" class="flex justify-between gap-2 rounded px-2 py-1 text-left hover:bg-muted" :title="f.descrizione" @click="inserisci(String(chiave))">
                    <code class="text-xs">{{ chiave }}</code><span class="truncate text-xs text-muted-foreground">{{ f.valore }}</span>
                  </button>
                </div>
              </details>
            </CardContent>
          </Card>
        </TabsContent>

        <!-- ═══ VERIFICHE ═══════════════════════════════════════════════════════════ -->
        <TabsContent value="verifiche">
          <Card>
            <CardHeader class="pb-2"><CardTitle class="text-base">Verifiche interne</CardTitle></CardHeader>
            <CardContent class="space-y-2">
              <div v-for="v in r.verifiche" :key="v.codice" :class="['rounded-md border p-3 text-sm', stileEsito(v.esito)]">
                <div class="flex items-center gap-2 font-medium">
                  <CheckCircle2 v-if="v.esito === 'ok'" class="h-4 w-4" />
                  <AlertTriangle v-else-if="v.esito === 'avviso'" class="h-4 w-4" />
                  <XCircle v-else class="h-4 w-4" />
                  {{ v.titolo }}
                </div>
                <div class="mt-1 pl-6">{{ v.dettaglio }}</div>
              </div>
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>
    </div>
  </GestionaleLayout>
</template>
