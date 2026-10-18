<script setup lang="ts">
// Scheda «Verbale»: discussione e dispositivo per punto (con bozza IA o modello), dichiarazioni a verbale
// testuali, registrazione nel registro dei verbali, comunicazione agli assenti con il termine dell'art. 1137.
import { computed, reactive, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import BozzaTesto from '@/components/gestionale/assemblee/BozzaTesto.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import InputError from '@/components/InputError.vue';
import { BookCheck, FileText, Printer, Save, Trash2, Plus } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import { classeEsito, data, etichettaEsito } from '@/lib/gestionale/assemblee';
import type { Assemblea, Assente, Bozza, Dichiarazione, OpzioniAssemblea, Persona, Punto } from '@/types/gestionale/assemblee';

const props = defineProps<{
  condominioId: number;
  assemblea: Assemblea;
  punti: Punto[];
  persone: Persona[];
  dichiarazioni: Dichiarazione[];
  assenti: Assente[];
  scadenzaPresenti: string | null;
  opzioni: OpzioniAssemblea;
  iaDisponibile: boolean;
}>();

const { generateRoute } = usePermission();
const page = usePage();
const errori = computed(() => (page.props.errors ?? {}) as Record<string, string>);
const base = () => ({ condominio: props.condominioId, assemblea: props.assemblea.id });
const modificabile = computed(() => props.assemblea.presenze_modificabili);
const selectClass = 'mt-1 h-9 w-full rounded-md border border-input bg-background px-3 text-sm';

// --- Testi per punto ---
const testi = reactive<Record<number, { discussione: string; testo_delibera: string }>>({});
const caricaTesti = () => {
  Object.keys(testi).forEach((k) => delete testi[Number(k)]);
  props.punti.forEach((p) => { testi[p.id] = { discussione: p.discussione ?? '', testo_delibera: p.testo_delibera ?? '' }; });
};
caricaTesti();
watch(() => props.punti, caricaTesti, { deep: true });

const salvaTesti = (p: Punto) => {
  router.put(route(...generateRoute('gestionale.assemblee.punti.testi', { ...base(), punto: p.id })), testi[p.id], { preserveScroll: true });
};

const applicaBozza = (bozza: Bozza) => {
  bozza.punti.forEach((bp) => {
    if (testi[bp.id]) {
      testi[bp.id].discussione = bp.discussione ?? testi[bp.id].discussione;
      testi[bp.id].testo_delibera = bp.testo_delibera ?? testi[bp.id].testo_delibera;
    }
  });
};

// --- Dichiarazioni a verbale ---
const nuovaDichiarazione = useForm({ assemblea_punto_id: null as number | null, anagrafica_id: null as number | null, dichiarante: '', testo: '' });
watch(() => nuovaDichiarazione.anagrafica_id, (id) => {
  const p = props.persone.find((x) => x.anagrafica_id === id);
  if (p) nuovaDichiarazione.dichiarante = p.nome;
});
const aggiungiDichiarazione = () => {
  nuovaDichiarazione.post(route(...generateRoute('gestionale.assemblee.dichiarazioni.store', base())), {
    preserveScroll: true,
    onSuccess: () => nuovaDichiarazione.reset(),
  });
};
const togliDichiarazione = (d: Dichiarazione) => {
  if (!confirm('Togliere la dichiarazione?')) return;
  router.delete(route(...generateRoute('gestionale.assemblee.dichiarazioni.destroy', { ...base(), dichiarazione: d.id })), { preserveScroll: true });
};
const titoloPunto = (id: number | null) => props.punti.find((p) => p.id === id)?.titolo ?? 'Generale';

// --- Registrazione ---
const registrazione = useForm({ file: null as File | null, pubblica: false });
const registra = () => {
  if (!confirm('Registrare il verbale? Riceverà il numero di registro e l\'assemblea non sarà più modificabile.')) return;
  registrazione.post(route(...generateRoute('gestionale.assemblee.verbale.registra', base())), { preserveScroll: true, forceFormData: true });
};
const scegliFile = (e: Event) => {
  registrazione.file = (e.target as HTMLInputElement).files?.[0] ?? null;
};

// --- Comunicazione agli assenti ---
const comunicazioni = reactive<Record<number, { verbale_mezzo: string; verbale_data_invio: string; verbale_data_ricezione: string; verbale_riferimento: string }>>({});
const caricaAssenti = () => {
  Object.keys(comunicazioni).forEach((k) => delete comunicazioni[Number(k)]);
  props.assenti.forEach((a) => {
    comunicazioni[a.anagrafica_id] = {
      verbale_mezzo: a.verbale_mezzo ?? 'raccomandata',
      verbale_data_invio: a.verbale_data_invio ?? '',
      verbale_data_ricezione: a.verbale_data_ricezione ?? '',
      verbale_riferimento: a.verbale_riferimento ?? '',
    };
  });
};
caricaAssenti();
watch(() => props.assenti, caricaAssenti, { deep: true });

const salvaComunicazione = (a: Assente) => {
  router.put(route(...generateRoute('gestionale.assemblee.comunicazione-verbale', base())), { anagrafica_id: a.anagrafica_id, ...comunicazioni[a.anagrafica_id] }, { preserveScroll: true });
};
const comunicabile = computed(() => props.assemblea.stato === 'svolta' || props.assemblea.stato === 'verbalizzata');
</script>

<template>
  <div class="space-y-8">
    <div v-if="errori.stato" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">{{ errori.stato }}</div>

    <section class="space-y-4">
      <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 class="font-semibold">Testo del verbale</h2>
          <p class="text-xs text-muted-foreground">Presenti, quorum, voti ed esiti li compone il programma. Qui si scrivono la discussione e il dispositivo di ogni punto.</p>
        </div>
        <div class="flex gap-2">

          <Button as="a" size="sm" variant="outline" target="_blank" :href="route(...generateRoute('gestionale.assemblee.stampa', { ...base(), documento: 'verbale' }))">
            <Printer class="h-4 w-4" /> Verbale PDF
          </Button>
        </div>
      </div>

      <BozzaTesto
        v-if="modificabile && punti.length"
        :condominio-id="condominioId"
        :assemblea-id="assemblea.id"
        tipo="verbale"
        :ia-disponibile="iaDisponibile"
        @bozza="applicaBozza"
      />
      <p class="text-xs text-muted-foreground">Le dichiarazioni a verbale non passano mai dalla bozza: restano come rese.</p>

      <div v-for="(p, i) in punti" :key="p.id" class="rounded-xl border p-4 space-y-3">
        <div class="flex items-start justify-between gap-2">
          <div class="font-medium">Punto {{ i + 1 }} — {{ p.titolo }}</div>
          <Badge variant="outline" :class="classeEsito[p.votazione.esito.esito]">{{ etichettaEsito[p.votazione.esito.esito] }}</Badge>
        </div>
        <template v-if="testi[p.id]">
          <div>
            <Label :for="`disc-${p.id}`">Discussione (note di seduta)</Label>
            <Textarea :id="`disc-${p.id}`" v-model="testi[p.id].discussione" class="mt-1" rows="3" :disabled="!modificabile" />
          </div>
          <div>
            <Label :for="`delib-${p.id}`">Testo della delibera</Label>
            <Textarea :id="`delib-${p.id}`" v-model="testi[p.id].testo_delibera" class="mt-1" rows="3" :disabled="!modificabile" />
          </div>
        </template>
        <div v-if="modificabile" class="flex justify-end">
          <Button size="sm" variant="outline" @click="salvaTesti(p)"><Save class="h-4 w-4" /> Salva testo</Button>
        </div>
      </div>
    </section>

    <section class="space-y-3">
      <h2 class="font-semibold">Dichiarazioni a verbale</h2>
      <p class="text-xs text-muted-foreground">Si riportano testualmente, come rese dal condòmino che lo chiede (art. 1130 n. 7 c.c.).</p>
      <div v-for="d in dichiarazioni" :key="d.id" class="flex items-start justify-between gap-2 rounded-lg border px-3 py-2 text-sm">
        <div><span class="font-medium">{{ d.dichiarante }}</span> <span class="text-xs text-muted-foreground">({{ titoloPunto(d.assemblea_punto_id) }})</span>: «{{ d.testo }}»</div>
        <Button v-if="modificabile" size="icon-sm" variant="ghost" @click="togliDichiarazione(d)"><Trash2 class="h-4 w-4 text-red-600" /></Button>
      </div>
      <form v-if="modificabile" class="rounded-xl border border-dashed p-4 space-y-3" @submit.prevent="aggiungiDichiarazione">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-6">
          <div class="sm:col-span-2">
            <Label for="dich-chi">Condòmino</Label>
            <select id="dich-chi" v-model="nuovaDichiarazione.anagrafica_id" :class="selectClass">
              <option :value="null">— altro —</option>
              <option v-for="p in persone" :key="p.anagrafica_id" :value="p.anagrafica_id">{{ p.nome }}</option>
            </select>
          </div>
          <div class="sm:col-span-2">
            <Label for="dich-nome">Dichiarante</Label>
            <Input id="dich-nome" v-model="nuovaDichiarazione.dichiarante" class="mt-1" />
            <InputError :message="nuovaDichiarazione.errors.dichiarante" />
          </div>
          <div class="sm:col-span-2">
            <Label for="dich-punto">Punto</Label>
            <select id="dich-punto" v-model="nuovaDichiarazione.assemblea_punto_id" :class="selectClass">
              <option :value="null">Generale</option>
              <option v-for="p in punti" :key="p.id" :value="p.id">{{ p.titolo }}</option>
            </select>
          </div>
          <div class="sm:col-span-6">
            <Label for="dich-testo">Testo letterale</Label>
            <Textarea id="dich-testo" v-model="nuovaDichiarazione.testo" class="mt-1" rows="2" />
            <InputError :message="nuovaDichiarazione.errors.testo" />
          </div>
        </div>
        <div class="flex justify-end"><Button size="sm" :disabled="nuovaDichiarazione.processing"><Plus class="h-4 w-4" /> Aggiungi dichiarazione</Button></div>
      </form>
    </section>

    <section class="space-y-3 rounded-xl border p-4">
      <h2 class="font-semibold flex items-center gap-2"><BookCheck class="h-4 w-4" /> Registro dei verbali</h2>
      <div v-if="assemblea.numero_registro" class="text-sm space-y-1">
        <div>Verbale <strong>n. {{ assemblea.numero_registro }}</strong>, registrato il {{ data(assemblea.verbale_registrato_at) }}.</div>
        <div v-if="assemblea.documento_verbale" class="flex items-center gap-2">
          <FileText class="h-4 w-4" />
          <a class="underline" :href="route(...generateRoute('documenti.download', { documento: assemblea.documento_verbale.id }))">{{ assemblea.documento_verbale.name }}</a>
          <span class="text-xs text-muted-foreground">({{ assemblea.documento_verbale.is_published ? 'pubblicato ai condòmini' : 'non pubblicato' }})</span>
        </div>
      </div>
      <form v-else-if="assemblea.stato === 'svolta'" class="space-y-3" @submit.prevent="registra">
        <p class="text-sm text-muted-foreground">
          Assegna il numero progressivo e archivia il PDF in Documenti → Verbali. Se carichi la copia firmata viene archiviata quella,
          altrimenti il verbale generato.
        </p>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-6">
          <div class="sm:col-span-3">
            <Label for="verbale-file">Verbale firmato (PDF, facoltativo)</Label>
            <input id="verbale-file" type="file" accept="application/pdf" class="mt-1 block w-full text-sm" @change="scegliFile" />
            <InputError :message="registrazione.errors.file" />
          </div>
          <div class="sm:col-span-3 flex items-end">
            <label class="flex items-center gap-2 text-sm">
              <input v-model="registrazione.pubblica" type="checkbox" class="h-4 w-4" /> Pubblica nell'archivio dei condòmini
            </label>
          </div>
        </div>
        <div class="flex justify-end"><Button size="sm" :disabled="registrazione.processing"><BookCheck class="h-4 w-4" /> Registra il verbale</Button></div>
      </form>
      <p v-else class="text-sm text-muted-foreground">Il verbale si registra quando l'assemblea è «svolta».</p>
    </section>

    <section class="space-y-3">
      <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 class="font-semibold">Comunicazione del verbale agli assenti</h2>
          <p class="text-xs text-muted-foreground">
            Per gli assenti i 30 giorni per impugnare (art. 1137 c.c.) decorrono dalla ricezione del verbale; per dissenzienti e astenuti
            dalla delibera<span v-if="scadenzaPresenti"> (termine indicativo {{ data(scadenzaPresenti) }})</span>. Termini indicativi:
            sospensione feriale di agosto considerata, festività no.
          </p>
        </div>
        <Button as="a" size="sm" variant="outline" target="_blank" :href="route(...generateRoute('gestionale.assemblee.stampa', { ...base(), documento: 'assenti' }))">
          <Printer class="h-4 w-4" /> Lettera agli assenti
        </Button>
      </div>
      <div class="overflow-x-auto rounded-xl border">
        <table class="w-full text-sm">
          <thead class="bg-muted/50 text-left text-xs uppercase text-muted-foreground">
            <tr>
              <th class="px-3 py-2">Assente</th>
              <th class="px-3 py-2">Mezzo</th>
              <th class="px-3 py-2">Inviato il</th>
              <th class="px-3 py-2">Ricevuto il</th>
              <th class="px-3 py-2">Riferimento</th>
              <th class="px-3 py-2">Impugnabile fino al</th>
              <th class="px-3 py-2"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="assenti.length === 0"><td colspan="7" class="px-3 py-4 text-center text-muted-foreground">Nessun assente.</td></tr>
            <tr v-for="a in assenti" :key="a.anagrafica_id" class="border-t">
              <template v-if="comunicazioni[a.anagrafica_id]">
                <td class="px-3 py-2"><div class="font-medium">{{ a.nome }}</div><div class="text-xs text-muted-foreground">{{ a.unita.join(', ') }}</div></td>
                <td class="px-3 py-2 min-w-40">
                  <select v-model="comunicazioni[a.anagrafica_id].verbale_mezzo" class="h-8 w-full rounded-md border border-input bg-background px-2 text-sm" :disabled="!comunicabile">
                    <option v-for="m in opzioni.mezzi" :key="m.value" :value="m.value">{{ m.label }}</option>
                  </select>
                </td>
                <td class="px-3 py-2"><Input v-model="comunicazioni[a.anagrafica_id].verbale_data_invio" type="date" class="h-8" :disabled="!comunicabile" /></td>
                <td class="px-3 py-2"><Input v-model="comunicazioni[a.anagrafica_id].verbale_data_ricezione" type="date" class="h-8" :disabled="!comunicabile" /></td>
                <td class="px-3 py-2"><Input v-model="comunicazioni[a.anagrafica_id].verbale_riferimento" class="h-8" :disabled="!comunicabile" /></td>
                <td class="px-3 py-2">{{ a.scadenza_impugnazione ? data(a.scadenza_impugnazione) : 'da ricezione' }}</td>
                <td class="px-3 py-2"><Button v-if="comunicabile" size="icon-sm" variant="ghost" @click="salvaComunicazione(a)"><Save class="h-4 w-4" /></Button></td>
              </template>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>
