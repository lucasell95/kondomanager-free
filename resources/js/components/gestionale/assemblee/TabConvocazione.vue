<script setup lang="ts">
// Scheda «Convocazione»: destinatari con mezzo e data di ricezione, controllo dei termini, avviso PDF.
import { reactive, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import ElencoControlli from '@/components/gestionale/assemblee/ElencoControlli.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import { Printer, Save, Trash2, UsersRound, Plus } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import { classeLivello } from '@/lib/gestionale/assemblee';
import type { Assemblea, Controllo, Destinatario, OpzioniAssemblea } from '@/types/gestionale/assemblee';

const props = defineProps<{
  condominioId: number;
  assemblea: Assemblea;
  destinatari: Destinatario[];
  controlli: Controllo[];
  opzioni: OpzioniAssemblea;
}>();

const { generateRoute } = usePermission();
const base = () => ({ condominio: props.condominioId, assemblea: props.assemblea.id });
const selectClass = 'h-8 w-full rounded-md border border-input bg-background px-2 text-sm';

type RigaForm = {
  anagrafica_id: number | null;
  nominativo: string;
  ruolo: string;
  mezzo: string;
  recapito: string;
  data_invio: string;
  data_ricezione: string;
  riferimento: string;
  note: string;
};

const righe = reactive<Record<number, RigaForm>>({});
const carica = () => {
  Object.keys(righe).forEach((k) => delete righe[Number(k)]);
  props.destinatari.forEach((d) => {
    righe[d.id] = {
      anagrafica_id: d.anagrafica_id,
      nominativo: d.nominativo,
      ruolo: d.ruolo ?? '',
      mezzo: d.mezzo,
      recapito: d.recapito ?? '',
      data_invio: d.data_invio ?? '',
      data_ricezione: d.data_ricezione ?? '',
      riferimento: d.riferimento ?? '',
      note: d.note ?? '',
    };
  });
};
carica();
watch(() => props.destinatari, carica, { deep: true });

const genera = () => {
  router.post(route(...generateRoute('gestionale.assemblee.destinatari.genera', base())), {}, { preserveScroll: true });
};

const salva = (d: Destinatario) => {
  router.put(route(...generateRoute('gestionale.assemblee.destinatari.update', { ...base(), destinatario: d.id })), righe[d.id], { preserveScroll: true });
};

const togli = (d: Destinatario) => {
  if (!confirm(`Togliere ${d.nominativo} dai destinatari?`)) return;
  router.delete(route(...generateRoute('gestionale.assemblee.destinatari.destroy', { ...base(), destinatario: d.id })), { preserveScroll: true });
};

const nuovo = useForm({ anagrafica_id: null as number | null, nominativo: '', ruolo: '', mezzo: 'raccomandata', recapito: '', data_invio: '', data_ricezione: '', riferimento: '', note: '' });
const aggiungi = () => {
  nuovo.post(route(...generateRoute('gestionale.assemblee.destinatari.store', base())), {
    preserveScroll: true,
    onSuccess: () => nuovo.reset(),
  });
};

const mezzoNonValido = (valore: string) => props.opzioni.mezzi.find((m) => m.value === valore)?.valido === false;
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h2 class="font-semibold">Convocazione</h2>
        <p class="text-xs text-muted-foreground">
          L'avviso va <strong>ricevuto</strong> almeno 5 giorni prima della prima convocazione, con raccomandata, PEC, fax o consegna a mano
          (art. 66 c. 3 disp. att. c.c.). L'e-mail ordinaria non è valida.
        </p>
      </div>
      <div class="flex gap-2">
        <Button v-if="assemblea.dati_modificabili" size="sm" variant="outline" @click="genera"><UsersRound class="h-4 w-4" /> Aggiungi i titolari</Button>
        <Button as="a" size="sm" variant="outline" target="_blank" :href="route(...generateRoute('gestionale.assemblee.stampa', { ...base(), documento: 'avviso' }))">
          <Printer class="h-4 w-4" /> Avviso PDF
        </Button>
      </div>
    </div>

    <ElencoControlli :controlli="controlli" />

    <div class="overflow-x-auto rounded-xl border">
      <table class="w-full text-sm">
        <thead class="bg-muted/50 text-left text-xs uppercase text-muted-foreground">
          <tr>
            <th class="px-3 py-2">Destinatario</th>
            <th class="px-3 py-2">Mezzo</th>
            <th class="px-3 py-2">Recapito</th>
            <th class="px-3 py-2">Inviato il</th>
            <th class="px-3 py-2">Ricevuto il</th>
            <th class="px-3 py-2">Riferimento</th>
            <th class="px-3 py-2">Termine</th>
            <th class="px-3 py-2"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="destinatari.length === 0">
            <td colspan="8" class="px-3 py-6 text-center text-muted-foreground">Nessun destinatario: usa «Aggiungi i titolari».</td>
          </tr>
          <tr v-for="d in destinatari" :key="d.id" class="border-t align-top">
            <template v-if="righe[d.id]">
              <td class="px-3 py-2">
                <div class="font-medium">{{ d.nominativo }}</div>
                <div class="text-xs text-muted-foreground">{{ d.ruolo }}</div>
              </td>
              <td class="px-3 py-2 min-w-40">
                <select v-model="righe[d.id].mezzo" :class="selectClass" :disabled="!assemblea.dati_modificabili">
                  <option v-for="m in opzioni.mezzi" :key="m.value" :value="m.value">{{ m.label }}</option>
                </select>
                <div v-if="mezzoNonValido(righe[d.id].mezzo)" class="mt-1 text-xs text-red-600">Non valido come convocazione</div>
              </td>
              <td class="px-3 py-2 min-w-48"><Input v-model="righe[d.id].recapito" class="h-8" :disabled="!assemblea.dati_modificabili" /></td>
              <td class="px-3 py-2"><Input v-model="righe[d.id].data_invio" type="date" class="h-8" :disabled="!assemblea.dati_modificabili" /></td>
              <td class="px-3 py-2"><Input v-model="righe[d.id].data_ricezione" type="date" class="h-8" :disabled="!assemblea.dati_modificabili" /></td>
              <td class="px-3 py-2 min-w-36"><Input v-model="righe[d.id].riferimento" class="h-8" placeholder="N. raccomandata, ricevuta PEC…" :disabled="!assemblea.dati_modificabili" /></td>
              <td class="px-3 py-2 min-w-56">
                <div class="rounded-md border px-2 py-1 text-xs" :class="classeLivello[d.stato.livello]">{{ d.stato.messaggio }}</div>
              </td>
              <td class="px-3 py-2 whitespace-nowrap">
                <template v-if="assemblea.dati_modificabili">
                  <Button size="icon-sm" variant="ghost" title="Salva" @click="salva(d)"><Save class="h-4 w-4" /></Button>
                  <Button size="icon-sm" variant="ghost" title="Togli" @click="togli(d)"><Trash2 class="h-4 w-4 text-red-600" /></Button>
                </template>
              </td>
            </template>
          </tr>
        </tbody>
      </table>
    </div>

    <form v-if="assemblea.dati_modificabili" class="rounded-xl border border-dashed p-4 space-y-3" @submit.prevent="aggiungi">
      <div class="font-medium">Altro destinatario</div>
      <div class="grid grid-cols-1 gap-3 sm:grid-cols-6">
        <div class="sm:col-span-2">
          <Label for="dest-nome">Nominativo</Label>
          <Input id="dest-nome" v-model="nuovo.nominativo" class="mt-1" />
          <InputError :message="nuovo.errors.nominativo" />
        </div>
        <div class="sm:col-span-2">
          <Label for="dest-mezzo">Mezzo</Label>
          <select id="dest-mezzo" v-model="nuovo.mezzo" :class="selectClass + ' mt-1 h-9'">
            <option v-for="m in opzioni.mezzi" :key="m.value" :value="m.value">{{ m.label }}</option>
          </select>
        </div>
        <div class="sm:col-span-2">
          <Label for="dest-recapito">Recapito</Label>
          <Input id="dest-recapito" v-model="nuovo.recapito" class="mt-1" />
        </div>
      </div>
      <div class="flex justify-end">
        <Button size="sm" :disabled="nuovo.processing"><Plus class="h-4 w-4" /> Aggiungi</Button>
      </div>
    </form>
  </div>
</template>
