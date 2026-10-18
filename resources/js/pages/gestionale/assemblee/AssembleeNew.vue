<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import CondominioDropdown from '@/components/CondominioDropdown.vue';
import FormDatiAssemblea from '@/components/gestionale/assemblee/FormDatiAssemblea.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { List, Plus, LoaderCircle } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import type { Building } from '@/types/buildings';
import type { BreadcrumbItem } from '@/types';
import type { OpzioniAssemblea } from '@/types/gestionale/assemblee';

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
  opzioni: OpzioniAssemblea;
  tabellaProposta: number | null;
}>();

const { generatePath, generateRoute } = usePermission();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, component: 'condominio-dropdown' } as any,
  { title: 'assemblee', href: generatePath('gestionale/:condominio/assemblee', { condominio: props.condominio.id }) },
  { title: 'nuova assemblea', href: '#' },
]);

const form = useForm({
  tipo: 'ordinaria',
  titolo: '',
  esercizio_id: null as number | string | null,
  tabella_id: props.tabellaProposta as number | string | null,
  prima_convocazione_at: '',
  prima_luogo: '',
  seconda_convocazione_at: '',
  seconda_luogo: '',
  videoconferenza: false,
  piattaforma_video: '',
  link_video: '',
  consenso_video_acquisito: false,
  convocante: '',
  amministratore_anagrafica_id: null as number | string | null,
  amministratore_nome: '',
  allegati: '',
  note: '',
});

const submit = () => {
  form.post(route(...generateRoute('gestionale.assemblee.store', { condominio: props.condominio.id })), {
    preserveScroll: true,
  });
};
</script>

<template>
  <Head title="Nuova assemblea" />

  <GestionaleLayout :breadcrumbs="breadcrumbs">
    <template #breadcrumb-condominio>
      <CondominioDropdown :condominio="props.condominio" :condomini="props.condomini" />
    </template>

    <div class="px-4 py-6">
      <div class="w-full shadow ring-1 ring-black/5 md:rounded-lg p-4">
        <form class="space-y-2" @submit.prevent="submit">
          <div class="flex flex-col lg:flex-row lg:justify-between gap-2 w-full">
            <div>
              <h1 class="text-lg font-semibold">Nuova assemblea</h1>
              <p class="text-sm text-muted-foreground">Dopo il salvataggio si aggiungono i punti all'ordine del giorno e i destinatari.</p>
            </div>
            <div class="flex flex-col lg:flex-row gap-2">
              <Button :disabled="form.processing" class="h-8 w-full lg:w-auto">
                <Plus v-if="!form.processing" class="w-4 h-4" />
                <LoaderCircle v-else class="h-4 w-4 animate-spin" />
                Salva
              </Button>
              <Link
                :href="generatePath('gestionale/:condominio/assemblee', { condominio: props.condominio.id })"
                class="w-full lg:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90"
              >
                <List class="w-4 h-4" /> Assemblee
              </Link>
            </div>
          </div>

          <Separator class="my-4" />

          <FormDatiAssemblea :form="form" :opzioni="props.opzioni" />
        </form>
      </div>
    </div>
  </GestionaleLayout>
</template>
