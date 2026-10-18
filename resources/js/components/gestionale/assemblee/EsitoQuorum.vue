<script setup lang="ts">
// Il calcolo di un quorum, riga per riga: chi presiede deve poterlo verificare a mano.
import { CheckCircle2, XCircle } from 'lucide-vue-next';
import type { Requisito } from '@/types/gestionale/assemblee';

defineProps<{
  titolo: string;
  sottotitolo?: string | null;
  requisiti: Requisito[];
  raggiunto: boolean | null;
  etichetta?: string;
}>();
</script>

<template>
  <div class="rounded-xl border p-4 space-y-2">
    <div class="flex items-start justify-between gap-2">
      <div>
        <div class="font-medium">{{ titolo }}</div>
        <div v-if="sottotitolo" class="text-xs text-muted-foreground">{{ sottotitolo }}</div>
      </div>
      <span
        v-if="raggiunto !== null"
        class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-semibold"
        :class="raggiunto
          ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-200'
          : 'border-red-200 bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-200'"
      >
        <CheckCircle2 v-if="raggiunto" class="h-3.5 w-3.5" />
        <XCircle v-else class="h-3.5 w-3.5" />
        {{ etichetta ?? (raggiunto ? 'Raggiunto' : 'Non raggiunto') }}
      </span>
    </div>
    <ul class="space-y-1 text-sm">
      <li v-for="(r, i) in requisiti" :key="i" class="flex gap-2">
        <CheckCircle2 v-if="r.raggiunto" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />
        <XCircle v-else class="mt-0.5 h-4 w-4 shrink-0 text-red-600" />
        <span>
          <span class="font-medium">{{ r.oggetto === 'teste' ? 'Teste' : 'Millesimi' }}</span>
          — richiesti {{ r.richiesto }}<br />
          <span class="font-mono text-xs text-muted-foreground">{{ r.calcolo }}</span>
        </span>
      </li>
    </ul>
    <slot />
  </div>
</template>
