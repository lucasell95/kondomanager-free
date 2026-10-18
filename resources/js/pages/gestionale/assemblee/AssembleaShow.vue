<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import CondominioDropdown from '@/components/CondominioDropdown.vue';
import Alert from '@/components/Alert.vue';
import TabDati from '@/components/gestionale/assemblee/TabDati.vue';
import TabConvocazione from '@/components/gestionale/assemblee/TabConvocazione.vue';
import TabPresenze from '@/components/gestionale/assemblee/TabPresenze.vue';
import VotazionePunto from '@/components/gestionale/assemblee/VotazionePunto.vue';
import TabVerbale from '@/components/gestionale/assemblee/TabVerbale.vue';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ArrowRight, List, Trash2 } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import { classeStato, dataOra } from '@/lib/gestionale/assemblee';
import type { Building } from '@/types/buildings';
import type { BreadcrumbItem } from '@/types';
import type { Flash } from '@/types/flash';
import type {
  Assemblea, Assente, Controllo, Destinatario, Dichiarazione, EsitoCostitutivo, OpzioniAssemblea, Persona, Punto,
} from '@/types/gestionale/assemblee';

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
  assemblea: Assemblea;
  punti: Punto[];
  unita: { id: number; etichetta: string; valore_testo: string }[];
  totaleMillesimi: string;
  costitutivo: { prima: EsitoCostitutivo; seconda: EsitoCostitutivo | null };
  persone: Persona[];
  destinatari: Destinatario[];
  dichiarazioni: Dichiarazione[];
  assenti: Assente[];
  scadenzaPresenti: string | null;
  controlli: Controllo[];
  opzioni: OpzioniAssemblea;
  iaDisponibile: boolean;
}>();

const { generatePath, generateRoute } = usePermission();
const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash?.message);
const erroreStato = computed(() => ((page.props.errors ?? {}) as Record<string, string>).stato);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, component: 'condominio-dropdown' } as any,
  { title: 'assemblee', href: generatePath('gestionale/:condominio/assemblee', { condominio: props.condominio.id }) },
  { title: props.assemblea.titolo_leggibile, href: '#' },
]);

const schedaIniziale = () => {
  if (props.assemblea.stato === 'bozza') return 'dati';
  if (props.assemblea.stato === 'convocata') return 'convocazione';
  if (props.assemblea.stato === 'svolta') return 'votazioni';
  return 'verbale';
};
const scheda = ref(schedaIniziale());
watch(() => props.assemblea.stato, () => { scheda.value = schedaIniziale(); });

const base = () => ({ condominio: props.condominio.id, assemblea: props.assemblea.id });

const etichettaTransizione: Record<string, string> = {
  bozza: 'Riporta in bozza',
  convocata: 'Segna come convocata',
  svolta: 'Segna come svolta',
};

const cambiaStato = (stato: string) => {
  const conferme: Record<string, string> = {
    convocata: 'Confermi che l\'avviso è stato inviato? L\'assemblea comparirà ai condòmini e in agenda.',
    svolta: 'Confermi che l\'assemblea si è svolta? Si passa alla redazione del verbale.',
    bozza: 'Riportare l\'assemblea in bozza? Sparirà dal portale dei condòmini e dall\'agenda.',
  };
  if (!confirm(conferme[stato] ?? 'Confermi?')) return;
  router.put(route(...generateRoute('gestionale.assemblee.stato', base())), { stato }, { preserveScroll: true });
};

const elimina = () => {
  if (!confirm('Eliminare l\'assemblea in bozza?')) return;
  router.delete(route(...generateRoute('gestionale.assemblee.destroy', base())));
};

const errori = computed(() => props.controlli.filter((c) => c.livello === 'error').length);
</script>

<template>
  <Head :title="assemblea.titolo_leggibile" />

  <GestionaleLayout :breadcrumbs="breadcrumbs">
    <template #breadcrumb-condominio>
      <CondominioDropdown :condominio="props.condominio" :condomini="props.condomini" />
    </template>

    <div class="px-4 py-6 space-y-4">
      <div v-if="flashMessage" class="py-1">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>
      <div v-if="erroreStato" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
        {{ erroreStato }}
      </div>

      <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
        <div class="space-y-1">
          <div class="flex flex-wrap items-center gap-2">
            <h1 class="text-xl font-semibold tracking-tight">{{ assemblea.titolo_leggibile }}</h1>
            <Badge variant="outline" :class="classeStato[assemblea.stato]">{{ assemblea.stato_label }}</Badge>
            <Badge v-if="assemblea.numero_registro" variant="outline">Verbale n. {{ assemblea.numero_registro }}</Badge>
          </div>
          <p class="text-sm text-muted-foreground">
            1ª convocazione {{ dataOra(assemblea.prima_convocazione_at) }}
            <template v-if="assemblea.seconda_convocazione_at"> · 2ª convocazione {{ dataOra(assemblea.seconda_convocazione_at) }}</template>
            <template v-if="errori"> · <span class="text-red-600">{{ errori }} problemi da correggere</span></template>
          </p>
        </div>
        <div class="flex flex-wrap gap-2">
          <Button
            v-for="t in assemblea.transizioni"
            :key="t.value"
            size="sm"
            :variant="t.value === 'bozza' || t.value === 'convocata' && assemblea.stato === 'svolta' ? 'outline' : 'default'"
            @click="cambiaStato(t.value)"
          >
            <ArrowRight class="h-4 w-4" /> {{ etichettaTransizione[t.value] ?? t.label }}
          </Button>
          <Button v-if="assemblea.stato === 'bozza'" size="sm" variant="outline" @click="elimina">
            <Trash2 class="h-4 w-4 text-red-600" /> Elimina
          </Button>
          <Link
            :href="generatePath('gestionale/:condominio/assemblee', { condominio: props.condominio.id })"
            class="inline-flex h-8 items-center justify-center gap-2 rounded-md bg-primary px-3 text-sm font-medium text-white hover:bg-primary/90"
          >
            <List class="h-4 w-4" /> Assemblee
          </Link>
        </div>
      </div>

      <div class="border border-slate-200 dark:border-slate-800 rounded-2xl bg-white dark:bg-slate-950 shadow-sm p-4">
        <Tabs v-model="scheda" class="w-full">
          <TabsList class="flex h-auto flex-wrap justify-start">
            <TabsTrigger value="dati">Dati e ordine del giorno</TabsTrigger>
            <TabsTrigger value="convocazione">Convocazione</TabsTrigger>
            <TabsTrigger value="presenze">Presenze e deleghe</TabsTrigger>
            <TabsTrigger value="votazioni">Votazioni</TabsTrigger>
            <TabsTrigger value="verbale">Verbale</TabsTrigger>
          </TabsList>

          <TabsContent value="dati" class="pt-4">
            <TabDati :condominio-id="condominio.id" :assemblea="assemblea" :punti="punti" :opzioni="opzioni" :ia-disponibile="iaDisponibile" />
          </TabsContent>

          <TabsContent value="convocazione" class="pt-4">
            <TabConvocazione :condominio-id="condominio.id" :assemblea="assemblea" :destinatari="destinatari" :controlli="controlli" :opzioni="opzioni" />
          </TabsContent>

          <TabsContent value="presenze" class="pt-4">
            <TabPresenze
              :condominio-id="condominio.id"
              :assemblea="assemblea"
              :persone="persone"
              :costitutivo="costitutivo"
              :unita="unita"
              :totale-millesimi="totaleMillesimi"
              :opzioni="opzioni"
            />
          </TabsContent>

          <TabsContent value="votazioni" class="pt-4 space-y-4">
            <p class="text-sm text-muted-foreground">
              Si delibera in <strong>{{ assemblea.convocazione_effettiva === 'seconda' ? 'seconda' : 'prima' }} convocazione</strong>.
              La presenza si registra punto per punto: chi esce prima del voto non conta fra gli intervenuti di quel punto.
              Il conflitto d'interessi non toglie il voto: il programma fa la prova di resistenza.
            </p>
            <div v-if="assemblea.stato === 'bozza'" class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">Le votazioni si registrano dopo la convocazione.</div>
            <div v-if="punti.length === 0" class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">Nessun punto all'ordine del giorno.</div>
            <VotazionePunto
              v-for="(p, i) in punti"
              :key="p.id"
              :condominio-id="condominio.id"
              :assemblea-id="assemblea.id"
              :punto="p"
              :numero="i + 1"
              :modificabile="assemblea.presenze_modificabili"
            />
          </TabsContent>

          <TabsContent value="verbale" class="pt-4">
            <TabVerbale
              :condominio-id="condominio.id"
              :assemblea="assemblea"
              :punti="punti"
              :persone="persone"
              :dichiarazioni="dichiarazioni"
              :assenti="assenti"
              :scadenza-presenti="scadenzaPresenti"
              :opzioni="opzioni"
              :ia-disponibile="iaDisponibile"
            />
          </TabsContent>
        </Tabs>
      </div>
    </div>
  </GestionaleLayout>
</template>
