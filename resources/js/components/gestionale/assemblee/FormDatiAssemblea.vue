<script setup lang="ts">
// Campi dei dati generali dell'assemblea, condivisi da «Nuova assemblea» e dalla scheda.
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import InputError from '@/components/InputError.vue';
import type { OpzioniAssemblea } from '@/types/gestionale/assemblee';

export interface DatiAssembleaForm {
  tipo: string;
  titolo: string;
  esercizio_id: number | string | null;
  tabella_id: number | string | null;
  prima_convocazione_at: string;
  prima_luogo: string;
  seconda_convocazione_at: string;
  seconda_luogo: string;
  videoconferenza: boolean;
  piattaforma_video: string;
  link_video: string;
  consenso_video_acquisito: boolean;
  convocante: string;
  amministratore_anagrafica_id: number | string | null;
  amministratore_nome: string;
  allegati: string;
  note: string;
  errors: Partial<Record<string, string>>;
}

defineProps<{
  form: DatiAssembleaForm;
  opzioni: OpzioniAssemblea;
  disabilitato?: boolean;
}>();

const selectClass = 'mt-1 h-9 w-full rounded-md border border-input bg-background px-3 text-sm disabled:opacity-50';
</script>

<template>
  <fieldset :disabled="disabilitato" class="space-y-6">
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
      <div class="sm:col-span-2">
        <Label for="tipo">Tipo</Label>
        <select id="tipo" v-model="form.tipo" :class="selectClass">
          <option v-for="t in opzioni.tipi" :key="t.value" :value="t.value">{{ t.label }}</option>
        </select>
        <InputError :message="form.errors.tipo" />
      </div>
      <div class="sm:col-span-4">
        <Label for="titolo">Titolo (facoltativo)</Label>
        <Input id="titolo" v-model="form.titolo" class="mt-1" placeholder="Es. Assemblea ordinaria di approvazione del rendiconto" />
        <InputError :message="form.errors.titolo" />
      </div>
      <div class="sm:col-span-3">
        <Label for="esercizio_id">Esercizio</Label>
        <select id="esercizio_id" v-model="form.esercizio_id" :class="selectClass">
          <option :value="null">— esercizio corrente —</option>
          <option v-for="e in opzioni.esercizi" :key="e.id" :value="e.id">{{ e.nome }} ({{ e.stato }})</option>
        </select>
        <InputError :message="form.errors.esercizio_id" />
      </div>
      <div class="sm:col-span-3">
        <Label for="tabella_id">Tabella millesimale per i quorum</Label>
        <select id="tabella_id" v-model="form.tabella_id" :class="selectClass">
          <option :value="null">— proprietà generale (automatica) —</option>
          <option v-for="t in opzioni.tabelle" :key="t.id" :value="t.id">{{ t.nome }}{{ t.attiva ? '' : ' (non attiva)' }}</option>
        </select>
        <InputError :message="form.errors.tabella_id" />
      </div>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
      <div class="sm:col-span-2">
        <Label for="prima_convocazione_at">Prima convocazione</Label>
        <Input id="prima_convocazione_at" v-model="form.prima_convocazione_at" type="datetime-local" class="mt-1" />
        <InputError :message="form.errors.prima_convocazione_at" />
      </div>
      <div class="sm:col-span-4">
        <Label for="prima_luogo">Luogo della prima convocazione</Label>
        <Input id="prima_luogo" v-model="form.prima_luogo" class="mt-1" placeholder="Es. androne del condominio" />
        <InputError :message="form.errors.prima_luogo" />
      </div>
      <div class="sm:col-span-2">
        <Label for="seconda_convocazione_at">Seconda convocazione</Label>
        <Input id="seconda_convocazione_at" v-model="form.seconda_convocazione_at" type="datetime-local" class="mt-1" />
        <p class="mt-1 text-xs text-muted-foreground">In un giorno successivo alla prima ed entro 10 giorni (art. 1136 c. 3 c.c.).</p>
        <InputError :message="form.errors.seconda_convocazione_at" />
      </div>
      <div class="sm:col-span-4">
        <Label for="seconda_luogo">Luogo della seconda convocazione</Label>
        <Input id="seconda_luogo" v-model="form.seconda_luogo" class="mt-1" placeholder="Se vuoto, lo stesso della prima" />
        <InputError :message="form.errors.seconda_luogo" />
      </div>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
      <div class="sm:col-span-6 flex flex-wrap items-center gap-6">
        <label class="flex items-center gap-2 text-sm">
          <input v-model="form.videoconferenza" type="checkbox" class="h-4 w-4" /> Anche in videoconferenza
        </label>
        <label v-if="form.videoconferenza" class="flex items-center gap-2 text-sm">
          <input v-model="form.consenso_video_acquisito" type="checkbox" class="h-4 w-4" />
          Consenso preventivo della maggioranza dei condòmini acquisito (art. 66 c. 6 disp. att.)
        </label>
      </div>
      <template v-if="form.videoconferenza">
        <div class="sm:col-span-2">
          <Label for="piattaforma_video">Piattaforma</Label>
          <Input id="piattaforma_video" v-model="form.piattaforma_video" class="mt-1" />
        </div>
        <div class="sm:col-span-4">
          <Label for="link_video">Collegamento</Label>
          <Input id="link_video" v-model="form.link_video" class="mt-1" />
          <InputError :message="form.errors.link_video" />
        </div>
      </template>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
      <div class="sm:col-span-2">
        <Label for="convocante">Chi convoca</Label>
        <Input id="convocante" v-model="form.convocante" class="mt-1" placeholder="Es. L'amministratore Mario Rossi" />
      </div>
      <div class="sm:col-span-2">
        <Label for="amministratore_anagrafica_id">Amministratore (se condòmino)</Label>
        <select id="amministratore_anagrafica_id" v-model="form.amministratore_anagrafica_id" :class="selectClass">
          <option :value="null">— non è un condòmino / non indicato —</option>
          <option v-for="a in opzioni.anagrafiche" :key="a.id" :value="a.id">{{ a.nome }}</option>
        </select>
        <InputError :message="form.errors.amministratore_anagrafica_id" />
      </div>
      <div class="sm:col-span-2">
        <Label for="amministratore_nome">Nome dell'amministratore</Label>
        <Input id="amministratore_nome" v-model="form.amministratore_nome" class="mt-1" />
        <p class="mt-1 text-xs text-muted-foreground">Serve a bloccare le deleghe all'amministratore (art. 67 c. 5 disp. att.).</p>
      </div>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
      <div class="sm:col-span-3">
        <Label for="allegati">Allegati all'avviso (uno per riga)</Label>
        <Textarea id="allegati" v-model="form.allegati" class="mt-1" rows="3" placeholder="Rendiconto 2025/26&#10;Preventivo 2026/27" />
      </div>
      <div class="sm:col-span-3">
        <Label for="note">Note interne</Label>
        <Textarea id="note" v-model="form.note" class="mt-1" rows="3" />
      </div>
    </div>
  </fieldset>
</template>
