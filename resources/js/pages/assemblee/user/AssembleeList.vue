<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { usePermission } from '@/composables/permissions';
import { classeStato, dataOra } from '@/lib/gestionale/assemblee';
import type { StatoAssemblea } from '@/types/gestionale/assemblee';

defineProps<{
  assemblee: {
    id: number;
    titolo: string;
    condominio: string | null;
    tipo_label: string;
    stato: StatoAssemblea;
    stato_label: string;
    prima_convocazione_at: string | null;
    seconda_convocazione_at: string | null;
    numero_registro: string | null;
    ha_verbale: boolean;
    punti_count: number;
  }[];
}>();

const { generateRoute } = usePermission();
</script>

<template>
  <Head title="Assemblee" />

  <AppLayout>
    <div class="px-4 py-6 space-y-4">
      <Heading title="Assemblee" description="Le assemblee convocate nel tuo condominio, con l'avviso di convocazione e, quando c'è, il verbale." />

      <div v-if="assemblee.length === 0" class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
        Nessuna assemblea convocata.
      </div>

      <Link
        v-for="a in assemblee"
        :key="a.id"
        :href="route(...generateRoute('assemblee.show', { assemblea: a.id }))"
        class="block rounded-xl border p-4 hover:bg-muted/50"
      >
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div>
            <div class="font-medium">{{ a.titolo }}</div>
            <div class="text-xs text-muted-foreground">{{ a.condominio }} · {{ a.tipo_label }} · {{ a.punti_count }} punti</div>
          </div>
          <Badge variant="outline" :class="classeStato[a.stato]">{{ a.stato_label }}</Badge>
        </div>
        <div class="mt-2 text-sm">
          1ª convocazione {{ dataOra(a.prima_convocazione_at) }}
          <template v-if="a.seconda_convocazione_at"> · 2ª convocazione {{ dataOra(a.seconda_convocazione_at) }}</template>
        </div>
      </Link>
    </div>
  </AppLayout>
</template>
