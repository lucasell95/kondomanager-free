<script setup lang="ts">
/**
 * Passaggio d'esercizio guidato: «Chiudi esercizio e apri il successivo».
 *
 * Quattro passi — controlli, saldi per condòmino, gestioni e nuovo esercizio, conferma — su
 * numeri calcolati dal server con gli stessi servizi che poi scrivono (l'anteprima è il calcolo).
 * Per ogni residuo il server manda già il risultato di ogni scelta possibile (`scenari`): qui si
 * sceglie solo quale mostrare, senza rifare i conti.
 *
 * A chiusura eseguita la pagina mostra il riepilogo e, finché nel nuovo esercizio non si è
 * registrato niente, il pulsante per annullarla.
 */
import { computed, reactive, ref } from 'vue';
import { Head, Link, useForm, usePage, router } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import Alert from '@/components/Alert.vue';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { Switch } from '@/components/ui/switch';
import InputError from '@/components/InputError.vue';
import {
  AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription,
  AlertDialogFooter, AlertDialogHeader, AlertDialogTitle, AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { usePermission } from '@/composables/permissions';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { useDateConverter } from '@/composables/useDateConverter';
import {
  ArrowLeft, ArrowRight, CalendarRange, CheckCircle2, CircleAlert, FileClock, Info, LoaderCircle,
  Lock, RotateCcw, TriangleAlert, Undo2, Wallet,
} from 'lucide-vue-next';
import type { Flash } from '@/types/flash';
import type { Building } from '@/types/buildings';

type Livello = 'errore' | 'avviso' | 'ok' | 'info';
type Modalita = 'riporta' | 'mantieni';
type ChiaveScenario = 'riporta_riapri' | 'mantieni_riapri' | 'mantieni_chiusa' | 'riporta_nuova' | 'mantieni_nuova';

interface Controllo { codice: string; livello: Livello; titolo: string; dettaglio: string; conteggio: number }
interface GestioneChiusura {
  id: number; nome: string; tipo: string; nell_esercizio: boolean; quote_non_emesse: number; piani_in_bozza: number;
  modalita_predefinita: Modalita; riapri_predefinito: boolean;
}
interface Scenario { riportato: number; mantenuto: number; differenza: number }
interface Residuo {
  chiave: string; anagrafica_id: number | null; anagrafica: string; immobile_id: number | null; immobile: string;
  gestione_id: number | null; saldo_iniziale: number; residuo: number; conguaglio: number; debito_quote: number;
  credito_quote: number; quote_aperte: number; extra: number; saldi_liberi: number; non_emesse: number;
  scenari: Record<ChiaveScenario, Scenario>;
}
interface EsercizioBase { id: number; nome: string; descrizione?: string; data_inizio: string; data_fine: string; stato: string; serie_protocollo?: string | null }
interface RiepilogoChiusura {
  id: number; eseguita_il: string | null; autore: string | null; esercizio_nuovo: EsercizioBase | null;
  totali: { residuo: number; riportato: number; mantenuto: number } | null;
  scritture: number; quote_riportate: number; saldi_creati: number; annullabile: boolean; motivi_blocco: string[];
  residui: { anagrafica: string; immobile: string; gestione: string; modalita: string | null; residuo: number; riportato: number; mantenuto: number; conguaglio: number }[];
}

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
  esercizio: EsercizioBase;
  chiusura: RiepilogoChiusura | null;
  ultima_annullata: { annullata_il: string | null } | null;
  controlli?: Controllo[];
  gestioni?: GestioneChiusura[];
  residui?: Residuo[];
  proposta?: { nome: string; descrizione: string; data_inizio: string; data_fine: string; serie: string };
}>();

const { generatePath, generateRoute } = usePermission();
const { format: euro } = useCurrencyFormatter();
const { toItalian } = useDateConverter();

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const breadcrumbs = computed(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: 'Esercizi', href: generatePath('gestionale/:condominio/esercizi', { condominio: props.condominio.id }) },
  { title: "Passaggio d'esercizio" },
]);

const pageGuides = [
  {
    title: 'Niente conta due volte',
    description: "Il residuo di ogni condòmino passa al nuovo esercizio una volta sola: come saldo iniziale che il nuovo piano assorbe (le vecchie quote si chiudono per riporto), oppure restando sulle quote originarie.",
    icon: Wallet, colorVariant: 'blue' as const,
  },
  {
    title: 'Esercizio chiuso, sigillato',
    description: "Dopo la chiusura le date e il nome non si cambiano più, e non si riapre dal modulo di modifica. Finché nel nuovo esercizio non registri niente, puoi annullare la chiusura da qui.",
    icon: Lock, colorVariant: 'emerald' as const,
  },
  {
    title: 'Fatture tardive',
    description: "Una fattura di competenza dell'esercizio chiuso che arriva dopo si registra nel nuovo esercizio come debito pregresso, con la sua data: il modulo della fattura te lo segnala.",
    icon: FileClock, colorVariant: 'amber' as const,
  },
];

const backUrl = computed(() => route(generateRoute('gestionale.esercizi.index'), { condominio: props.condominio.id }));

// ─── Procedura ──────────────────────────────────────────────────────────────────────────────
const passi = ['Controlli', 'Saldi per condòmino', 'Gestioni e nuovo esercizio', 'Conferma'];
const passo = ref(0);

const controlli = computed(() => props.controlli ?? []);
const errori = computed(() => controlli.value.filter((c) => c.livello === 'errore'));
const gestioni = computed(() => props.gestioni ?? []);
const gestioniPerId = computed(() => Object.fromEntries(gestioni.value.map((g) => [g.id, g])));

const scelte = reactive<Record<number, { modalita: Modalita; riapri: boolean }>>(
  Object.fromEntries((props.gestioni ?? []).map((g) => [g.id, { modalita: g.modalita_predefinita, riapri: g.riapri_predefinito }])),
);

const form = useForm({
  nome: props.proposta?.nome ?? '',
  descrizione: props.proposta?.descrizione ?? '',
  data_fine: props.proposta?.data_fine ?? '',
  numerazione_per_esercizio: true,
  copia_piano_conti: true,
  gestioni: {} as Record<number, { modalita: Modalita; riapri: boolean }>,
  conferma: false,
});

function scenarioPer(r: Residuo): ChiaveScenario {
  const g = r.gestione_id ? gestioniPerId.value[r.gestione_id] : undefined;
  if (!g) return 'mantieni_chiusa';
  const s = scelte[g.id];
  if (g.tipo === 'ordinaria') return s.modalita === 'riporta' ? 'riporta_nuova' : 'mantieni_nuova';
  if (!s.riapri) return 'mantieni_chiusa';
  return s.modalita === 'riporta' ? 'riporta_riapri' : 'mantieni_riapri';
}

const righe = computed(() => (props.residui ?? []).map((r) => ({ ...r, esito: r.scenari[scenarioPer(r)] })));
const totali = computed(() => righe.value.filter((r) => r.anagrafica_id).reduce(
  (t, r) => ({
    residuo: t.residuo + r.residuo,
    riportato: t.riportato + r.esito.riportato,
    mantenuto: t.mantenuto + r.esito.mantenuto,
    conguaglio: t.conguaglio + r.conguaglio,
  }),
  { residuo: 0, riportato: 0, mantenuto: 0, conguaglio: 0 },
));
const conDifferenze = computed(() => righe.value.filter((r) => r.esito.differenza !== 0));
const quoteDaChiudere = computed(() => righe.value.reduce((n, r) => {
  const g = r.gestione_id ? gestioniPerId.value[r.gestione_id] : undefined;
  if (!g) return n;
  const s = scelte[g.id];
  const riporta = s.modalita === 'riporta' && (g.tipo === 'ordinaria' || s.riapri);
  return n + (riporta ? r.quote_aperte : 0);
}, 0));

function nomeGestione(id: number | null): string {
  return (id && gestioniPerId.value[id]?.nome) || '—';
}

function erroreScelta(g: GestioneChiusura): string | null {
  const s = scelte[g.id];
  if (s.modalita !== 'riporta') return null;
  if (g.tipo !== 'ordinaria' && !s.riapri) return 'Una gestione che non prosegue non può riportare il residuo in un nuovo piano.';
  if (g.quote_non_emesse > 0) return `Ci sono ${g.quote_non_emesse} quote non emesse: emettile prima, oppure lascia aperte le rate.`;
  return null;
}

const scelteValide = computed(() => gestioni.value.every((g) => !erroreScelta(g)));

function impostaRiapri(g: GestioneChiusura, valore: boolean) {
  scelte[g.id].riapri = valore;
  if (!valore) scelte[g.id].modalita = 'mantieni';
}

const classeLivello: Record<Livello, string> = {
  errore: 'text-red-600 dark:text-red-400',
  avviso: 'text-amber-600 dark:text-amber-400',
  ok: 'text-emerald-600 dark:text-emerald-400',
  info: 'text-sky-600 dark:text-sky-400',
};
const iconaLivello = { errore: CircleAlert, avviso: TriangleAlert, ok: CheckCircle2, info: Info } as const;

function esegui() {
  form.gestioni = Object.fromEntries(Object.entries(scelte).map(([id, s]) => [id, { ...s }]));
  form.post(route(generateRoute('gestionale.esercizi.chiusura.store'), { condominio: props.condominio.id, esercizio: props.esercizio.id }), {
    preserveScroll: true,
  });
}

const annullando = ref(false);
function annullaChiusura() {
  annullando.value = true;
  router.post(
    route(generateRoute('gestionale.esercizi.chiusura.annulla'), { condominio: props.condominio.id, esercizio: props.esercizio.id }),
    {},
    { preserveScroll: true, onFinish: () => { annullando.value = false; } },
  );
}

const segno = (v: number) => (v > 0 ? 'text-red-700 dark:text-red-400' : v < 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-muted-foreground');
</script>

<template>
  <Head title="Passaggio d'esercizio" />

  <GestionaleLayout>
    <div class="px-6 py-8 space-y-4">
      <PageHeaderGuide
        :page-title="`Passaggio d'esercizio — ${props.esercizio.nome}`"
        page-subtitle="Chiudi l'esercizio e apri il successivo: residui riportati una volta sola, gestioni pronte, numerazione per esercizio."
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :condominio="props.condominio"
        :condomini="props.condomini"
        :backUrl="backUrl"
        backText="Esercizi"
      />

      <div v-if="flashMessage" class="py-1">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <!-- ═══ Chiusura già eseguita ═══ -->
      <section v-if="props.chiusura" class="space-y-4">
        <div class="rounded-2xl border border-emerald-200 dark:border-emerald-900 bg-emerald-50/60 dark:bg-emerald-950/30 p-5">
          <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
            <div class="space-y-1">
              <p class="flex items-center gap-2 font-semibold text-emerald-800 dark:text-emerald-300">
                <CheckCircle2 class="h-5 w-5" /> Esercizio chiuso con la procedura guidata
              </p>
              <p class="text-sm text-muted-foreground">
                Eseguita il {{ props.chiusura.eseguita_il ? new Date(props.chiusura.eseguita_il).toLocaleString('it-IT') : '—' }}
                <span v-if="props.chiusura.autore">da {{ props.chiusura.autore }}</span>.
                <template v-if="props.chiusura.esercizio_nuovo">
                  Aperto «{{ props.chiusura.esercizio_nuovo.nome }}»
                  ({{ toItalian(props.chiusura.esercizio_nuovo.data_inizio) }} – {{ toItalian(props.chiusura.esercizio_nuovo.data_fine) }})<span v-if="props.chiusura.esercizio_nuovo.serie_protocollo">, protocolli serie {{ props.chiusura.esercizio_nuovo.serie_protocollo }}</span>.
                </template>
              </p>
              <p class="text-sm text-muted-foreground">
                {{ props.chiusura.scritture }} scritture di riporto, {{ props.chiusura.quote_riportate }} quote chiuse per riporto,
                {{ props.chiusura.saldi_creati }} saldi iniziali creati.
              </p>
            </div>

            <AlertDialog v-if="props.chiusura.annullabile">
              <AlertDialogTrigger as-child>
                <Button variant="outline" class="h-9 gap-2" :disabled="annullando">
                  <LoaderCircle v-if="annullando" class="h-4 w-4 animate-spin" />
                  <Undo2 v-else class="h-4 w-4" />
                  Annulla chiusura
                </Button>
              </AlertDialogTrigger>
              <AlertDialogContent>
                <AlertDialogHeader>
                  <AlertDialogTitle>Annullare la chiusura?</AlertDialogTitle>
                  <AlertDialogDescription>
                    «{{ props.esercizio.nome }}» torna aperto; «{{ props.chiusura.esercizio_nuovo?.nome }}» viene eliminato con le sue
                    gestioni, il piano dei conti copiato, i saldi iniziali e le scritture di riporto. Le quote riportate tornano da pagare.
                  </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                  <AlertDialogCancel>Indietro</AlertDialogCancel>
                  <AlertDialogAction @click="annullaChiusura">Annulla la chiusura</AlertDialogAction>
                </AlertDialogFooter>
              </AlertDialogContent>
            </AlertDialog>
          </div>

          <div v-if="!props.chiusura.annullabile && props.chiusura.motivi_blocco.length" class="mt-3 text-sm text-muted-foreground">
            <p class="font-medium">La chiusura non si può più annullare:</p>
            <ul class="list-disc pl-5">
              <li v-for="m in props.chiusura.motivi_blocco" :key="m">{{ m }}</li>
            </ul>
          </div>
        </div>

        <div v-if="props.chiusura.totali" class="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <div class="rounded-xl border p-4"><p class="text-xs uppercase text-muted-foreground">Residuo a fine esercizio</p><p class="text-lg font-semibold tabular-nums">{{ euro(props.chiusura.totali.residuo) }}</p></div>
          <div class="rounded-xl border p-4"><p class="text-xs uppercase text-muted-foreground">Riportato nei nuovi piani</p><p class="text-lg font-semibold tabular-nums">{{ euro(props.chiusura.totali.riportato) }}</p></div>
          <div class="rounded-xl border p-4"><p class="text-xs uppercase text-muted-foreground">Resta sulle quote originarie</p><p class="text-lg font-semibold tabular-nums">{{ euro(props.chiusura.totali.mantenuto) }}</p></div>
        </div>

        <div class="overflow-x-auto rounded-2xl border bg-white dark:bg-slate-950">
          <table class="w-full text-sm">
            <thead class="bg-muted/50 text-left text-xs uppercase text-muted-foreground">
              <tr><th class="px-3 py-2">Condòmino</th><th class="px-3 py-2">Unità</th><th class="px-3 py-2">Gestione</th><th class="px-3 py-2 text-right">Residuo</th><th class="px-3 py-2 text-right">Riportato</th><th class="px-3 py-2 text-right">Sulle quote</th></tr>
            </thead>
            <tbody>
              <tr v-for="(r, i) in props.chiusura.residui" :key="i" class="border-t">
                <td class="px-3 py-2">{{ r.anagrafica }}</td><td class="px-3 py-2">{{ r.immobile }}</td><td class="px-3 py-2">{{ r.gestione }}</td>
                <td class="px-3 py-2 text-right tabular-nums" :class="segno(r.residuo)">{{ euro(r.residuo) }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ euro(r.riportato) }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ euro(r.mantenuto) }}</td>
              </tr>
              <tr v-if="!props.chiusura.residui.length"><td colspan="6" class="px-3 py-6 text-center text-muted-foreground">Nessun residuo: tutti in pari.</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- ═══ Esercizio chiuso a mano ═══ -->
      <section v-else-if="props.esercizio.stato !== 'aperto'" class="rounded-2xl border p-6 text-sm text-muted-foreground">
        <p class="flex items-center gap-2 font-medium text-foreground"><Lock class="h-4 w-4" /> Esercizio chiuso</p>
        <p class="mt-1">
          «{{ props.esercizio.nome }}» è chiuso ma non con la procedura guidata, quindi non c'è un passaggio da mostrare o annullare.
          <template v-if="props.ultima_annullata">L'ultima chiusura guidata è stata annullata.</template>
          I residui di questo esercizio, se ce ne sono, vanno caricati a mano nei saldi iniziali del successivo.
        </p>
      </section>

      <!-- ═══ Procedura ═══ -->
      <section v-else class="space-y-4">
        <ol class="grid grid-cols-2 gap-2 lg:grid-cols-4">
          <li v-for="(p, i) in passi" :key="p">
            <button type="button" class="w-full rounded-xl border px-3 py-2 text-left text-sm transition"
              :class="i === passo ? 'border-primary bg-primary/5 font-semibold' : 'hover:bg-muted/50'"
              @click="passo = i">
              <span class="mr-2 inline-flex h-5 w-5 items-center justify-center rounded-full text-xs"
                :class="i < passo ? 'bg-emerald-600 text-white' : i === passo ? 'bg-primary text-primary-foreground' : 'bg-muted'">{{ i + 1 }}</span>
              {{ p }}
            </button>
          </li>
        </ol>

        <!-- Passo 1: controlli -->
        <div v-if="passo === 0" class="space-y-2 rounded-2xl border bg-white dark:bg-slate-950 p-4">
          <div v-for="c in controlli" :key="c.codice" class="flex items-start gap-3 rounded-lg px-2 py-2">
            <component :is="iconaLivello[c.livello]" class="mt-0.5 h-5 w-5 shrink-0" :class="classeLivello[c.livello]" />
            <div>
              <p class="font-medium">{{ c.titolo }}</p>
              <p class="text-sm text-muted-foreground">{{ c.dettaglio }}</p>
            </div>
          </div>
          <p v-if="errori.length" class="rounded-lg bg-red-50 dark:bg-red-950/40 px-3 py-2 text-sm text-red-700 dark:text-red-300">
            Ci sono {{ errori.length }} problemi bloccanti: puoi consultare i passi successivi, ma la chiusura si esegue solo dopo averli risolti.
          </p>
        </div>

        <!-- Passo 2: saldi per condòmino -->
        <div v-else-if="passo === 1" class="space-y-3">
          <p class="text-sm text-muted-foreground">
            Il <strong>residuo</strong> è il saldo con cui si chiude l'estratto conto dell'esercizio (positivo = debito, negativo = credito).
            «Riportato» diventa saldo iniziale libero del nuovo esercizio, che il nuovo piano rate assorbe; «sulle quote» resta sulle rate
            originarie, dove si incassa o si compensa. Le colonne cambiano con le scelte del passo 3.
          </p>
          <div class="overflow-x-auto rounded-2xl border bg-white dark:bg-slate-950">
            <table class="w-full text-sm">
              <thead class="bg-muted/50 text-left text-xs uppercase text-muted-foreground">
                <tr>
                  <th class="px-3 py-2">Condòmino</th><th class="px-3 py-2">Unità</th><th class="px-3 py-2">Gestione</th>
                  <th class="px-3 py-2 text-right">Saldo iniziale</th><th class="px-3 py-2 text-right">Residuo</th>
                  <th class="px-3 py-2 text-right">di cui conguaglio</th><th class="px-3 py-2 text-right">Riportato</th>
                  <th class="px-3 py-2 text-right">Sulle quote</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in righe" :key="r.chiave" class="border-t">
                  <td class="px-3 py-2">{{ r.anagrafica }}</td>
                  <td class="px-3 py-2">{{ r.immobile }}</td>
                  <td class="px-3 py-2">{{ nomeGestione(r.gestione_id) }}</td>
                  <td class="px-3 py-2 text-right tabular-nums">{{ euro(r.saldo_iniziale) }}</td>
                  <td class="px-3 py-2 text-right font-medium tabular-nums" :class="segno(r.residuo)">{{ euro(r.residuo) }}</td>
                  <td class="px-3 py-2 text-right tabular-nums">{{ r.conguaglio ? euro(r.conguaglio) : '—' }}</td>
                  <td class="px-3 py-2 text-right tabular-nums">{{ euro(r.esito.riportato) }}</td>
                  <td class="px-3 py-2 text-right tabular-nums">
                    {{ euro(r.esito.mantenuto) }}
                    <span v-if="r.esito.differenza" class="ml-1 text-amber-600" :title="`Quote e giornale differiscono di ${euro(r.esito.differenza)}`">⚠</span>
                  </td>
                </tr>
                <tr v-if="!righe.length"><td colspan="8" class="px-3 py-6 text-center text-muted-foreground">Nessun residuo: tutti i condòmini sono in pari.</td></tr>
              </tbody>
              <tfoot v-if="righe.length" class="border-t bg-muted/30 font-semibold">
                <tr>
                  <td class="px-3 py-2" colspan="4">Totale condòmini</td>
                  <td class="px-3 py-2 text-right tabular-nums">{{ euro(totali.residuo) }}</td>
                  <td class="px-3 py-2 text-right tabular-nums">{{ totali.conguaglio ? euro(totali.conguaglio) : '—' }}</td>
                  <td class="px-3 py-2 text-right tabular-nums">{{ euro(totali.riportato) }}</td>
                  <td class="px-3 py-2 text-right tabular-nums">{{ euro(totali.mantenuto) }}</td>
                </tr>
              </tfoot>
            </table>
          </div>
          <p v-if="conDifferenze.length" class="text-sm text-amber-700 dark:text-amber-400">
            ⚠ Per {{ conDifferenze.length }} righe le quote e il giornale non raccontano lo stesso importo: il saldo iniziale segue il
            giornale (come l'estratto conto), ma conviene verificare quelle posizioni prima di chiudere.
          </p>
        </div>

        <!-- Passo 3: gestioni e nuovo esercizio -->
        <div v-else-if="passo === 2" class="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <div class="space-y-4 rounded-2xl border bg-white dark:bg-slate-950 p-4">
            <p class="flex items-center gap-2 font-semibold"><CalendarRange class="h-4 w-4" /> Nuovo esercizio</p>
            <div>
              <Label for="nome">Nome</Label>
              <Input id="nome" v-model="form.nome" class="mt-1" @focus="form.clearErrors('nome')" />
              <InputError :message="form.errors.nome" />
            </div>
            <div>
              <Label for="descrizione">Descrizione</Label>
              <Input id="descrizione" v-model="form.descrizione" class="mt-1" />
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <Label>Inizio</Label>
                <Input :model-value="props.proposta ? toItalian(props.proposta.data_inizio) : ''" class="mt-1" disabled />
                <p class="mt-1 text-xs text-muted-foreground">Il giorno dopo la fine di questo: fra i due non resta un giorno scoperto.</p>
              </div>
              <div>
                <Label for="data_fine">Fine</Label>
                <Input id="data_fine" v-model="form.data_fine" type="date" class="mt-1" @focus="form.clearErrors('data_fine')" />
                <InputError :message="form.errors.data_fine" />
              </div>
            </div>
            <label class="flex items-start gap-3 text-sm">
              <Checkbox v-model="form.numerazione_per_esercizio" class="mt-0.5" />
              <span>
                <span class="font-medium">Protocolli numerati per esercizio</span> (serie {{ props.proposta?.serie }}: es. INC-{{ props.proposta?.serie }}-00001).
                <span class="block text-muted-foreground">I protocolli degli esercizi precedenti non cambiano; senza la spunta si continua sull'anno solare.</span>
              </span>
            </label>
            <label class="flex items-start gap-3 text-sm">
              <Checkbox v-model="form.copia_piano_conti" class="mt-0.5" />
              <span>
                <span class="font-medium">Copia il piano dei conti dell'ordinaria</span>
                <span class="block text-muted-foreground">Capitoli, voci, tabelle millesimali e importi: la base del nuovo preventivo, da aggiornare.</span>
              </span>
            </label>
          </div>

          <div class="space-y-3 rounded-2xl border bg-white dark:bg-slate-950 p-4">
            <p class="font-semibold">Gestioni</p>
            <div v-for="g in gestioni" :key="g.id" class="space-y-2 rounded-xl border p-3">
              <div class="flex items-center justify-between gap-2">
                <p class="font-medium">{{ g.nome }}</p>
                <Badge variant="outline">{{ g.tipo === 'ordinaria' ? 'Ordinaria' : 'Straordinaria' }}</Badge>
              </div>

              <p v-if="g.tipo === 'ordinaria'" class="text-sm text-muted-foreground">
                Si riapre sempre: nasce la nuova gestione ordinaria del nuovo esercizio.
              </p>
              <label v-else class="flex items-center gap-3 text-sm">
                <Switch :model-value="scelte[g.id].riapri" @update:model-value="(v: boolean) => impostaRiapri(g, v)" />
                Prosegue nel nuovo esercizio (lavori in corso, con il suo fondo e le sue rate)
              </label>

              <div v-if="g.tipo === 'ordinaria' || scelte[g.id].riapri" class="flex flex-wrap gap-2">
                <Button type="button" size="sm" :variant="scelte[g.id].modalita === 'riporta' ? 'default' : 'outline'" class="h-8"
                  @click="scelte[g.id].modalita = 'riporta'">
                  Riporta nel nuovo piano
                </Button>
                <Button type="button" size="sm" :variant="scelte[g.id].modalita === 'mantieni' ? 'default' : 'outline'" class="h-8"
                  @click="scelte[g.id].modalita = 'mantieni'">
                  Lascia aperte le vecchie rate
                </Button>
              </div>
              <p class="text-xs text-muted-foreground">
                <template v-if="scelte[g.id].modalita === 'riporta'">
                  Le quote non pagate si chiudono «per riporto» e il loro importo diventa saldo iniziale libero: lo chiede il nuovo piano (Rata 0).
                </template>
                <template v-else>
                  Le quote non pagate restano da incassare dove sono; i crediti restano compensabili. Nel nuovo esercizio compaiono come saldo iniziale bloccato.
                </template>
              </p>
              <p v-if="erroreScelta(g)" class="text-xs text-red-600">{{ erroreScelta(g) }}</p>
              <p v-if="g.piani_in_bozza" class="text-xs text-amber-600">{{ g.piani_in_bozza }} piani rate ancora in bozza.</p>
            </div>
          </div>
        </div>

        <!-- Passo 4: conferma -->
        <div v-else class="space-y-4 rounded-2xl border bg-white dark:bg-slate-950 p-4">
          <ul class="space-y-1 text-sm">
            <li>Si chiude <strong>«{{ props.esercizio.nome }}»</strong> e si apre <strong>«{{ form.nome }}»</strong>
              dal {{ props.proposta ? toItalian(props.proposta.data_inizio) : '' }} al {{ toItalian(form.data_fine) }}.</li>
            <li>Residuo dei condòmini: <strong>{{ euro(totali.residuo) }}</strong>, di cui {{ euro(totali.riportato) }} riportati nei nuovi piani
              e {{ euro(totali.mantenuto) }} che restano sulle quote originarie.</li>
            <li>{{ quoteDaChiudere }} quote vengono chiuse per riporto con una scrittura nel nuovo esercizio (protocollo CHI).</li>
            <li v-if="form.numerazione_per_esercizio">Protocolli del nuovo esercizio nella serie {{ props.proposta?.serie }}.</li>
            <li>Dopo la chiusura il vecchio esercizio non si modifica: fatture e pagamenti tardivi si registrano nel nuovo, con la loro data.</li>
          </ul>

          <div v-if="errori.length" class="rounded-lg bg-red-50 dark:bg-red-950/40 px-3 py-2 text-sm text-red-700 dark:text-red-300">
            Risolvi prima i problemi bloccanti del passo 1: {{ errori.map((e) => e.titolo).join('; ') }}.
          </div>

          <label class="flex items-start gap-3 text-sm">
            <Checkbox v-model="form.conferma" class="mt-0.5" />
            <span>Ho controllato i saldi per condòmino e le scelte sulle gestioni.</span>
          </label>
          <InputError :message="form.errors.conferma" />
          <InputError v-for="(m, k) in form.errors" :key="k" :message="['nome', 'data_fine', 'conferma'].includes(String(k)) ? undefined : m" />

          <Button :disabled="form.processing || !form.conferma || errori.length > 0 || !scelteValide" class="h-9 gap-2" @click="esegui">
            <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
            <RotateCcw v-else class="h-4 w-4" />
            Chiudi esercizio e apri il successivo
          </Button>
        </div>

        <div class="flex justify-between">
          <Button variant="outline" class="h-8 gap-2" :disabled="passo === 0" @click="passo--"><ArrowLeft class="h-4 w-4" /> Indietro</Button>
          <Button v-if="passo < passi.length - 1" class="h-8 gap-2" @click="passo++">Avanti <ArrowRight class="h-4 w-4" /></Button>
          <Link v-else :href="backUrl" class="text-sm text-muted-foreground underline-offset-4 hover:underline">Torna agli esercizi</Link>
        </div>
      </section>
    </div>
  </GestionaleLayout>
</template>
