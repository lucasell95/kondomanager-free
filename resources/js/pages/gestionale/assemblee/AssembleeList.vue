<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import CondominioDropdown from '@/components/CondominioDropdown.vue';
import Alert from '@/components/Alert.vue';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Plus, Printer, Gavel, BookOpen } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import { classeStato, dataOra } from '@/lib/gestionale/assemblee';
import type { Building } from '@/types/buildings';
import type { BreadcrumbItem } from '@/types';
import type { Flash } from '@/types/flash';
import type { RigaAssemblea } from '@/types/gestionale/assemblee';

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
  esercizi: { id: number; nome: string; stato: string }[];
  esercizioSelezionato: number | null;
  assemblee: RigaAssemblea[];
  registro: RigaAssemblea[];
}>();

const { generatePath, generateRoute } = usePermission();
const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash?.message);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, component: 'condominio-dropdown' } as any,
  { title: 'assemblee', href: '#' },
]);

const filtro = computed(() => (props.esercizioSelezionato ? String(props.esercizioSelezionato) : 'tutti'));

function cambiaEsercizio(event: Event) {
  const valore = (event.target as HTMLSelectElement).value;
  router.get(
    route(...generateRoute('gestionale.assemblee.index', { condominio: props.condominio.id })),
    { esercizio: valore },
    { preserveScroll: true, preserveState: true },
  );
}

function apri(id: number) {
  router.visit(route(...generateRoute('gestionale.assemblee.show', { condominio: props.condominio.id, assemblea: id })));
}

const urlRegistro = computed(() => route(...generateRoute('gestionale.assemblee.registro.stampa', { condominio: props.condominio.id })));
</script>

<template>
  <Head title="Assemblee" />

  <GestionaleLayout :breadcrumbs="breadcrumbs">
    <template #breadcrumb-condominio>
      <CondominioDropdown :condominio="props.condominio" :condomini="props.condomini" />
    </template>

    <div class="px-4 py-6 space-y-6">
      <div v-if="flashMessage" class="py-1">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        <div>
          <h1 class="text-xl font-semibold tracking-tight flex items-center gap-2">
            <Gavel class="h-5 w-5" /> Assemblee
          </h1>
          <p class="text-sm text-muted-foreground">
            Convocazione, presenze e deleghe, votazioni con calcolo dei quorum (art. 1136 c.c.) e verbale.
          </p>
        </div>
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
          <label class="text-sm text-muted-foreground" for="filtro-esercizio">Esercizio</label>
          <select
            id="filtro-esercizio"
            class="h-9 rounded-md border border-input bg-background px-3 text-sm"
            :value="filtro"
            @change="cambiaEsercizio"
          >
            <option value="tutti">Tutti gli esercizi</option>
            <option v-for="e in props.esercizi" :key="e.id" :value="String(e.id)">{{ e.nome }}</option>
          </select>
          <Link
            :href="route(...generateRoute('gestionale.assemblee.create', { condominio: props.condominio.id }))"
            class="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-primary px-3 text-sm font-medium text-white hover:bg-primary/90"
          >
            <Plus class="h-4 w-4" /> Nuova assemblea
          </Link>
        </div>
      </div>

      <div class="border border-slate-200 dark:border-slate-800 rounded-2xl bg-white dark:bg-slate-950 overflow-hidden shadow-sm">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Assemblea</TableHead>
              <TableHead>Prima convocazione</TableHead>
              <TableHead>Seconda convocazione</TableHead>
              <TableHead class="text-center">Punti</TableHead>
              <TableHead>Stato</TableHead>
              <TableHead>Verbale</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-if="props.assemblee.length === 0">
              <TableCell colspan="6" class="py-10 text-center text-sm text-muted-foreground">
                Nessuna assemblea per questo esercizio.
              </TableCell>
            </TableRow>
            <TableRow
              v-for="a in props.assemblee"
              :key="a.id"
              class="cursor-pointer hover:bg-muted/50"
              @click="apri(a.id)"
            >
              <TableCell>
                <div class="font-medium">{{ a.titolo }}</div>
                <div class="text-xs text-muted-foreground">{{ a.tipo_label }}</div>
              </TableCell>
              <TableCell class="text-sm">{{ dataOra(a.prima_convocazione_at) }}</TableCell>
              <TableCell class="text-sm">{{ dataOra(a.seconda_convocazione_at) }}</TableCell>
              <TableCell class="text-center">{{ a.punti_count ?? '—' }}</TableCell>
              <TableCell>
                <Badge variant="outline" :class="classeStato[a.stato]">{{ a.stato_label }}</Badge>
              </TableCell>
              <TableCell class="text-sm">{{ a.numero_registro ? 'n. ' + a.numero_registro : '—' }}</TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </div>

      <div class="border border-slate-200 dark:border-slate-800 rounded-2xl bg-white dark:bg-slate-950 overflow-hidden shadow-sm">
        <div class="flex items-center justify-between border-b px-4 py-3">
          <div>
            <h2 class="font-semibold flex items-center gap-2"><BookOpen class="h-4 w-4" /> Registro dei verbali</h2>
            <p class="text-xs text-muted-foreground">Art. 1130 n. 7 c.c. — numerazione progressiva per anno, tutti gli esercizi.</p>
          </div>
          <Button as="a" :href="urlRegistro" target="_blank" variant="outline" size="sm">
            <Printer class="h-4 w-4" /> Stampa registro
          </Button>
        </div>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>N.</TableHead>
              <TableHead>Data</TableHead>
              <TableHead>Assemblea</TableHead>
              <TableHead>Registrato il</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-if="props.registro.length === 0">
              <TableCell colspan="4" class="py-6 text-center text-sm text-muted-foreground">Nessun verbale registrato.</TableCell>
            </TableRow>
            <TableRow v-for="v in props.registro" :key="v.id" class="cursor-pointer hover:bg-muted/50" @click="apri(v.id)">
              <TableCell class="font-medium">{{ v.numero_registro }}</TableCell>
              <TableCell class="text-sm">{{ dataOra(v.data_svolgimento) }}</TableCell>
              <TableCell class="text-sm">{{ v.titolo }}</TableCell>
              <TableCell class="text-sm">{{ dataOra(v.verbale_registrato_at) }}</TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </div>
    </div>
  </GestionaleLayout>
</template>
