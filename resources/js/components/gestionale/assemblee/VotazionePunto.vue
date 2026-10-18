<script setup lang="ts">
// Votazione di un punto: presenza al momento del voto, voto e conflitto d'interessi per ogni testa.
// L'esito si ricalcola sul server a ogni modifica (anteprima, non salvata), con la stessa regola del verbale.
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import EsitoQuorum from '@/components/gestionale/assemblee/EsitoQuorum.vue';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { LoaderCircle, Save, ThumbsUp, ThumbsDown, MinusCircle, AlertTriangle } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import { classeEsito, etichettaEsito } from '@/lib/gestionale/assemblee';
import type { EsitoVotazione, Punto, Voto } from '@/types/gestionale/assemblee';

const props = defineProps<{
  condominioId: number;
  assembleaId: number;
  punto: Punto;
  numero: number;
  modificabile: boolean;
}>();

const { generateRoute } = usePermission();
const base = () => ({ condominio: props.condominioId, assemblea: props.assembleaId, punto: props.punto.id });

type Riga = { chiave_testa: string; etichetta: string; valore_testo: string; rappresentante: string | null; presente: boolean; voto: Voto; conflitto: boolean };

const righe = ref<Riga[]>([]);
const esito = ref<EsitoVotazione>(props.punto.votazione.esito);
const inCalcolo = ref(false);
const modificato = ref(false);

const carica = () => {
  righe.value = props.punto.votazione.teste.map((t) => ({
    chiave_testa: t.chiave,
    etichetta: t.etichetta,
    valore_testo: t.valore_testo,
    rappresentante: t.rappresentante,
    presente: t.presente,
    voto: t.voto,
    conflitto: t.conflitto,
  }));
  esito.value = props.punto.votazione.esito;
  modificato.value = false;
};
carica();
watch(() => props.punto.votazione, carica, { deep: true });

const payload = () => ({
  voti: righe.value.map((r) => ({ chiave_testa: r.chiave_testa, presente: r.presente, voto: r.presente ? r.voto : null, conflitto: r.conflitto })),
});

let timer: ReturnType<typeof setTimeout> | null = null;
const ricalcola = () => {
  modificato.value = true;
  if (timer) clearTimeout(timer);
  timer = setTimeout(async () => {
    inCalcolo.value = true;
    try {
      const { data } = await axios.post(route(...generateRoute('gestionale.assemblee.punti.anteprima', base())), payload());
      esito.value = data.esito;
    } finally {
      inCalcolo.value = false;
    }
  }, 250);
};

const vota = (r: Riga, voto: Voto) => {
  if (!props.modificabile || !r.presente) return;
  r.voto = r.voto === voto ? null : voto;
  ricalcola();
};

const tuttiFavorevoli = () => {
  righe.value.forEach((r) => { if (r.presente) r.voto = 'favorevole'; });
  ricalcola();
};

const salva = () => {
  router.put(route(...generateRoute('gestionale.assemblee.punti.voti', base())), payload(), { preserveScroll: true });
};

const senzaVoto = computed(() => esito.value.esito === 'senza_votazione');
const pulsante = (attivo: boolean, colore: string) =>
  attivo ? colore : 'border-input text-muted-foreground hover:bg-muted';
</script>

<template>
  <div class="rounded-xl border p-4 space-y-4">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
      <div>
        <div class="font-medium">Punto {{ numero }} — {{ punto.titolo }}</div>
        <div class="text-xs text-muted-foreground">
          {{ punto.materia_label }} · {{ esito.regola ?? 'nessuna votazione' }} ({{ esito.norma }})
          · con usufrutto vota {{ punto.vota_usufruttuario ? 'l\'usufruttuario' : 'il nudo proprietario' }}
        </div>
      </div>
      <div class="flex items-center gap-2">
        <LoaderCircle v-if="inCalcolo" class="h-4 w-4 animate-spin text-muted-foreground" />
        <Badge variant="outline" :class="classeEsito[esito.esito]">{{ etichettaEsito[esito.esito] }}</Badge>
      </div>
    </div>

    <template v-if="!senzaVoto">
      <div class="overflow-x-auto rounded-lg border">
        <table class="w-full text-sm">
          <thead class="bg-muted/50 text-left text-xs uppercase text-muted-foreground">
            <tr>
              <th class="px-3 py-2">Condòmino (unità)</th>
              <th class="px-3 py-2 text-right">Millesimi</th>
              <th class="px-3 py-2 text-center">Presente al voto</th>
              <th class="px-3 py-2">Voto</th>
              <th class="px-3 py-2 text-center">Conflitto</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in righe" :key="r.chiave_testa" class="border-t" :class="r.presente ? '' : 'opacity-60'">
              <td class="px-3 py-2">
                <div>{{ r.etichetta }}</div>
                <div v-if="r.rappresentante" class="text-xs text-muted-foreground">{{ r.rappresentante }}</div>
              </td>
              <td class="px-3 py-2 text-right font-mono">{{ r.valore_testo }}</td>
              <td class="px-3 py-2 text-center">
                <input v-model="r.presente" type="checkbox" class="h-4 w-4" :disabled="!modificabile" @change="ricalcola" />
              </td>
              <td class="px-3 py-2">
                <div class="flex gap-1">
                  <button type="button" class="inline-flex items-center gap-1 rounded-md border px-2 py-1 text-xs"
                    :class="pulsante(r.voto === 'favorevole', 'border-emerald-300 bg-emerald-50 text-emerald-700')"
                    :disabled="!modificabile || !r.presente" @click="vota(r, 'favorevole')">
                    <ThumbsUp class="h-3.5 w-3.5" /> Favorevole
                  </button>
                  <button type="button" class="inline-flex items-center gap-1 rounded-md border px-2 py-1 text-xs"
                    :class="pulsante(r.voto === 'contrario', 'border-red-300 bg-red-50 text-red-700')"
                    :disabled="!modificabile || !r.presente" @click="vota(r, 'contrario')">
                    <ThumbsDown class="h-3.5 w-3.5" /> Contrario
                  </button>
                  <button type="button" class="inline-flex items-center gap-1 rounded-md border px-2 py-1 text-xs"
                    :class="pulsante(r.voto === 'astenuto', 'border-slate-300 bg-slate-100 text-slate-700')"
                    :disabled="!modificabile || !r.presente" @click="vota(r, 'astenuto')">
                    <MinusCircle class="h-3.5 w-3.5" /> Astenuto
                  </button>
                </div>
              </td>
              <td class="px-3 py-2 text-center">
                <input v-model="r.conflitto" type="checkbox" class="h-4 w-4" :disabled="!modificabile" title="Conflitto d'interessi" @change="ricalcola" />
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-5">
        <div class="rounded-lg border px-3 py-2"><div class="text-xs text-muted-foreground">Intervenuti</div>{{ esito.intervenuti.teste }} · {{ esito.intervenuti.valore_testo }} ‰</div>
        <div class="rounded-lg border px-3 py-2"><div class="text-xs text-muted-foreground">Favorevoli</div>{{ esito.favorevoli.teste }} · {{ esito.favorevoli.valore_testo }} ‰</div>
        <div class="rounded-lg border px-3 py-2"><div class="text-xs text-muted-foreground">Contrari</div>{{ esito.contrari.teste }} · {{ esito.contrari.valore_testo }} ‰</div>
        <div class="rounded-lg border px-3 py-2"><div class="text-xs text-muted-foreground">Astenuti</div>{{ esito.astenuti.teste }} · {{ esito.astenuti.valore_testo }} ‰</div>
        <div class="rounded-lg border px-3 py-2"><div class="text-xs text-muted-foreground">Senza voto</div>{{ esito.non_espressi.teste }} · {{ esito.non_espressi.valore_testo }} ‰</div>
      </div>

      <EsitoQuorum titolo="Maggioranza deliberativa" :sottotitolo="esito.regola" :requisiti="esito.requisiti"
        :raggiunto="esito.esito === 'non_votata' ? null : esito.esito === 'approvata'"
        :etichetta="etichettaEsito[esito.esito]">
        <div v-if="esito.conflitto" class="flex gap-2 rounded-md border px-3 py-2 text-sm"
          :class="esito.conflitto.determinante ? 'border-amber-300 bg-amber-50 text-amber-900 dark:bg-amber-950 dark:text-amber-100' : 'border-slate-200 bg-slate-50 dark:bg-slate-900'">
          <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
          <span>{{ esito.conflitto.spiegazione }}</span>
        </div>
        <div v-if="esito.decisivi.length" class="text-xs text-muted-foreground">
          Voto decisivo: {{ esito.decisivi.join(', ') }}.
        </div>
      </EsitoQuorum>

      <div v-if="modificabile" class="flex flex-wrap justify-end gap-2">
        <Button size="sm" variant="outline" @click="tuttiFavorevoli">Tutti i presenti favorevoli</Button>
        <Button size="sm" @click="salva"><Save class="h-4 w-4" /> Salva votazione{{ modificato ? ' *' : '' }}</Button>
      </div>
    </template>
    <p v-else class="text-sm text-muted-foreground">Punto informativo: nessuna votazione.</p>
  </div>
</template>
