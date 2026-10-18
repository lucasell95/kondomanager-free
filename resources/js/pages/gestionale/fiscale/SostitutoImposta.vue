<script setup lang="ts">
/**
 * CU e 770: il condominio come sostituto d'imposta, anno solare per anno solare.
 *
 * Risponde a tre domande, in quest'ordine:
 *  1. **c'è qualcosa che non torna?** — anomalie anagrafiche, ritenute non versate o versate in
 *     ritardo: sono le uniche cose che costano sanzioni, quindi stanno in cima;
 *  2. **cosa scrivo nella CU di ciascun fornitore?** — i punti della sezione lavoro autonomo;
 *  3. **cosa scrivo nel 770?** — le righe del quadro ST (ed eventuale SX).
 *
 * Qui non si fa aritmetica: tutti i numeri arrivano dal server (`ProspettoSostitutoService`),
 * in centesimi.
 */
import { computed, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import MovimentiLayout from '@/layouts/gestionale/MovimentiLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import Alert from '@/components/Alert.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import {
    AlertTriangle, CalendarClock, CheckCircle2, ChevronDown, ChevronRight, Download, FileSpreadsheet,
    FileText, Landmark, Printer, Receipt, UserCheck,
} from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import type { Building } from '@/types/buildings';
import type { Flash } from '@/types/flash';

type Riga = Record<string, any>;

interface Prospetto {
    anno: number;
    generato_il: string;
    sostituto: Riga;
    percipienti: Riga[];
    forfetari: Riga[];
    quadratura: { righe: Riga[]; differenze: Riga[]; ravvedimenti: Riga[]; da_versare: Riga[]; eccedenze: Riga[]; in_quadratura: boolean };
    st: Riga[];
    sx: Riga[];
    totali: Record<string, number>;
    scadenze: { adempimento: string; data: string; nota: string }[];
    avvertenze: string[];
}

const props = defineProps<{
    condominio: Building;
    condomini: Building[];
    esercizio: { id: number; nome: string } | null;
    anno: number;
    anni: number[];
    prospetto: Prospetto;
    codici_carica: { value: string; label: string }[];
    codice_sanzione_suggerito: string | null;
}>();

const { generatePath, generateRoute } = usePermission();
const { euro } = useCurrencyFormatter();
const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const p = computed(() => props.prospetto);
const sost = computed(() => p.value.sostituto);

const headerBreadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Movimenti' },
    { title: 'CU e 770' },
]);

const pageGuides = [
    { title: 'Anno dei pagamenti', description: 'Conta la data del pagamento, non della fattura: una fattura di dicembre pagata a gennaio va nella CU dell\'anno dopo.', icon: CalendarClock, colorVariant: 'blue' as const },
    { title: 'CU per fornitore', description: 'Per ogni percipiente i punti 1-10 della sezione lavoro autonomo, e la copia sintetica in PDF da consegnargli.', icon: Receipt, colorVariant: 'amber' as const },
    { title: 'Quadro ST', description: 'Le righe del 770 vengono dalle deleghe F24 versate: ritenute operate, interessi, data e codice tributo.', icon: Landmark, colorVariant: 'slate' as const },
];

const dataIt = (d: string | null | undefined) =>
    d ? new Date(`${String(d).slice(0, 10)}T00:00:00`).toLocaleDateString('it-IT') : '—';

const rotta = (nome: string, extra: Record<string, unknown> = {}) =>
    route(generateRoute(nome), { condominio: props.condominio.id, ...extra } as any);

const cambiaAnno = (e: Event) => {
    const anno = (e.target as HTMLSelectElement).value;
    router.get(rotta('gestionale.fiscale.cu770.index'), { anno }, { preserveScroll: true });
};

const sezioneCsv = ref('percipienti');
const urlCsv = computed(() => `${rotta('gestionale.fiscale.cu770.export', { anno: props.anno, formato: 'csv' })}?sezione=${sezioneCsv.value}`);

// ── Sostituto e firmatario ──
const firm = sost.value.firmatario ?? {};
const formSostituto = useForm({
    firmatario_cognome: firm.cognome ?? '',
    firmatario_nome: firm.nome ?? '',
    firmatario_codice_fiscale: firm.codice_fiscale ?? '',
    firmatario_codice_carica: firm.codice_carica ?? '13',
    firmatario_data_carica: firm.data_carica ?? '',
    sostituto_telefono: sost.value.telefono ?? '',
});
const salvaSostituto = () => formSostituto.put(rotta('gestionale.fiscale.sostituto.update'), { preserveScroll: true });

// ── Dettaglio pagamenti per percipiente ──
const aperti = ref<Record<string, boolean>>({});
const apri = (k: string) => { aperti.value[k] = !aperti.value[k]; };

const statoLabel: Record<string, string> = {
    regolare: 'Versata nei termini',
    tardivo: 'Versata in ritardo',
    omesso: 'Non versata, scaduta',
    da_versare: 'Da versare',
    eccedenza: 'Versato in eccesso',
};
const statoClasse: Record<string, string> = {
    regolare: 'text-emerald-700 bg-emerald-50 border-emerald-200',
    tardivo: 'text-amber-800 bg-amber-50 border-amber-200',
    omesso: 'text-rose-700 bg-rose-50 border-rose-200',
    da_versare: 'text-slate-700 bg-slate-50 border-slate-200',
    eccedenza: 'text-violet-700 bg-violet-50 border-violet-200',
};

const nomePercipiente = (a: Riga) =>
    a.persona_fisica && a.cognome ? `${a.cognome} ${a.nome ?? ''}`.trim() : (a.denominazione ?? '—');

const anomalieTotali = computed(() =>
    sost.value.anomalie.length + p.value.percipienti.reduce((n, x) => n + x.anomalie.length, 0)
);

// ── Ravvedimento ──
const formRavv = useForm({
    riga_f24_id: null as number | null,
    interessi: '',
    sanzione: '',
    codice_tributo_sanzione: props.codice_sanzione_suggerito ?? '',
    data_versamento: '',
    note: '',
});
const ravvAperto = ref<number | null>(null);
const preparaRavv = (r: Riga) => {
    ravvAperto.value = r.pagamento_id;
    formRavv.riga_f24_id = r.riga_f24_id;
    const reg = r.ravvedimento_registrato;
    const cifra = (c: number | undefined) => c ? (c / 100).toFixed(2).replace('.', ',') : '';
    formRavv.interessi = reg ? cifra(reg.interessi) : cifra(r.stima?.interessi);
    formRavv.sanzione = reg ? cifra(reg.sanzione) : cifra(r.stima?.sanzione);
    formRavv.data_versamento = reg?.data_versamento ?? r.data_versamento ?? '';
};
const salvaRavv = () => formRavv.post(rotta('gestionale.fiscale.ravvedimenti.store'), {
    preserveScroll: true,
    onSuccess: () => { ravvAperto.value = null; },
});
const eliminaRavv = (id: number) => router.delete(rotta('gestionale.fiscale.ravvedimenti.destroy', { ravvedimentoId: id }), { preserveScroll: true });
</script>

<template>
    <Head title="CU e 770" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-3">
            <PageHeaderGuide
                page-title="CU e 770"
                page-subtitle="Il condominio come sostituto d'imposta: dati per la Certificazione Unica di ogni fornitore e per il quadro ST del 770."
                :guides="pageGuides"
                :breadcrumbs="(headerBreadcrumbs as any)"
                :condominio="(props.condominio as any)"
                :condomini="(props.condomini as any)"
            />

            <MovimentiLayout>
                <Alert v-if="flashMessage" :message="flashMessage.message" :type="flashMessage.type" class="mb-4" />

                <!-- Barra: anno e stampe -->
                <div class="flex flex-wrap items-end gap-3 mb-5">
                    <div>
                        <Label for="anno" class="text-xs text-slate-500">Anno dei pagamenti</Label>
                        <select id="anno" :value="props.anno" class="mt-1 h-9 rounded-md border border-input bg-white px-2 text-sm" @change="cambiaAnno">
                            <option v-for="a in props.anni" :key="a" :value="a">{{ a }}</option>
                        </select>
                    </div>
                    <div class="text-xs text-slate-500 pb-2">
                        CU {{ props.anno + 1 }} e 770/{{ props.anno + 1 }}
                    </div>
                    <div class="flex-1" />
                    <a :href="rotta('gestionale.fiscale.cu770.pdf', { anno: props.anno })" target="_blank">
                        <Button variant="outline" class="h-9 gap-2"><Printer class="w-4 h-4" /> Prospetto PDF</Button>
                    </a>
                    <a :href="rotta('gestionale.fiscale.cu770.export', { anno: props.anno, formato: 'xlsx' })">
                        <Button variant="outline" class="h-9 gap-2"><FileSpreadsheet class="w-4 h-4" /> XLSX</Button>
                    </a>
                    <div class="flex items-center gap-1">
                        <select v-model="sezioneCsv" class="h-9 rounded-md border border-input bg-white px-2 text-sm" aria-label="Sezione CSV">
                            <option value="percipienti">Percipienti</option>
                            <option value="pagamenti">Pagamenti</option>
                            <option value="quadratura">Quadratura</option>
                            <option value="st">Quadro ST</option>
                        </select>
                        <a :href="urlCsv"><Button variant="outline" class="h-9 gap-2"><Download class="w-4 h-4" /> CSV</Button></a>
                    </div>
                </div>

                <!-- Riepilogo -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
                    <div class="border rounded-xl p-3 bg-white">
                        <p class="text-[11px] uppercase tracking-wider text-slate-500">Percipienti</p>
                        <p class="text-xl font-black">{{ p.totali.percipienti }}</p>
                    </div>
                    <div class="border rounded-xl p-3 bg-white">
                        <p class="text-[11px] uppercase tracking-wider text-slate-500">Ritenute operate</p>
                        <p class="text-xl font-black">{{ euro(p.totali.ritenute_operate) }}</p>
                    </div>
                    <div class="border rounded-xl p-3 bg-white">
                        <p class="text-[11px] uppercase tracking-wider text-slate-500">Ritenute versate</p>
                        <p class="text-xl font-black">{{ euro(p.totali.ritenute_versate) }}</p>
                    </div>
                    <div class="border rounded-xl p-3" :class="p.quadratura.in_quadratura && anomalieTotali === 0 ? 'bg-emerald-50 border-emerald-200' : 'bg-rose-50 border-rose-200'">
                        <p class="text-[11px] uppercase tracking-wider" :class="p.quadratura.in_quadratura && anomalieTotali === 0 ? 'text-emerald-700' : 'text-rose-700'">Controlli</p>
                        <p class="text-sm font-semibold flex items-center gap-1.5 mt-1">
                            <CheckCircle2 v-if="p.quadratura.in_quadratura && anomalieTotali === 0" class="w-4 h-4 text-emerald-600" />
                            <AlertTriangle v-else class="w-4 h-4 text-rose-600" />
                            <span v-if="p.quadratura.in_quadratura && anomalieTotali === 0">Tutto torna</span>
                            <span v-else>{{ p.quadratura.differenze.length }} differenze, {{ p.quadratura.ravvedimenti.length }} ravvedimenti, {{ anomalieTotali }} anomalie</span>
                        </p>
                    </div>
                </div>

                <!-- Sostituto e firmatario -->
                <section class="border rounded-xl p-4 bg-slate-50/60 mb-5">
                    <h2 class="text-sm font-semibold flex items-center gap-2 mb-1"><UserCheck class="w-4 h-4" /> Sostituto d'imposta e firmatario</h2>
                    <p class="text-xs text-slate-600 mb-3">
                        {{ sost.denominazione }} — C.F. <span class="font-mono">{{ sost.codice_fiscale || '—' }}</span>
                        — {{ sost.indirizzo }} {{ sost.cap }} {{ sost.comune }} {{ sost.provincia ? `(${sost.provincia})` : '' }}.
                        Codice fiscale e indirizzo si cambiano dalla scheda del condominio.
                    </p>
                    <form class="grid grid-cols-1 sm:grid-cols-6 gap-3" @submit.prevent="salvaSostituto">
                        <div class="sm:col-span-2">
                            <Label for="f_cognome" class="text-xs">Cognome del firmatario</Label>
                            <Input id="f_cognome" v-model="formSostituto.firmatario_cognome" class="mt-1 bg-white" />
                        </div>
                        <div class="sm:col-span-2">
                            <Label for="f_nome" class="text-xs">Nome</Label>
                            <Input id="f_nome" v-model="formSostituto.firmatario_nome" class="mt-1 bg-white" />
                        </div>
                        <div class="sm:col-span-2">
                            <Label for="f_cf" class="text-xs">Codice fiscale</Label>
                            <Input id="f_cf" v-model="formSostituto.firmatario_codice_fiscale" maxlength="16" class="mt-1 bg-white uppercase font-mono" />
                            <InputError :message="formSostituto.errors.firmatario_codice_fiscale" />
                        </div>
                        <div class="sm:col-span-2">
                            <Label for="f_carica" class="text-xs">Codice carica (da verificare sulle istruzioni)</Label>
                            <select id="f_carica" v-model="formSostituto.firmatario_codice_carica" class="mt-1 h-9 w-full rounded-md border border-input bg-white px-2 text-sm">
                                <option value="">—</option>
                                <option v-for="c in props.codici_carica" :key="c.value" :value="c.value">{{ c.label }}</option>
                            </select>
                            <InputError :message="formSostituto.errors.firmatario_codice_carica" />
                        </div>
                        <div class="sm:col-span-2">
                            <Label for="f_data" class="text-xs">In carica dal</Label>
                            <Input id="f_data" v-model="formSostituto.firmatario_data_carica" type="date" class="mt-1 bg-white" />
                        </div>
                        <div class="sm:col-span-1">
                            <Label for="f_tel" class="text-xs">Telefono del sostituto</Label>
                            <Input id="f_tel" v-model="formSostituto.sostituto_telefono" class="mt-1 bg-white" />
                        </div>
                        <div class="sm:col-span-1 flex items-end">
                            <Button type="submit" class="h-9 w-full" :disabled="formSostituto.processing">Salva</Button>
                        </div>
                    </form>
                    <ul v-if="sost.anomalie.length" class="mt-3 space-y-1">
                        <li v-for="a in sost.anomalie" :key="a" class="text-xs text-rose-700 flex gap-1.5"><AlertTriangle class="w-3.5 h-3.5 shrink-0" /> {{ a }}</li>
                    </ul>
                </section>

                <!-- Percipienti -->
                <section class="mb-6">
                    <h2 class="text-sm font-semibold mb-2">Percipienti — dati per la Certificazione Unica (sezione lavoro autonomo)</h2>
                    <div class="overflow-x-auto border rounded-xl">
                        <table class="w-full text-xs">
                            <thead class="bg-slate-50 text-slate-600">
                                <tr>
                                    <th class="p-2 text-left">Percipiente</th>
                                    <th class="p-2">Causale (1)</th>
                                    <th class="p-2 text-right">Lordo (4)</th>
                                    <th class="p-2">Cod. (6)</th>
                                    <th class="p-2 text-right">Non soggette (7)</th>
                                    <th class="p-2 text-right">Imponibile (8)</th>
                                    <th class="p-2 text-right">Ritenute (9/10)</th>
                                    <th class="p-2 text-right">Versate</th>
                                    <th class="p-2 text-right">Differenza</th>
                                    <th class="p-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!p.percipienti.length"><td colspan="10" class="p-4 text-center text-slate-500">Nessun pagamento con ritenuta nel {{ props.anno }}.</td></tr>
                                <template v-for="x in p.percipienti" :key="x.chiave">
                                    <tr class="border-t align-top">
                                        <td class="p-2">
                                            <button type="button" class="flex items-start gap-1 text-left font-semibold" @click="apri(x.chiave)">
                                                <component :is="aperti[x.chiave] ? ChevronDown : ChevronRight" class="w-3.5 h-3.5 mt-0.5 shrink-0" />
                                                {{ nomePercipiente(x.anagrafica) }}
                                            </button>
                                            <div class="pl-5 text-slate-500 font-mono">{{ x.anagrafica.codice_fiscale || '—' }}</div>
                                            <div v-if="x.anomalie.length" class="pl-5 text-rose-700 flex items-center gap-1"><AlertTriangle class="w-3 h-3" /> {{ x.anomalie.length }} da sistemare</div>
                                        </td>
                                        <td class="p-2 text-center font-bold" :title="x.causale_descrizione">{{ x.punti['1'] ?? '—' }}</td>
                                        <td class="p-2 text-right">{{ euro(x.punti['4']) }}</td>
                                        <td class="p-2 text-center">{{ x.punti['6'] ?? '' }}</td>
                                        <td class="p-2 text-right">{{ euro(x.punti['7']) }}</td>
                                        <td class="p-2 text-right">{{ euro(x.punti['8']) }}</td>
                                        <td class="p-2 text-right">{{ euro(x.punti['9'] + x.punti['10']) }}</td>
                                        <td class="p-2 text-right">{{ euro(x.ritenute_versate) }}</td>
                                        <td class="p-2 text-right" :class="x.differenza !== 0 ? 'text-rose-700 font-bold' : ''">{{ euro(x.differenza) }}</td>
                                        <td class="p-2 text-right">
                                            <a :href="rotta('gestionale.fiscale.cu770.cu', { anno: props.anno, fornitoreId: x.fornitore_id })" target="_blank" class="inline-flex items-center gap-1 text-blue-700 hover:underline whitespace-nowrap">
                                                <FileText class="w-3.5 h-3.5" /> CU sintetica
                                            </a>
                                        </td>
                                    </tr>
                                    <tr v-if="aperti[x.chiave]" class="bg-slate-50/60">
                                        <td colspan="10" class="p-3">
                                            <ul v-if="x.anomalie.length" class="mb-2 space-y-0.5">
                                                <li v-for="a in x.anomalie" :key="a" class="text-rose-700">• {{ a }}</li>
                                            </ul>
                                            <p v-if="x.anagrafica.suggeriti_da_cf" class="mb-2 text-slate-600">
                                                Dal codice fiscale: sesso {{ x.anagrafica.suggeriti_da_cf.sesso }}, nato/a il {{ dataIt(x.anagrafica.suggeriti_da_cf.data_nascita) }}
                                                <span v-if="x.anagrafica.suggeriti_da_cf.comune">a {{ x.anagrafica.suggeriti_da_cf.comune.nome }} ({{ x.anagrafica.suggeriti_da_cf.comune.provincia }})</span>
                                                (codice {{ x.anagrafica.suggeriti_da_cf.codice_catastale }}).
                                            </p>
                                            <table class="w-full">
                                                <thead class="text-slate-500">
                                                    <tr>
                                                        <th class="p-1 text-left">Pagato il</th>
                                                        <th class="p-1 text-left">Fatture</th>
                                                        <th class="p-1 text-right">Lordo con IVA</th>
                                                        <th class="p-1 text-right">Compenso</th>
                                                        <th class="p-1 text-right">Non soggette</th>
                                                        <th class="p-1 text-right">Imponibile</th>
                                                        <th class="p-1 text-right">Ritenuta</th>
                                                        <th class="p-1">Cod. trib.</th>
                                                        <th class="p-1">Scadenza</th>
                                                        <th class="p-1">Versata il</th>
                                                        <th class="p-1">Stato</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr v-for="r in x.pagamenti" :key="r.id" class="border-t">
                                                        <td class="p-1">{{ dataIt(r.data_pagamento) }}</td>
                                                        <td class="p-1">{{ r.fatture.map((f: Riga) => f.numero).join(', ') }}</td>
                                                        <td class="p-1 text-right">{{ euro(r.lordo_con_iva) }}</td>
                                                        <td class="p-1 text-right">{{ euro(r.compenso) }}</td>
                                                        <td class="p-1 text-right">{{ euro(r.non_soggette_totale) }}</td>
                                                        <td class="p-1 text-right">{{ euro(r.imponibile) }}</td>
                                                        <td class="p-1 text-right">{{ euro(r.ritenuta) }}</td>
                                                        <td class="p-1 text-center">{{ r.codice_tributo }}<span v-if="r.codice_tributo_assunto" title="Natura del percipiente non indicata">*</span></td>
                                                        <td class="p-1">{{ dataIt(r.scadenza_legale) }}</td>
                                                        <td class="p-1">{{ dataIt(r.data_versamento) }}</td>
                                                        <td class="p-1">
                                                            <span class="px-1.5 py-0.5 rounded border text-[10px]" :class="statoClasse[r.stato_versamento]">
                                                                {{ statoLabel[r.stato_versamento] }}<template v-if="r.giorni_ritardo"> · {{ r.giorni_ritardo }} gg</template>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- Quadratura -->
                <section class="mb-6">
                    <h2 class="text-sm font-semibold mb-2">Controllo: ritenute operate e versate, per mese e codice tributo</h2>
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <div class="overflow-x-auto border rounded-xl">
                            <table class="w-full text-xs">
                                <thead class="bg-slate-50 text-slate-600">
                                    <tr><th class="p-2 text-left">Periodo</th><th class="p-2">Codice</th><th class="p-2 text-right">Operate</th><th class="p-2 text-right">Versate</th><th class="p-2 text-right">Differenza</th></tr>
                                </thead>
                                <tbody>
                                    <tr v-if="!p.quadratura.righe.length"><td colspan="5" class="p-3 text-center text-slate-500">Nessuna ritenuta.</td></tr>
                                    <tr v-for="c in p.quadratura.righe" :key="c.mese + c.codice_tributo" class="border-t">
                                        <td class="p-2">{{ c.periodo }}</td>
                                        <td class="p-2 text-center">{{ c.codice_tributo }}</td>
                                        <td class="p-2 text-right">{{ euro(c.operate) }}</td>
                                        <td class="p-2 text-right">{{ euro(c.versate) }}</td>
                                        <td class="p-2 text-right" :class="c.differenza !== 0 ? 'text-rose-700 font-bold' : 'text-emerald-700'">{{ euro(c.differenza) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="space-y-3">
                            <div v-if="p.quadratura.da_versare.length" class="border rounded-xl p-3 bg-white text-xs">
                                <p class="font-semibold mb-1">Ancora da versare, nei termini</p>
                                <p v-for="d in p.quadratura.da_versare" :key="d.pagamento_id">
                                    {{ euro(d.importo) }} (cod. {{ d.codice_tributo }}, pagamento del {{ dataIt(d.data_pagamento) }}) entro il {{ dataIt(d.scadenza_legale) }}
                                </p>
                            </div>
                            <div v-if="p.quadratura.eccedenze.length" class="border rounded-xl p-3 bg-violet-50 border-violet-200 text-xs">
                                <p class="font-semibold mb-1">Versamenti senza ritenuta operata</p>
                                <p v-for="x in p.quadratura.eccedenze" :key="x.pagamento_id + x.codice_tributo">{{ euro(x.importo) }} cod. {{ x.codice_tributo }} — {{ x.motivo }}</p>
                            </div>
                            <p v-if="p.quadratura.in_quadratura" class="text-xs text-emerald-700 flex items-center gap-1.5"><CheckCircle2 class="w-4 h-4" /> Ritenute operate e versate coincidono, nei termini.</p>
                        </div>
                    </div>

                    <div v-if="p.quadratura.ravvedimenti.length" class="mt-4 border border-rose-200 rounded-xl p-3 bg-rose-50/50">
                        <p class="text-xs font-semibold text-rose-800 mb-1">Ravvedimenti da valutare</p>
                        <p class="text-[11px] text-rose-700 mb-2">Interessi e sanzione sono una <strong>stima indicativa</strong> (percentuali da verificare). Dopo aver versato, annota qui interessi e sanzione: gli interessi entrano nel quadro ST.</p>
                        <table class="w-full text-xs">
                            <thead class="text-slate-600">
                                <tr><th class="p-1 text-left">Pagamento</th><th class="p-1">Cod.</th><th class="p-1 text-right">Ritenuta</th><th class="p-1">Scadenza</th><th class="p-1">Versata il</th><th class="p-1 text-right">Giorni</th><th class="p-1 text-right">Interessi</th><th class="p-1 text-right">Sanzione</th><th class="p-1"></th></tr>
                            </thead>
                            <tbody>
                                <template v-for="r in p.quadratura.ravvedimenti" :key="r.pagamento_id">
                                    <tr class="border-t">
                                        <td class="p-1">{{ dataIt(r.data_pagamento) }}</td>
                                        <td class="p-1 text-center">{{ r.codice_tributo }}</td>
                                        <td class="p-1 text-right">{{ euro(r.ritenuta) }}</td>
                                        <td class="p-1">{{ dataIt(r.scadenza_legale) }}</td>
                                        <td class="p-1">{{ r.stato === 'omesso' ? 'non versata' : dataIt(r.data_versamento) }}</td>
                                        <td class="p-1 text-right">{{ r.giorni_ritardo }}</td>
                                        <td class="p-1 text-right">{{ euro(r.ravvedimento_registrato?.interessi ?? r.stima?.interessi ?? 0) }}<span v-if="!r.ravvedimento_registrato"> (stima)</span></td>
                                        <td class="p-1 text-right">{{ euro(r.ravvedimento_registrato?.sanzione ?? r.stima?.sanzione ?? 0) }}<span v-if="!r.ravvedimento_registrato"> (stima)</span></td>
                                        <td class="p-1 text-right whitespace-nowrap">
                                            <template v-if="r.riga_f24_id">
                                                <Button size="sm" variant="outline" class="h-7" @click="preparaRavv(r)">{{ r.ravvedimento_registrato ? 'Modifica' : 'Annota' }}</Button>
                                                <Button v-if="r.ravvedimento_registrato" size="sm" variant="ghost" class="h-7 text-rose-700" @click="eliminaRavv(r.ravvedimento_registrato.id)">Elimina</Button>
                                            </template>
                                            <span v-else class="text-[11px] text-slate-500">da versare con F24</span>
                                        </td>
                                    </tr>
                                    <tr v-if="ravvAperto === r.pagamento_id">
                                        <td colspan="9" class="p-2 bg-white">
                                            <form class="grid grid-cols-2 sm:grid-cols-6 gap-2 items-end" @submit.prevent="salvaRavv">
                                                <div><Label class="text-[11px]">Interessi (€)</Label><Input v-model="formRavv.interessi" class="h-8" /></div>
                                                <div><Label class="text-[11px]">Sanzione (€)</Label><Input v-model="formRavv.sanzione" class="h-8" /></div>
                                                <div><Label class="text-[11px]">Codice sanzione</Label><Input v-model="formRavv.codice_tributo_sanzione" maxlength="4" class="h-8 font-mono" /></div>
                                                <div><Label class="text-[11px]">Versati il</Label><Input v-model="formRavv.data_versamento" type="date" class="h-8" /></div>
                                                <div><Label class="text-[11px]">Note</Label><Input v-model="formRavv.note" class="h-8" /></div>
                                                <div class="flex gap-1"><Button type="submit" size="sm" class="h-8" :disabled="formRavv.processing">Salva</Button><Button type="button" size="sm" variant="ghost" class="h-8" @click="ravvAperto = null">Annulla</Button></div>
                                            </form>
                                            <InputError :message="formRavv.errors.riga_f24_id || formRavv.errors.data_versamento || formRavv.errors.interessi" />
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- Quadro ST -->
                <section class="mb-6">
                    <h2 class="text-sm font-semibold mb-1">Modello 770 — quadro ST, sezione I (erario)</h2>
                    <p class="text-[11px] text-slate-500 mb-2">Una riga per versamento delle ritenute operate nel {{ props.anno }}, dalle deleghe F24 versate. Colonne per contenuto: la numerazione va verificata sulle istruzioni del modello.</p>
                    <div class="overflow-x-auto border rounded-xl">
                        <table class="w-full text-xs">
                            <thead class="bg-slate-50 text-slate-600">
                                <tr><th class="p-2 text-left">Periodo di riferimento</th><th class="p-2 text-right">Ritenute operate</th><th class="p-2 text-right">Interessi</th><th class="p-2">Ravvedimento</th><th class="p-2 text-right">Importo versato</th><th class="p-2">Codice tributo</th><th class="p-2">Data versamento</th><th class="p-2 text-left">Deleghe</th></tr>
                            </thead>
                            <tbody>
                                <tr v-if="!p.st.length"><td colspan="8" class="p-3 text-center text-slate-500">Nessun versamento registrato per il {{ props.anno }}.</td></tr>
                                <tr v-for="r in p.st" :key="r.periodo + r.codice_tributo + r.data_versamento + r.ravvedimento" class="border-t">
                                    <td class="p-2">{{ r.periodo }}</td>
                                    <td class="p-2 text-right">{{ euro(r.ritenute_operate) }}</td>
                                    <td class="p-2 text-right">{{ euro(r.interessi) }}</td>
                                    <td class="p-2 text-center font-bold">{{ r.ravvedimento ? 'X' : '' }}</td>
                                    <td class="p-2 text-right">{{ euro(r.importo_versato) }}</td>
                                    <td class="p-2 text-center">{{ r.codice_tributo }}</td>
                                    <td class="p-2 text-center">{{ dataIt(r.data_versamento) }}</td>
                                    <td class="p-2">{{ r.deleghe.join(', ') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="p.sx.length" class="mt-3 border rounded-xl p-3 bg-violet-50/60 border-violet-200 text-xs">
                        <p class="font-semibold mb-1">Quadro SX — versamenti in eccesso da valutare</p>
                        <p v-for="x in p.sx" :key="x.periodo + x.codice_tributo + x.data_versamento">
                            {{ x.periodo }} cod. {{ x.codice_tributo }}: versati {{ euro(x.versato) }} su {{ euro(x.operate) }} operate, eccedenza {{ euro(x.eccedenza) }} ({{ dataIt(x.data_versamento) }}).
                        </p>
                        <p class="text-[11px] text-slate-500 mt-1">Può diventare un credito da compensare: da verificare con le istruzioni o con il commercialista.</p>
                    </div>
                </section>

                <!-- Forfetari -->
                <section v-if="p.forfetari.length" class="mb-6">
                    <h2 class="text-sm font-semibold mb-1">Fornitori in regime forfetario (nessuna ritenuta)</h2>
                    <p class="text-[11px] text-slate-500 mb-2">Dal periodo d'imposta 2024 i compensi ai forfetari non vanno certificati in CU (art. 4 c. 6-septies DPR 322/1998), salvo casi residui come le prestazioni sanitarie: da verificare. Elencati per completezza.</p>
                    <div class="overflow-x-auto border rounded-xl">
                        <table class="w-full text-xs">
                            <thead class="bg-slate-50 text-slate-600"><tr><th class="p-2 text-left">Fornitore</th><th class="p-2 text-left">Codice fiscale</th><th class="p-2 text-right">Pagamenti</th><th class="p-2 text-right">Compenso netto IVA</th></tr></thead>
                            <tbody>
                                <tr v-for="f in p.forfetari" :key="f.fornitore_id" class="border-t">
                                    <td class="p-2">{{ f.denominazione }}</td>
                                    <td class="p-2 font-mono">{{ f.codice_fiscale }}</td>
                                    <td class="p-2 text-right">{{ f.pagamenti }}</td>
                                    <td class="p-2 text-right">{{ euro(f.compenso) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- Scadenze e avvertenze -->
                <section class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div class="border rounded-xl p-3 bg-white text-xs">
                        <p class="font-semibold mb-1">Scadenze (da verificare sui provvedimenti dell'anno)</p>
                        <p v-for="s in p.scadenze" :key="s.adempimento"><strong>{{ dataIt(s.data) }}</strong> — {{ s.adempimento }} <span class="text-slate-500">({{ s.nota }})</span></p>
                    </div>
                    <div class="border rounded-xl p-3 bg-amber-50/60 border-amber-200 text-xs">
                        <p class="font-semibold mb-1">Avvertenze</p>
                        <p v-for="a in p.avvertenze" :key="a">• {{ a }}</p>
                    </div>
                </section>
            </MovimentiLayout>
        </div>
    </GestionaleLayout>
</template>
