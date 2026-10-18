<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { FileText, ArrowLeft } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import { classeStato, dataOra } from '@/lib/gestionale/assemblee';
import type { StatoAssemblea } from '@/types/gestionale/assemblee';

defineProps<{
  assemblea: {
    id: number;
    titolo: string;
    condominio: string | null;
    tipo_label: string;
    stato: StatoAssemblea;
    stato_label: string;
    prima_convocazione_at: string | null;
    seconda_convocazione_at: string | null;
    prima_luogo: string | null;
    seconda_luogo: string | null;
    videoconferenza: boolean;
    piattaforma_video: string | null;
    link_video: string | null;
    numero_registro: string | null;
    ha_verbale: boolean;
    allegati: string[];
    punti: { id: number; titolo: string; testo: string | null }[];
  };
}>();

const { generateRoute } = usePermission();
</script>

<template>
  <Head :title="assemblea.titolo" />

  <AppLayout>
    <div class="px-4 py-6 space-y-6">
      <div class="flex flex-wrap items-start justify-between gap-2">
        <Heading :title="assemblea.titolo" :description="`${assemblea.condominio ?? ''} · ${assemblea.tipo_label}`" />
        <Badge variant="outline" :class="classeStato[assemblea.stato]">{{ assemblea.stato_label }}</Badge>
      </div>

      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div class="rounded-xl border p-4">
          <div class="text-xs uppercase text-muted-foreground">Prima convocazione</div>
          <div class="font-medium">{{ dataOra(assemblea.prima_convocazione_at) }}</div>
          <div class="text-sm">{{ assemblea.prima_luogo }}</div>
        </div>
        <div v-if="assemblea.seconda_convocazione_at" class="rounded-xl border p-4">
          <div class="text-xs uppercase text-muted-foreground">Seconda convocazione</div>
          <div class="font-medium">{{ dataOra(assemblea.seconda_convocazione_at) }}</div>
          <div class="text-sm">{{ assemblea.seconda_luogo }}</div>
        </div>
      </div>

      <div v-if="assemblea.videoconferenza" class="rounded-xl border p-4 text-sm">
        Anche in videoconferenza: {{ assemblea.piattaforma_video }} <span v-if="assemblea.link_video">— {{ assemblea.link_video }}</span>
      </div>

      <div class="rounded-xl border p-4 space-y-2">
        <h2 class="font-semibold">Ordine del giorno</h2>
        <ol class="list-decimal pl-5 space-y-2 text-sm">
          <li v-for="p in assemblea.punti" :key="p.id">
            <div class="font-medium">{{ p.titolo }}</div>
            <div v-if="p.testo" class="whitespace-pre-line text-muted-foreground">{{ p.testo }}</div>
          </li>
        </ol>
        <div v-if="assemblea.allegati.length" class="pt-2 text-sm">
          <span class="font-medium">Allegati:</span> {{ assemblea.allegati.join(', ') }}
        </div>
      </div>

      <div class="flex flex-wrap gap-2">
        <Button as="a" variant="outline" target="_blank" :href="route(...generateRoute('assemblee.avviso', { assemblea: assemblea.id }))">
          <FileText class="h-4 w-4" /> Avviso di convocazione
        </Button>
        <Button v-if="assemblea.ha_verbale" as="a" variant="outline" target="_blank" :href="route(...generateRoute('assemblee.verbale', { assemblea: assemblea.id }))">
          <FileText class="h-4 w-4" /> Verbale n. {{ assemblea.numero_registro }}
        </Button>
        <Link :href="route(generateRoute('assemblee.index'))" class="inline-flex h-9 items-center gap-2 rounded-md px-3 text-sm hover:bg-muted">
          <ArrowLeft class="h-4 w-4" /> Tutte le assemblee
        </Link>
      </div>
    </div>
  </AppLayout>
</template>
