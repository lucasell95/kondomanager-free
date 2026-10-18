<script setup lang="ts">
import { AlertTriangle, Info, XCircle } from 'lucide-vue-next';
import { classeLivello } from '@/lib/gestionale/assemblee';
import type { Controllo } from '@/types/gestionale/assemblee';

defineProps<{ controlli: Controllo[] }>();
</script>

<template>
  <div v-if="controlli.length" class="space-y-2">
    <div
      v-for="(c, i) in controlli"
      :key="i"
      class="flex items-start gap-2 rounded-lg border px-3 py-2 text-sm"
      :class="classeLivello[c.livello]"
    >
      <XCircle v-if="c.livello === 'error'" class="mt-0.5 h-4 w-4 shrink-0" />
      <AlertTriangle v-else-if="c.livello === 'warning'" class="mt-0.5 h-4 w-4 shrink-0" />
      <Info v-else class="mt-0.5 h-4 w-4 shrink-0" />
      <span>{{ c.messaggio }}</span>
    </div>
  </div>
</template>
