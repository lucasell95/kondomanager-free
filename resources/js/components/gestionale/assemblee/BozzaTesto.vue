<script setup lang="ts">
// Pulsante «Genera bozza» con anteprima dei dati che verrebbero inviati all'IA, attesa lunga
// (la generazione può superare il minuto) e avvisi della validazione. Non salva: emette la bozza.
import { ref } from 'vue';
import axios from 'axios';
import { Button } from '@/components/ui/button';
import { Eye, LoaderCircle, Sparkles } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import type { Bozza } from '@/types/gestionale/assemblee';

const props = defineProps<{
  condominioId: number;
  assembleaId: number;
  tipo: 'convocazione' | 'verbale';
  iaDisponibile: boolean;
}>();

const emit = defineEmits<{ (e: 'bozza', bozza: Bozza): void }>();

const { generateRoute } = usePermission();
const params = () => ({ condominio: props.condominioId, assemblea: props.assembleaId, tipo: props.tipo });

const inAttesa = ref(false);
const errore = ref<string | null>(null);
const ultima = ref<Bozza | null>(null);
const contesto = ref<unknown>(null);
const mostraContesto = ref(false);

const anteprima = async () => {
  if (mostraContesto.value) {
    mostraContesto.value = false;
    return;
  }
  const { data } = await axios.get(route(...generateRoute('gestionale.assemblee.bozza.contesto', params())));
  contesto.value = data.contesto;
  mostraContesto.value = true;
};

const genera = async () => {
  inAttesa.value = true;
  errore.value = null;
  try {
    const { data } = await axios.post<Bozza>(route(...generateRoute('gestionale.assemblee.bozza', params())), {}, { timeout: 180000 });
    ultima.value = data;
    emit('bozza', data);
  } catch {
    errore.value = 'La bozza non è arrivata in tempo o il servizio ha risposto con un errore. Riprova, oppure scrivi il testo a mano.';
  } finally {
    inAttesa.value = false;
  }
};
</script>

<template>
  <div class="space-y-2">
    <div class="flex flex-wrap justify-end gap-2">
      <Button v-if="iaDisponibile" size="sm" variant="ghost" type="button" @click="anteprima">
        <Eye class="h-4 w-4" /> {{ mostraContesto ? 'Nascondi' : 'Dati inviati all\'IA' }}
      </Button>
      <Button size="sm" variant="outline" type="button" :disabled="inAttesa" @click="genera">
        <LoaderCircle v-if="inAttesa" class="h-4 w-4 animate-spin" />
        <Sparkles v-else class="h-4 w-4" />
        {{ inAttesa ? (iaDisponibile ? 'Generazione in corso (può durare oltre un minuto)…' : 'Generazione…') : `Genera bozza ${iaDisponibile ? '(IA)' : '(modello fisso)'}` }}
      </Button>
    </div>

    <pre v-if="mostraContesto" class="max-h-80 overflow-auto rounded-lg border bg-muted/40 p-3 text-xs">{{ JSON.stringify(contesto, null, 2) }}</pre>

    <div v-if="errore" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">{{ errore }}</div>

    <div v-if="ultima" class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-900 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-100">
      Bozza {{ ultima.fonte === 'ia' ? 'scritta dall\'IA' : 'dal modello fisso' }} inserita nei campi: rileggi e salva ogni punto.
      <span v-if="ultima.modello" class="text-xs">(modello {{ ultima.modello }}<template v-if="ultima.versione_prompt">, prompt {{ ultima.versione_prompt }}</template>)</span>
      <ul v-if="ultima.avvisi.length || ultima.note_per_revisore.length" class="mt-2 list-disc pl-5">
        <li v-for="(a, i) in [...ultima.avvisi, ...ultima.note_per_revisore]" :key="i">{{ a }}</li>
      </ul>
    </div>
  </div>
</template>
