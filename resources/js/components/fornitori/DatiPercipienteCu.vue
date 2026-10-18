<script setup lang="ts">
/**
 * Il riquadro dei dati anagrafici che la Certificazione Unica chiede per un percipiente
 * **persona fisica** (professionista, ditta individuale): cognome e nome separati, sesso, data e
 * luogo di nascita. Per le società bastano denominazione e codice fiscale, già presenti sopra.
 *
 * Il domicilio fiscale è la sede indicata in «Recapiti e sede».
 *
 * Usato identico dalla creazione e dalla modifica del fornitore: riceve il `form` di Inertia e ne
 * scrive i campi.
 */
import { computed } from 'vue';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import InputError from '@/components/InputError.vue';
import { FileCheck2, Wand2 } from 'lucide-vue-next';
import { datiDaCodiceFiscale, isCodiceFiscalePersonaFisica } from '@/lib/gestionale/fiscale/codiceFiscale';

interface FormPercipiente {
    codice_fiscale: string;
    natura_percipiente: string;
    cognome: string;
    nome: string;
    sesso: string;
    data_nascita: string;
    comune_nascita: string;
    provincia_nascita: string;
    codice_catastale_nascita: string;
    errors: Partial<Record<string, string>>;
}

const props = defineProps<{ form: FormPercipiente }>();

const personaFisica = computed(() =>
    isCodiceFiscalePersonaFisica(props.form.codice_fiscale) || props.form.natura_percipiente === 'persona_fisica_irpef'
);

const suggeriti = computed(() => datiDaCodiceFiscale(props.form.codice_fiscale));

const dataIt = (iso: string) => iso ? new Date(`${iso}T00:00:00`).toLocaleDateString('it-IT') : '';

/** Riempie sesso, data e codice catastale dal codice fiscale, senza toccare ciò che è già scritto. */
const ricava = () => {
    const d = suggeriti.value;
    if (!d) return;
    /* eslint-disable vue/no-mutating-props */
    if (!props.form.sesso) props.form.sesso = d.sesso;
    if (!props.form.data_nascita) props.form.data_nascita = d.dataNascita;
    if (!props.form.codice_catastale_nascita) props.form.codice_catastale_nascita = d.codiceCatastale;
    /* eslint-enable vue/no-mutating-props */
};

const nonCoerenti = computed(() => {
    const d = suggeriti.value;
    if (!d) return [] as string[];
    const out: string[] = [];
    if (props.form.sesso && props.form.sesso.toUpperCase() !== d.sesso) out.push(`il sesso (dal codice fiscale: ${d.sesso})`);
    if (props.form.data_nascita && props.form.data_nascita.slice(0, 10) !== d.dataNascita) out.push(`la data di nascita (dal codice fiscale: ${dataIt(d.dataNascita)})`);
    if (props.form.codice_catastale_nascita && props.form.codice_catastale_nascita.toUpperCase() !== d.codiceCatastale) out.push(`il codice catastale (dal codice fiscale: ${d.codiceCatastale})`);
    return out;
});
</script>

<template>
    <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
        <CardHeader class="pb-3 border-b border-dashed mb-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <CardTitle class="text-base font-semibold flex items-center gap-2">
                        <FileCheck2 class="w-4 h-4 text-slate-500" /> Dati per la Certificazione Unica
                    </CardTitle>
                    <CardDescription>
                        Solo per i percipienti persona fisica (professionisti, ditte individuali) a cui il condominio opera la ritenuta.
                        Il domicilio fiscale è la sede indicata sopra.
                    </CardDescription>
                </div>
                <Button v-if="suggeriti" type="button" variant="outline" size="sm" class="gap-1.5 shrink-0" @click="ricava">
                    <Wand2 class="w-3.5 h-3.5" /> Ricava dal codice fiscale
                </Button>
            </div>
        </CardHeader>
        <CardContent class="space-y-4">
            <p v-if="!personaFisica" class="text-xs text-slate-500">
                Il codice fiscale indicato non è quello di una persona fisica: per la CU bastano denominazione e codice fiscale.
                Compila questi campi solo se il fornitore è una ditta individuale con il codice fiscale del titolare.
            </p>
            <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">
                <div class="sm:col-span-3">
                    <Label for="cu_cognome">Cognome</Label>
                    <Input id="cu_cognome" v-model="form.cognome" class="mt-1 bg-white" />
                    <InputError :message="form.errors.cognome" />
                </div>
                <div class="sm:col-span-3">
                    <Label for="cu_nome">Nome</Label>
                    <Input id="cu_nome" v-model="form.nome" class="mt-1 bg-white" />
                    <InputError :message="form.errors.nome" />
                </div>
                <div class="sm:col-span-1">
                    <Label for="cu_sesso">Sesso</Label>
                    <select id="cu_sesso" v-model="form.sesso" class="mt-1 h-9 w-full rounded-md border border-input bg-white px-2 text-sm">
                        <option value="">—</option>
                        <option value="M">M</option>
                        <option value="F">F</option>
                    </select>
                    <InputError :message="form.errors.sesso" />
                </div>
                <div class="sm:col-span-2">
                    <Label for="cu_data_nascita">Data di nascita</Label>
                    <Input id="cu_data_nascita" v-model="form.data_nascita" type="date" class="mt-1 bg-white" />
                    <InputError :message="form.errors.data_nascita" />
                </div>
                <div class="sm:col-span-2">
                    <Label for="cu_comune_nascita">Comune (o Stato estero) di nascita</Label>
                    <Input id="cu_comune_nascita" v-model="form.comune_nascita" class="mt-1 bg-white" />
                    <InputError :message="form.errors.comune_nascita" />
                </div>
                <div class="sm:col-span-1">
                    <Label for="cu_provincia_nascita">Prov.</Label>
                    <Input id="cu_provincia_nascita" v-model="form.provincia_nascita" maxlength="2" class="mt-1 bg-white uppercase" />
                    <InputError :message="form.errors.provincia_nascita" />
                </div>
                <div class="sm:col-span-2">
                    <Label for="cu_catastale">Codice catastale del comune (facoltativo)</Label>
                    <Input id="cu_catastale" v-model="form.codice_catastale_nascita" maxlength="4" class="mt-1 bg-white uppercase font-mono" placeholder="Es. H501" />
                    <InputError :message="form.errors.codice_catastale_nascita" />
                </div>
            </div>
            <p v-if="nonCoerenti.length" class="text-xs text-amber-700">
                Non corrisponde al codice fiscale: {{ nonCoerenti.join('; ') }}. Verifica prima di produrre la CU.
            </p>
        </CardContent>
    </Card>
</template>
