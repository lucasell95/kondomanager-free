<script setup lang="ts">
// Scheda «Dati e ordine del giorno»: dati generali, punti con materia e maggioranza, bozza dei punti.
import { reactive, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import FormDatiAssemblea from '@/components/gestionale/assemblee/FormDatiAssemblea.vue';
import BozzaTesto from '@/components/gestionale/assemblee/BozzaTesto.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import InputError from '@/components/InputError.vue';
import { ArrowDown, ArrowUp, Save, Trash2, Plus } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import type { Assemblea, Bozza, OpzioniAssemblea, Punto } from '@/types/gestionale/assemblee';

const props = defineProps<{
  condominioId: number;
  assemblea: Assemblea;
  punti: Punto[];
  opzioni: OpzioniAssemblea;
  iaDisponibile: boolean;
}>();

const { generateRoute } = usePermission();
const base = () => ({ condominio: props.condominioId, assemblea: props.assemblea.id });
const selectClass = 'mt-1 h-9 w-full rounded-md border border-input bg-background px-3 text-sm';

const datiDaProps = () => ({
  tipo: props.assemblea.tipo ?? 'ordinaria',
  titolo: props.assemblea.titolo ?? '',
  esercizio_id: props.assemblea.esercizio_id as number | string | null,
  tabella_id: props.assemblea.tabella_id as number | string | null,
  prima_convocazione_at: props.assemblea.prima_convocazione_at ?? '',
  prima_luogo: props.assemblea.prima_luogo ?? '',
  seconda_convocazione_at: props.assemblea.seconda_convocazione_at ?? '',
  seconda_luogo: props.assemblea.seconda_luogo ?? '',
  videoconferenza: props.assemblea.videoconferenza,
  piattaforma_video: props.assemblea.piattaforma_video ?? '',
  link_video: props.assemblea.link_video ?? '',
  consenso_video_acquisito: props.assemblea.consenso_video_acquisito,
  convocante: props.assemblea.convocante ?? '',
  amministratore_anagrafica_id: props.assemblea.amministratore_anagrafica_id as number | string | null,
  amministratore_nome: props.assemblea.amministratore_nome ?? '',
  allegati: props.assemblea.allegati ?? '',
  note: props.assemblea.note ?? '',
});

const formDati = useForm(datiDaProps());

const salvaDati = () => {
  formDati.put(route(...generateRoute('gestionale.assemblee.update', base())), { preserveScroll: true });
};

// --- Ordine del giorno ---
type BozzaPuntoForm = { titolo: string; testo: string; materia: string; tabella_id: number | string | null };

const modifiche = reactive<Record<number, BozzaPuntoForm>>({});
const caricaModifiche = () => {
  Object.keys(modifiche).forEach((k) => delete modifiche[Number(k)]);
  props.punti.forEach((p) => {
    modifiche[p.id] = { titolo: p.titolo, testo: p.testo ?? '', materia: p.materia, tabella_id: p.tabella_id };
  });
};
caricaModifiche();
watch(() => props.punti, caricaModifiche, { deep: true });

const nuovo = useForm({ titolo: '', testo: '', materia: 'ordinaria', tabella_id: null as number | string | null });

const aggiungiPunto = () => {
  nuovo.post(route(...generateRoute('gestionale.assemblee.punti.store', base())), {
    preserveScroll: true,
    onSuccess: () => nuovo.reset(),
  });
};

const salvaPunto = (p: Punto) => {
  router.put(route(...generateRoute('gestionale.assemblee.punti.update', { ...base(), punto: p.id })), modifiche[p.id], { preserveScroll: true });
};

const eliminaPunto = (p: Punto) => {
  if (!confirm(`Eliminare il punto «${p.titolo}»?`)) return;
  router.delete(route(...generateRoute('gestionale.assemblee.punti.destroy', { ...base(), punto: p.id })), { preserveScroll: true });
};

const sposta = (p: Punto, direzione: 'su' | 'giu') => {
  router.put(route(...generateRoute('gestionale.assemblee.punti.sposta', { ...base(), punto: p.id })), { direzione }, { preserveScroll: true });
};

const materia = (valore: string) => props.opzioni.materie.find((m) => m.value === valore);

// --- Bozza dei punti (IA o modello fisso): riempie i campi, non salva ---
const applicaBozza = (bozza: Bozza) => {
  bozza.punti.forEach((bp) => {
    if (modifiche[bp.id]) {
      modifiche[bp.id].titolo = bp.titolo ?? modifiche[bp.id].titolo;
      modifiche[bp.id].testo = bp.testo ?? modifiche[bp.id].testo;
    }
  });
};
</script>

<template>
  <div class="space-y-8">
    <section class="space-y-4">
      <div class="flex items-center justify-between">
        <h2 class="font-semibold">Dati dell'assemblea</h2>
        <Button v-if="assemblea.dati_modificabili" size="sm" :disabled="formDati.processing" @click="salvaDati">
          <Save class="h-4 w-4" /> Salva dati
        </Button>
      </div>
      <FormDatiAssemblea :form="formDati" :opzioni="opzioni" :disabilitato="!assemblea.dati_modificabili" />
    </section>

    <section class="space-y-4">
      <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 class="font-semibold">Ordine del giorno</h2>
          <p class="text-xs text-muted-foreground">
            Per ogni punto scegli la materia: decide la maggioranza richiesta e chi vota in caso di usufrutto.
          </p>
        </div>

      </div>

      <BozzaTesto
        v-if="assemblea.dati_modificabili && punti.length"
        :condominio-id="condominioId"
        :assemblea-id="assemblea.id"
        tipo="convocazione"
        :ia-disponibile="iaDisponibile"
        @bozza="applicaBozza"
      />

      <div v-if="punti.length === 0" class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
        Nessun punto all'ordine del giorno.
      </div>

      <div v-for="(p, i) in punti" :key="p.id" class="rounded-xl border p-4 space-y-3">
        <div class="flex items-start justify-between gap-2">
          <div class="font-medium">Punto {{ i + 1 }}</div>
          <div v-if="assemblea.dati_modificabili" class="flex gap-1">
            <Button size="icon-sm" variant="ghost" :disabled="i === 0" title="Sposta su" @click="sposta(p, 'su')"><ArrowUp class="h-4 w-4" /></Button>
            <Button size="icon-sm" variant="ghost" :disabled="i === punti.length - 1" title="Sposta giù" @click="sposta(p, 'giu')"><ArrowDown class="h-4 w-4" /></Button>
            <Button size="icon-sm" variant="ghost" title="Elimina" @click="eliminaPunto(p)"><Trash2 class="h-4 w-4 text-red-600" /></Button>
          </div>
        </div>
        <fieldset v-if="modifiche[p.id]" :disabled="!assemblea.dati_modificabili" class="grid grid-cols-1 gap-3 sm:grid-cols-6">
          <div class="sm:col-span-4">
            <Label :for="`titolo-${p.id}`">Titolo</Label>
            <Input :id="`titolo-${p.id}`" v-model="modifiche[p.id].titolo" class="mt-1" />
          </div>
          <div class="sm:col-span-2">
            <Label :for="`materia-${p.id}`">Materia</Label>
            <select :id="`materia-${p.id}`" v-model="modifiche[p.id].materia" :class="selectClass">
              <option v-for="m in opzioni.materie" :key="m.value" :value="m.value">{{ m.label }}</option>
            </select>
          </div>
          <div class="sm:col-span-4">
            <Label :for="`testo-${p.id}`">Descrizione</Label>
            <Textarea :id="`testo-${p.id}`" v-model="modifiche[p.id].testo" class="mt-1" rows="3" />
          </div>
          <div class="sm:col-span-2 space-y-2">
            <div>
              <Label :for="`tabella-${p.id}`">Tabella (se diversa)</Label>
              <select :id="`tabella-${p.id}`" v-model="modifiche[p.id].tabella_id" :class="selectClass">
                <option :value="null">— quella dell'assemblea —</option>
                <option v-for="t in opzioni.tabelle" :key="t.id" :value="t.id">{{ t.nome }}</option>
              </select>
            </div>
            <div class="text-xs text-muted-foreground space-y-1">
              <div><span class="font-medium">Norma:</span> {{ materia(modifiche[p.id].materia)?.norma }}</div>
              <div v-if="materia(modifiche[p.id].materia)?.maggioranza_prima">
                <span class="font-medium">1ª conv.:</span> {{ materia(modifiche[p.id].materia)?.maggioranza_prima }}
              </div>
              <div v-if="materia(modifiche[p.id].materia)?.maggioranza_seconda">
                <span class="font-medium">2ª conv.:</span> {{ materia(modifiche[p.id].materia)?.maggioranza_seconda }}
              </div>
              <div>Con usufrutto vota: {{ materia(modifiche[p.id].materia)?.vota_usufruttuario ? 'l\'usufruttuario' : 'il nudo proprietario' }}</div>
              <div v-if="materia(modifiche[p.id].materia)?.avvertenza" class="text-amber-700 dark:text-amber-300">
                {{ materia(modifiche[p.id].materia)?.avvertenza }}
              </div>
            </div>
          </div>
        </fieldset>
        <div v-if="assemblea.dati_modificabili" class="flex justify-end">
          <Button size="sm" variant="outline" @click="salvaPunto(p)"><Save class="h-4 w-4" /> Salva punto</Button>
        </div>
      </div>

      <form v-if="assemblea.dati_modificabili" class="rounded-xl border border-dashed p-4 space-y-3" @submit.prevent="aggiungiPunto">
        <div class="font-medium">Nuovo punto</div>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-6">
          <div class="sm:col-span-4">
            <Label for="nuovo-titolo">Titolo</Label>
            <Input id="nuovo-titolo" v-model="nuovo.titolo" class="mt-1" placeholder="Es. Approvazione del rendiconto 2025/26" />
            <InputError :message="nuovo.errors.titolo" />
          </div>
          <div class="sm:col-span-2">
            <Label for="nuovo-materia">Materia</Label>
            <select id="nuovo-materia" v-model="nuovo.materia" :class="selectClass">
              <option v-for="m in opzioni.materie" :key="m.value" :value="m.value">{{ m.label }}</option>
            </select>
            <InputError :message="nuovo.errors.materia" />
          </div>
          <div class="sm:col-span-6">
            <Label for="nuovo-testo">Descrizione</Label>
            <Textarea id="nuovo-testo" v-model="nuovo.testo" class="mt-1" rows="2" />
          </div>
        </div>
        <div class="flex justify-end">
          <Button size="sm" :disabled="nuovo.processing"><Plus class="h-4 w-4" /> Aggiungi punto</Button>
        </div>
      </form>
    </section>
  </div>
</template>
