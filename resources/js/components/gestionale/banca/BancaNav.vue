<script setup lang="ts">
/**
 * Le tre schermate della banca: Importa, Movimenti da abbinare, Riconciliazione.
 * Stessa barra delle altre sezioni (NavSezione), dentro la sezione Movimenti.
 */
import { computed } from 'vue';
import NavSezione from '@/components/NavSezione.vue';
import { usePermission } from '@/composables/permissions';
import { Upload, ListChecks, Scale } from 'lucide-vue-next';

const props = defineProps<{ condominioId: number; daAbbinare?: number }>();
const { generatePath } = usePermission();

const voci = computed(() => [
    {
        icon: Upload,
        title: 'Importa estratto conto',
        href: generatePath('gestionale/:condominio/banca/importa', { condominio: props.condominioId }),
    },
    {
        icon: ListChecks,
        title: 'Movimenti da abbinare',
        href: generatePath('gestionale/:condominio/banca/movimenti', { condominio: props.condominioId }),
        badge: props.daAbbinare ? String(props.daAbbinare) : undefined,
    },
    {
        icon: Scale,
        title: 'Riconciliazione',
        href: generatePath('gestionale/:condominio/banca/riconciliazione', { condominio: props.condominioId }),
    },
]);
</script>

<template>
    <NavSezione :items="voci" class="mb-4" />
</template>
