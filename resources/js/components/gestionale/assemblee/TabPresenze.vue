<script setup lang="ts">
// Scheda «Presenze e deleghe»: svolgimento, presenze per persona, deleghe con i blocchi dell'art. 67,
// quorum costitutivo calcolato in prima e seconda convocazione.
import { computed, reactive, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import EsitoQuorum from '@/components/gestionale/assemblee/EsitoQuorum.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Printer, RefreshCw, Save } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import type { Assemblea, EsitoCostitutivo, OpzioniAssemblea, Persona } from '@/types/gestionale/assemblee';

const props = defineProps<{
  condominioId: number;
  assemblea: Assemblea;
  persone: Persona[];
  costitutivo: { prima: EsitoCostitutivo; seconda: EsitoCostitutivo | null };
  unita: { id: number; etichetta: string; valore_testo: string }[];
  totaleMillesimi: string;
  opzioni: OpzioniAssemblea;
}>();

const { generateRoute } = usePermission();
const page = usePage();
const errori = computed(() => (page.props.errors ?? {}) as Record<string, string>);
const base = () => ({ condominio: props.condominioId, assemblea: props.assemblea.id });
const selectClass = 'h-8 w-full rounded-md border border-input bg-background px-2 text-sm';
const modificabile = computed(() => props.assemblea.presenze_modificabili);

const svolgimento = useForm({
  convocazione_tenuta: (props.assemblea.convocazione_tenuta ?? props.assemblea.convocazione_effettiva) as string,
  prima_deserta: props.assemblea.prima_deserta,
  presidente: props.assemblea.presidente ?? '',
  segretario: props.assemblea.segretario ?? '',
  ora_apertura: props.assemblea.ora_apertura ?? '',
  ora_chiusura: props.assemblea.ora_chiusura ?? '',
});

const salvaSvolgimento = () => {
  svolgimento.put(route(...generateRoute('gestionale.assemblee.svolgimento', base())), { preserveScroll: true });
};

type RigaPresenza = {
  modo: string;
  delegato_anagrafica_id: number | null;
  delegato_nome: string;
  delega_scritta: boolean;
  ora_ingresso: string;
  ora_uscita: string;
};

const righe = reactive<Record<number, RigaPresenza>>({});
const carica = () => {
  Object.keys(righe).forEach((k) => delete righe[Number(k)]);
  props.persone.forEach((p) => {
    righe[p.anagrafica_id] = {
      modo: p.presenza?.modo ?? 'assente',
      delegato_anagrafica_id: p.presenza?.delegato_anagrafica_id ?? null,
      delegato_nome: p.presenza?.delegato_nome ?? '',
      delega_scritta: p.presenza?.delega_scritta ?? true,
      ora_ingresso: p.presenza?.ora_ingresso ?? '',
      ora_uscita: p.presenza?.ora_uscita ?? '',
    };
  });
};
carica();
watch(() => props.persone, carica, { deep: true });

const salvaPresenza = (p: Persona) => {
  router.put(
    route(...generateRoute('gestionale.assemblee.presenze.update', base())),
    { anagrafica_id: p.anagrafica_id, ...righe[p.anagrafica_id] },
    { preserveScroll: true },
  );
};

const fotografia = () => {
  if (!confirm('Rileggere unità, titolari e millesimi dalle anagrafiche e dalla tabella? Le presenze registrate restano.')) return;
  router.post(route(...generateRoute('gestionale.assemblee.fotografia', base())), {}, { preserveScroll: true });
};

const altri = (id: number) => props.opzioni.anagrafiche.filter((a) => a.id !== id);
</script>

<template>
  <div class="space-y-8">
    <section class="space-y-3">
      <div class="flex items-center justify-between">
        <h2 class="font-semibold">Svolgimento</h2>
        <Button v-if="modificabile" size="sm" :disabled="svolgimento.processing" @click="salvaSvolgimento"><Save class="h-4 w-4" /> Salva</Button>
      </div>
      <fieldset :disabled="!modificabile" class="grid grid-cols-1 gap-3 sm:grid-cols-6">
        <div class="sm:col-span-2">
          <Label for="conv-tenuta">Si tiene in</Label>
          <select id="conv-tenuta" v-model="svolgimento.convocazione_tenuta" :class="selectClass + ' mt-1 h-9'">
            <option value="prima">prima convocazione</option>
            <option value="seconda" :disabled="!assemblea.seconda_convocazione_at">seconda convocazione</option>
          </select>
          <div v-if="svolgimento.errors.convocazione_tenuta" class="text-xs text-red-600">{{ svolgimento.errors.convocazione_tenuta }}</div>
        </div>
        <div class="sm:col-span-4 flex items-end">
          <label class="flex items-center gap-2 text-sm">
            <input v-model="svolgimento.prima_deserta" type="checkbox" class="h-4 w-4" />
            La prima convocazione è andata deserta (si annota nel verbale)
          </label>
        </div>
        <div class="sm:col-span-2"><Label for="presidente">Presidente</Label><Input id="presidente" v-model="svolgimento.presidente" class="mt-1" /></div>
        <div class="sm:col-span-2"><Label for="segretario">Segretario</Label><Input id="segretario" v-model="svolgimento.segretario" class="mt-1" /></div>
        <div class="sm:col-span-1"><Label for="ora-apertura">Apertura</Label><Input id="ora-apertura" v-model="svolgimento.ora_apertura" type="time" class="mt-1" /></div>
        <div class="sm:col-span-1"><Label for="ora-chiusura">Chiusura</Label><Input id="ora-chiusura" v-model="svolgimento.ora_chiusura" type="time" class="mt-1" /></div>
      </fieldset>
    </section>

    <section class="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <EsitoQuorum
        titolo="Quorum costitutivo — prima convocazione"
        :sottotitolo="`${costitutivo.prima.regola} (${costitutivo.prima.norma}) · presenti ${costitutivo.prima.teste_presenti}/${costitutivo.prima.teste_totali}, ${costitutivo.prima.valore_presente_testo}/${costitutivo.prima.valore_totale_testo} ‰`"
        :requisiti="costitutivo.prima.requisiti"
        :raggiunto="costitutivo.prima.raggiunto"
      />
      <EsitoQuorum
        v-if="costitutivo.seconda"
        titolo="Quorum costitutivo — seconda convocazione"
        :sottotitolo="`${costitutivo.seconda.regola} (${costitutivo.seconda.norma})`"
        :requisiti="costitutivo.seconda.requisiti"
        :raggiunto="costitutivo.seconda.raggiunto"
      />
    </section>

    <section class="space-y-3">
      <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 class="font-semibold">Presenze e deleghe</h2>
          <p class="text-xs text-muted-foreground">
            La delega è scritta e non può andare all'amministratore (art. 67 c. 5 disp. att.). Con più di 20 condòmini un delegato
            non rappresenta più di un quinto dei condòmini e del valore.
          </p>
        </div>
        <div class="flex gap-2">
          <Button v-if="assemblea.stato !== 'verbalizzata'" size="sm" variant="outline" @click="fotografia"><RefreshCw class="h-4 w-4" /> Rileggi unità e millesimi</Button>
          <Button as="a" size="sm" variant="outline" target="_blank" :href="route(...generateRoute('gestionale.assemblee.stampa', { ...base(), documento: 'presenze' }))">
            <Printer class="h-4 w-4" /> Foglio presenze
          </Button>
        </div>
      </div>

      <div v-if="errori.delegato || errori.anagrafica_id || errori.stato" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
        {{ errori.delegato || errori.anagrafica_id || errori.stato }}
      </div>

      <div v-if="assemblea.stato === 'bozza'" class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
        Le presenze si registrano dopo la convocazione.
      </div>

      <div class="overflow-x-auto rounded-xl border">
        <table class="w-full text-sm">
          <thead class="bg-muted/50 text-left text-xs uppercase text-muted-foreground">
            <tr>
              <th class="px-3 py-2">Persona</th>
              <th class="px-3 py-2">Presenza</th>
              <th class="px-3 py-2">Delegato</th>
              <th class="px-3 py-2">Ingresso</th>
              <th class="px-3 py-2">Uscita</th>
              <th class="px-3 py-2"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in persone" :key="p.anagrafica_id" class="border-t align-top">
              <template v-if="righe[p.anagrafica_id]">
                <td class="px-3 py-2">
                  <div class="font-medium">{{ p.nome }}</div>
                  <div class="text-xs text-muted-foreground">{{ p.unita.join(', ') }} · {{ p.ruoli.join(', ') }}</div>
                </td>
                <td class="px-3 py-2 min-w-32">
                  <select v-model="righe[p.anagrafica_id].modo" :class="selectClass" :disabled="!modificabile">
                    <option value="presente">Presente</option>
                    <option value="delegato">Per delega</option>
                    <option value="assente">Assente</option>
                  </select>
                </td>
                <td class="px-3 py-2 min-w-64">
                  <div v-if="righe[p.anagrafica_id].modo === 'delegato'" class="space-y-1">
                    <select v-model="righe[p.anagrafica_id].delegato_anagrafica_id" :class="selectClass" :disabled="!modificabile">
                      <option :value="null">— persona esterna (scrivi il nome) —</option>
                      <option v-for="a in altri(p.anagrafica_id)" :key="a.id" :value="a.id">{{ a.nome }}</option>
                    </select>
                    <Input
                      v-if="!righe[p.anagrafica_id].delegato_anagrafica_id"
                      v-model="righe[p.anagrafica_id].delegato_nome"
                      class="h-8"
                      placeholder="Nome del delegato"
                      :disabled="!modificabile"
                    />
                    <label class="flex items-center gap-2 text-xs">
                      <input v-model="righe[p.anagrafica_id].delega_scritta" type="checkbox" class="h-3.5 w-3.5" :disabled="!modificabile" /> delega scritta consegnata
                    </label>
                  </div>
                  <span v-else class="text-muted-foreground">—</span>
                </td>
                <td class="px-3 py-2"><Input v-model="righe[p.anagrafica_id].ora_ingresso" type="time" class="h-8" :disabled="!modificabile" /></td>
                <td class="px-3 py-2"><Input v-model="righe[p.anagrafica_id].ora_uscita" type="time" class="h-8" :disabled="!modificabile" /></td>
                <td class="px-3 py-2">
                  <Button v-if="modificabile" size="icon-sm" variant="ghost" title="Salva" @click="salvaPresenza(p)"><Save class="h-4 w-4" /></Button>
                </td>
              </template>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="space-y-2">
      <h2 class="font-semibold">Unità e millesimi ({{ assemblea.tabella_nome ?? 'nessuna tabella' }})</h2>
      <div class="overflow-x-auto rounded-xl border">
        <table class="w-full text-sm">
          <thead class="bg-muted/50 text-left text-xs uppercase text-muted-foreground">
            <tr><th class="px-3 py-2">Unità</th><th class="px-3 py-2 text-right">Millesimi</th></tr>
          </thead>
          <tbody>
            <tr v-for="u in unita" :key="u.id" class="border-t">
              <td class="px-3 py-1.5">{{ u.etichetta }}</td>
              <td class="px-3 py-1.5 text-right font-mono">{{ u.valore_testo }}</td>
            </tr>
            <tr class="border-t font-semibold">
              <td class="px-3 py-1.5">Totale</td>
              <td class="px-3 py-1.5 text-right font-mono">{{ totaleMillesimi }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>
