<script setup lang="ts">
/**
 * Guida per le fatture tardive: la data del documento cade in un esercizio già chiuso?
 *
 * Dopo la chiusura l'esercizio vecchio non accetta più registrazioni (StoreFatturaRequest vuole
 * un esercizio aperto). La fattura si registra quindi nell'esercizio aperto come **debito
 * pregresso**, con la sua data di competenza: è il percorso `is_pregresso` che il modulo già
 * conosce (copertura con Rata 0, fondo o sopravvenienza). Questo avviso lo dice esplicitamente e,
 * con `@tardiva`, permette al modulo di proporre la data di competenza originaria.
 *
 * Chiede al server (`esercizi-competenza`) a quale esercizio appartiene la data: le date degli
 * esercizi chiusi non stanno nelle props del modulo.
 */
import { ref, watch } from 'vue';
import axios from 'axios';
import { FileClock } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';

const props = defineProps<{ condominioId: number; dataDocumento: string | null | undefined }>();
const emit = defineEmits<{ (e: 'tardiva', esito: { esercizio: string; data: string }): void }>();

const { generateRoute } = usePermission();
const messaggio = ref<string | null>(null);

let ultima = 0;
watch(
  () => props.dataDocumento,
  async (data) => {
    messaggio.value = null;
    if (!data || !/^\d{4}-\d{2}-\d{2}$/.test(data)) return;

    const richiesta = ++ultima;
    try {
      const { data: esito } = await axios.get(
        route(generateRoute('gestionale.esercizi.competenza'), { condominio: props.condominioId }),
        { params: { data } },
      );
      if (richiesta !== ultima) return; // una risposta vecchia non copre quella nuova
      messaggio.value = esito.tardiva ? esito.messaggio : null;
      if (esito.tardiva) emit('tardiva', { esercizio: esito.esercizio_competenza?.nome ?? '', data });
    } catch {
      messaggio.value = null; // è una guida: se non risponde, il modulo funziona come prima
    }
  },
  { immediate: true },
);
</script>

<template>
  <div v-if="messaggio" class="flex items-start gap-2 rounded-md border border-sky-200 bg-sky-50 p-2 text-[10.5px] font-medium text-sky-800 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-300">
    <FileClock class="mt-0.5 h-3.5 w-3.5 shrink-0" />
    <span><strong>Fattura tardiva.</strong> {{ messaggio }}</span>
  </div>
</template>
