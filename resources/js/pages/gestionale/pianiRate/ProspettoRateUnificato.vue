<script setup lang="ts">
/**
 * Prospetto rate unificato — pagina dedicata (link diretti e stampe). Il contenuto è
 * `ProspettoUnificatoPanel`, lo stesso pannello mostrato nella lista dei piani rate.
 */
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide, { type GuideItem } from '@/components/PageHeaderGuide.vue';
import ProspettoUnificatoPanel from '@/components/gestionale/pianiRate/ProspettoUnificatoPanel.vue';
import { usePermission } from '@/composables/permissions';
import { trans } from 'laravel-vue-i18n';
import { CalendarDays, Layers, ShieldCheck } from 'lucide-vue-next';
import type { Building } from '@/types/buildings';
import type { Esercizio } from '@/types/gestionale/esercizi';
import type { ProspettoRateUnificato } from '@/types/gestionale/piani-rate';

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
  esercizio: Esercizio;
  esercizi: Esercizio[];
  prospetto: ProspettoRateUnificato;
}>();

const { generatePath, generateRoute } = usePermission();

const t = (chiave: string, sostituzioni?: Record<string, string | number>) =>
  trans(`gestionale.piani_rate.prospetto_unificato.${chiave}`, sostituzioni as Record<string, string> | undefined);

// ⚠️ `computed`, non `const`: le traduzioni dei file PHP arrivano in modo asincrono e una costante
// di modulo le congelerebbe prima dell'arrivo (vedi `TraduzioniNonSiCongelanoTest`).
const breadcrumbs = computed(() => [
  { title: trans('gestionale.piani_rate.breadcrumbs.management'), href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: trans('gestionale.piani_rate.breadcrumbs.rate_plans'), href: generatePath('gestionale/:condominio/esercizi/:esercizio/piani-rate', { condominio: props.condominio.id, esercizio: props.esercizio.id }) },
  { title: t('breadcrumb') },
]);

const pageGuides = computed<GuideItem[]>(() => [
  { title: t('guides.sum_title'), description: t('guides.sum_description'), icon: CalendarDays, colorVariant: 'blue' },
  { title: t('guides.separate_title'), description: t('guides.separate_description'), icon: ShieldCheck, colorVariant: 'emerald' },
  { title: t('guides.detail_title'), description: t('guides.detail_description'), icon: Layers, colorVariant: 'amber' },
]);

const backUrl = computed(() => route(generateRoute('gestionale.esercizi.piani-rate.index'), { condominio: props.condominio.id, esercizio: props.esercizio.id }));
</script>

<template>
  <Head :title="t('head_title')" />

  <GestionaleLayout>
    <div class="px-6 py-8 space-y-3">

      <PageHeaderGuide
        :page-title="t('page_title')"
        :page-subtitle="t('page_subtitle')"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :condominio="props.condominio"
        :condomini="props.condomini"
        :esercizio="props.esercizio"
        :esercizi="props.esercizi"
        :backUrl="backUrl"
        :backText="t('back')"
      />

      <ProspettoUnificatoPanel :condominio="props.condominio" :esercizio="props.esercizio" :prospetto="props.prospetto" />
    </div>
  </GestionaleLayout>
</template>
