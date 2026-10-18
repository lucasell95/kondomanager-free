<script setup lang="ts">

import { ref } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { usePage, Link } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { Plus, TableProperties } from 'lucide-vue-next';
import { trans } from 'laravel-vue-i18n';
import { usePermission } from '@/composables/permissions';
import { useTabellaServer } from '@/composables/useTabellaServer';
import type { Table } from '@tanstack/vue-table';
import type { Building } from '@/types/buildings';
import type { PianoRate } from '@/types/gestionale/piani-rate';
import type { Esercizio } from '@/types/gestionale/esercizi';

// Props
const props = defineProps<{ table: Table<PianoRate> }>();

// Page props
const page = usePage<{ condominio: Building; esercizio: Esercizio }>();

// Permissions / routes
const { generateRoute } = usePermission();

// Filters
const nameFilter = ref('')

const { filtra } = useTabellaServer(() =>
  route(generateRoute('gestionale.esercizi.piani-rate.index'), { condominio: page.props.condominio.id, esercizio: page.props.esercizio.id  }),
)

// Watch filters with debounce
watchDebounced(
  [nameFilter],
  () => {
    filtra(
      { nome: nameFilter.value || null },
      () => {
        if (!nameFilter.value) props.table.reset()
      },
    )
  },
  { debounce: 300 }
)
</script>

<template>
  <div class="flex items-center justify-between w-full mb-3">
    
    <div class="flex items-center space-x-2">
      <div class="flex items-center space-x-2">
        <Input
          placeholder="Filtra per nome..."
          v-model="nameFilter"
          class="h-8 w-[150px] lg:w-[250px]"
        />

      </div>
    </div>

    <div class="flex items-center gap-2">
      <!-- Prospetto unificato: tutte le rate dell'esercizio sommate per condòmino e scadenza (sola lettura). -->
      <Link
        :href="route(generateRoute('gestionale.esercizi.piani-rate.prospetto-unificato'), { condominio: page.props.condominio.id, esercizio: page.props.esercizio.id })"
        :title="trans('gestionale.piani_rate.prospetto_unificato.link_help')"
        class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-sm text-xs font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors"
        prefetch
      >
        <TableProperties class="w-3.5 h-3.5" />
        <span>{{ trans('gestionale.piani_rate.prospetto_unificato.link') }}</span>
      </Link>

      <Link
        :href="route(generateRoute('gestionale.esercizi.piani-rate.create'), { condominio: page.props.condominio.id, esercizio: page.props.esercizio.id })"
        class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-slate-900 dark:bg-slate-700 border border-slate-800 shadow-sm text-xs font-medium text-white hover:bg-slate-800 dark:hover:bg-slate-600 transition-colors"
        prefetch
      >
        <Plus class="w-3.5 h-3.5" />
        <span>Nuovo piano rate</span>
      </Link>
    </div>

  </div>
</template>
