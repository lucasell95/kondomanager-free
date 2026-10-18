<script setup lang="ts">
/**
 * Banca → Movimenti da abbinare.
 *
 * L'elenco dei movimenti importati con filtri; per ognuno «Abbina» apre le proposte (già a giornale,
 * condòmino e rate, fattura da pagare, regola) e le conferme, che passano tutte dai flussi contabili
 * esistenti. «Conferma proposte sicure» applica in blocco solo quelle che il server giudica sicure.
 */
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import MovimentiLayout from '@/layouts/gestionale/MovimentiLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import BancaNav from '@/components/gestionale/banca/BancaNav.vue';
import Alert from '@/components/Alert.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Dialog, DialogScrollContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { usePermission } from '@/composables/permissions';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { ListChecks, Sparkles, Undo2, Loader2, Trash2, Wand2 } from 'lucide-vue-next';
import type { Flash } from '@/types/flash';

interface Movimento {
    id: number; cassa_id: number; cassa: string | null; data_contabile: string; data_valuta: string | null;
    importo: number; divisa: string; descrizione: string | null; controparte: string | null;
    iban_controparte: string | null; riferimento: string | null;
    stato: 'da_abbinare' | 'abbinato' | 'ignorato'; tipo_abbinamento: string | null; note: string | null;
    testo_regola: string;
    scrittura: { id: number; protocollo: string | null; causale: string | null } | null;
}
interface RigaRata {
    quota_id: number; rata_id: number; descrizione: string; gestione: string | null; scadenza: string | null;
    unita: string; residuo: number; emessa: boolean; da_pagare?: number;
}
interface Proposte {
    gia_registrate: { id: number; protocollo: string | null; causale: string | null; data: string; tipo: string }[];
    incasso: null | {
        anagrafica: { id: number; nome: string }; motivo: string; punteggio: number;
        altre_anagrafiche: { id: number; nome: string; punteggio: number; motivo: string }[];
        quote: RigaRata[]; eccedenza: number;
    };
    pagamenti: { fattura_id: number; numero: string | null; data: string | null; fornitore: { id: number; nome: string | null }; residuo: number; importo_esatto: boolean; punteggio: number; motivi: string[] }[];
    regole: { id: number; nome: string; azione: string; conto_id: number | null; conto_nome: string | null; gestione_id: number | null; causale: string | null }[];
    migliore: null | { tipo: string; automatica: boolean };
    fatture_aperte?: { fattura_id: number; numero: string | null; data: string | null; fornitore: { id: number; nome: string | null }; residuo: number }[];
}

const props = defineProps<{
    condominio: any;
    condomini: any[];
    esercizio: any;
    esercizi: any[];
    casse: { id: number; nome: string; tipo: string; iban: string | null }[];
    movimenti: { data: Movimento[]; links?: any[]; current_page: number; last_page: number; total: number; prev_page_url: string | null; next_page_url: string | null };
    conteggi: { da_abbinare: number; abbinato: number; ignorato: number };
    filtri: { stato: string; cassa_id?: number | null; verso?: string | null; data_da?: string | null; data_a?: string | null; search?: string | null };
    capitoli: { id: number; label: string; gestione_id: number | null }[];
    gestioni: { id: number; nome: string; tipo: string }[];
    anagrafiche: { id: number; nome: string }[];
    regole: { id: number; nome: string; testo_contiene: string; verso: string; azione: string; conto: string | null; attiva: boolean }[];
}>();

const { generateRoute, generatePath } = usePermission();
const { euro } = useCurrencyFormatter();
const page = usePage<{ flash: { message?: Flash }; errors: Record<string, string> }>();
const flashMessage = computed(() => page.props.flash?.message);
const erroreAbbinamento = computed(() => page.props.errors?.abbinamento ?? null);
const codiceErrore = computed(() => page.props.errors?.codice ?? null);

const breadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Banca' },
    { title: 'Movimenti da abbinare' },
]);

const guide = [
    { title: 'Proposte', description: 'Per ogni accredito il gestionale propone il condòmino (dall\'IBAN o dal nome già visti, o dal nome in anagrafica) e le rate da coprire, dalla più vecchia. Per gli addebiti, la fattura da pagare o la regola delle commissioni.', icon: Sparkles, colorVariant: 'blue' as const },
    { title: 'Stessa contabilità', description: 'Confermare un abbinamento registra l\'incasso, il pagamento o la spesa con le stesse maschere di sempre: ritenute, F24 e quadrature restano corrette.', icon: ListChecks, colorVariant: 'emerald' as const },
    { title: 'Annullare', description: 'Annullare un abbinamento non cancella la scrittura: se era sbagliata, stornala dal suo registro.', icon: Undo2, colorVariant: 'amber' as const },
];

// ── Filtri ────────────────────────────────────────────────────────────────────────────────
const filtri = reactive({
    stato: props.filtri.stato ?? 'da_abbinare',
    cassa_id: props.filtri.cassa_id ?? '',
    verso: props.filtri.verso ?? '',
    data_da: props.filtri.data_da ?? '',
    data_a: props.filtri.data_a ?? '',
    search: props.filtri.search ?? '',
});

const applicaFiltri = () => {
    const query: Record<string, any> = { condominio: props.condominio.id };
    for (const [k, v] of Object.entries(filtri)) {
        if (v !== '' && v !== null) query[k] = v;
    }
    router.get(route(generateRoute('gestionale.banca.movimenti.index'), query), {}, { preserveState: true, preserveScroll: true });
};

const scegliStato = (stato: string) => {
    filtri.stato = stato;
    applicaFiltri();
};

// ── Selezione e conferma multipla ─────────────────────────────────────────────────────────
const selezionati = ref<number[]>([]);
const tuttiSelezionati = computed(() =>
    props.movimenti.data.filter((m) => m.stato === 'da_abbinare').length > 0
    && props.movimenti.data.filter((m) => m.stato === 'da_abbinare').every((m) => selezionati.value.includes(m.id)));

const selezionaTutti = (e: Event) => {
    selezionati.value = (e.target as HTMLInputElement).checked
        ? props.movimenti.data.filter((m) => m.stato === 'da_abbinare').map((m) => m.id)
        : [];
};

const confermaMultipla = () => {
    if (!selezionati.value.length) return;
    router.post(route(generateRoute('gestionale.banca.movimenti.multipla'), { condominio: props.condominio.id }), { ids: selezionati.value }, {
        preserveScroll: true,
        onSuccess: () => { selezionati.value = []; },
    });
};

// ── Pannello di abbinamento ───────────────────────────────────────────────────────────────
const aperto = ref(false);
const corrente = ref<Movimento | null>(null);
const proposte = ref<Proposte | null>(null);
const caricamento = ref(false);
const invio = ref(false);

const incasso = reactive<{ pagante_id: number | null; righe: RigaRata[]; descrizione: string }>({ pagante_id: null, righe: [], descrizione: '' });
const pagamento = reactive<{ scelte: Record<number, string>; commissioni: string; iban_confermato: boolean; allow_overdraft: boolean; nota_override: string; conferma_duplicato: boolean }>({
    scelte: {}, commissioni: '', iban_confermato: false, allow_overdraft: false, nota_override: '', conferma_duplicato: false,
});
const spesa = reactive<{ conto_id: number | null; gestione_id: number | null; causale: string; crea_regola: boolean; testo_regola: string }>({
    conto_id: null, gestione_id: null, causale: '', crea_regola: false, testo_regola: '',
});
const collega = reactive<{ scrittura_id: string; forza: boolean }>({ scrittura_id: '', forza: false });
const notaIgnora = ref('');

const centesimi = (v: string | number | null | undefined): number => {
    if (v === '' || v === null || v === undefined) return 0;
    const n = Number(String(v).replace(',', '.'));
    return Number.isFinite(n) ? Math.round(n * 100) : 0;
};
const inEuro = (c: number) => (c / 100).toFixed(2);

const apri = async (m: Movimento) => {
    corrente.value = m;
    proposte.value = null;
    aperto.value = true;
    caricamento.value = true;
    incasso.pagante_id = null;
    incasso.righe = [];
    incasso.descrizione = '';
    pagamento.scelte = {};
    pagamento.commissioni = '';
    pagamento.iban_confermato = false;
    pagamento.allow_overdraft = false;
    pagamento.nota_override = '';
    pagamento.conferma_duplicato = false;
    spesa.conto_id = null;
    spesa.gestione_id = props.gestioni.length === 1 ? props.gestioni[0].id : null;
    spesa.causale = '';
    spesa.crea_regola = false;
    spesa.testo_regola = m.testo_regola ?? '';
    collega.scrittura_id = '';
    collega.forza = false;
    notaIgnora.value = '';

    try {
        const r = await axios.get(route(generateRoute('gestionale.banca.movimenti.proposte'), { condominio: props.condominio.id, movimento: m.id }));
        proposte.value = r.data.proposte as Proposte;

        if (proposte.value.incasso) {
            incasso.pagante_id = proposte.value.incasso.anagrafica.id;
            incasso.righe = proposte.value.incasso.quote.map((q) => ({ ...q }));
        }
        if (proposte.value.pagamenti.length) {
            const p = proposte.value.pagamenti[0];
            pagamento.scelte = { [p.fattura_id]: inEuro(Math.min(p.residuo, Math.abs(m.importo))) };
        }
        if (proposte.value.regole.length && proposte.value.regole[0].conto_id) {
            spesa.conto_id = proposte.value.regole[0].conto_id;
            spesa.gestione_id = proposte.value.regole[0].gestione_id;
            spesa.causale = proposte.value.regole[0].causale ?? '';
        }
    } finally {
        caricamento.value = false;
    }
};

const cambiaPagante = async () => {
    if (!corrente.value || !incasso.pagante_id) return;
    const r = await axios.get(route(generateRoute('gestionale.banca.movimenti.rate-aperte'), {
        condominio: props.condominio.id, movimento: corrente.value.id, anagrafica_id: incasso.pagante_id,
    }));
    const proposta: RigaRata[] = r.data.proposta;
    incasso.righe = (r.data.rate as RigaRata[]).map((q) => ({ ...q, da_pagare: proposta.find((p) => p.quota_id === q.quota_id)?.da_pagare ?? 0 }));
};

const totaleRate = computed(() => incasso.righe.reduce((s, r) => s + (r.da_pagare ?? 0), 0));
const eccedenza = computed(() => Math.max(0, (corrente.value?.importo ?? 0) - totaleRate.value));
const incassoValido = computed(() => !!incasso.pagante_id && totaleRate.value > 0 && totaleRate.value <= (corrente.value?.importo ?? 0));

const totalePagamento = computed(() =>
    Object.values(pagamento.scelte).reduce((s, v) => s + centesimi(v), 0) + centesimi(pagamento.commissioni));
const pagamentoValido = computed(() => corrente.value !== null && totalePagamento.value === Math.abs(corrente.value.importo)
    && Object.values(pagamento.scelte).some((v) => centesimi(v) > 0));

const fattureElencabili = computed(() => {
    const viste = new Set<number>();
    const elenco: { fattura_id: number; numero: string | null; data: string | null; fornitore: { id: number; nome: string | null }; residuo: number; motivi?: string[] }[] = [];
    for (const f of [...(proposte.value?.pagamenti ?? []), ...(proposte.value?.fatture_aperte ?? [])]) {
        if (!viste.has(f.fattura_id)) {
            viste.add(f.fattura_id);
            elenco.push(f);
        }
    }
    return elenco;
});

const opzioni = { preserveScroll: true, onSuccess: () => { if (!page.props.errors?.abbinamento) aperto.value = false; } };

const parametri = () => ({ condominio: props.condominio.id, movimento: corrente.value!.id });

const registraIncasso = () => {
    if (!corrente.value || !incassoValido.value) return;
    invio.value = true;
    router.post(route(generateRoute('gestionale.banca.movimenti.incasso'), parametri()), {
        pagante_id: incasso.pagante_id,
        righe: incasso.righe.filter((r) => (r.da_pagare ?? 0) > 0).map((r) => ({ quota_id: r.quota_id, importo: r.da_pagare })),
        eccedenza: eccedenza.value,
        descrizione: incasso.descrizione || null,
    }, { ...opzioni, onFinish: () => { invio.value = false; } });
};

const registraPagamento = () => {
    if (!corrente.value || !pagamentoValido.value) return;
    invio.value = true;
    router.post(route(generateRoute('gestionale.banca.movimenti.pagamento'), parametri()), {
        allocazioni: Object.entries(pagamento.scelte)
            .filter(([, v]) => centesimi(v) > 0)
            .map(([id, v]) => ({ fattura_id: Number(id), importo: centesimi(v) })),
        commissioni: centesimi(pagamento.commissioni),
        iban_confermato_manualmente: pagamento.iban_confermato,
        allow_overdraft: pagamento.allow_overdraft,
        nota_override: pagamento.allow_overdraft ? pagamento.nota_override : null,
        conferma_duplicato_verificato: pagamento.conferma_duplicato,
    }, { ...opzioni, onFinish: () => { invio.value = false; } });
};

const registraSpesa = () => {
    if (!corrente.value || !spesa.conto_id || !spesa.gestione_id) return;
    invio.value = true;
    router.post(route(generateRoute('gestionale.banca.movimenti.regolazione'), parametri()), {
        conto_id: spesa.conto_id,
        gestione_id: spesa.gestione_id,
        causale: spesa.causale || null,
        crea_regola: spesa.crea_regola,
        testo_regola: spesa.crea_regola ? spesa.testo_regola : null,
    }, { ...opzioni, onFinish: () => { invio.value = false; } });
};

const collegaScrittura = (id: number | string, forza = false) => {
    if (!corrente.value || !id) return;
    router.post(route(generateRoute('gestionale.banca.movimenti.collega'), parametri()), { scrittura_id: Number(id), forza }, opzioni);
};

const ignora = () => {
    if (!corrente.value) return;
    router.post(route(generateRoute('gestionale.banca.movimenti.ignora'), parametri()), { nota: notaIgnora.value || null }, opzioni);
};

const scollega = (m: Movimento) => {
    router.post(route(generateRoute('gestionale.banca.movimenti.scollega'), { condominio: props.condominio.id, movimento: m.id }), {}, { preserveScroll: true });
};

watch(codiceErrore, (c) => {
    if (c === 'importo_diverso') collega.forza = true;
});

// ── Regole ────────────────────────────────────────────────────────────────────────────────
const regolaNuova = reactive<{ nome: string; testo_contiene: string; verso: string; azione: string; conto_id: number | null; gestione_id: number | null; causale: string }>({
    nome: '', testo_contiene: '', verso: 'uscita', azione: 'regolazione', conto_id: null, gestione_id: null, causale: '',
});
const mostraRegole = ref(false);

const salvaRegola = () => {
    router.post(route(generateRoute('gestionale.banca.regole.store'), { condominio: props.condominio.id }), { ...regolaNuova }, {
        preserveScroll: true,
        onSuccess: () => { regolaNuova.nome = ''; regolaNuova.testo_contiene = ''; regolaNuova.causale = ''; },
    });
};

const eliminaRegola = (id: number) => {
    router.delete(route(generateRoute('gestionale.banca.regole.destroy'), { condominio: props.condominio.id, regola: id }), { preserveScroll: true });
};

// ── Presentazione ─────────────────────────────────────────────────────────────────────────
const data = (d: string | null) => (d ? d.split('-').reverse().join('/') : '—');
const etichettaAbbinamento: Record<string, string> = {
    incasso_rate: 'Incasso rate',
    pagamento_fornitore: 'Pagamento fornitore',
    regolazione: 'Spesa diretta',
    esistente: 'Già a giornale',
};
const urlScrittura = (id: number) => generatePath('gestionale/:condominio/scritture/:scrittura', { condominio: props.condominio.id, scrittura: id });
const paginaUrl = (url: string | null) => url ?? '#';
</script>

<template>
    <Head title="Movimenti bancari" />

    <GestionaleLayout>
        <div class="px-6 py-8 space-y-3">
            <PageHeaderGuide
                page-title="Movimenti da abbinare"
                page-subtitle="I movimenti dell'estratto conto, da trasformare in incassi, pagamenti e spese con un clic."
                :guides="guide"
                :breadcrumbs="(breadcrumbs as any)"
                :condominio="props.condominio"
                :condomini="props.condomini"
                :esercizio="props.esercizio"
                :esercizi="props.esercizi"
            />

            <MovimentiLayout>
                <BancaNav :condominio-id="props.condominio.id" :da-abbinare="props.conteggi.da_abbinare" />

                <div v-if="flashMessage" class="mb-4">
                    <Alert :message="flashMessage.message" :type="flashMessage.type" />
                </div>
                <div v-if="erroreAbbinamento && !aperto" class="mb-4 whitespace-pre-line rounded-md border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                    {{ erroreAbbinamento }}
                </div>

                <!-- Filtri -->
                <div class="mb-4 flex flex-wrap items-center gap-2">
                    <button
                        v-for="s in [{ k: 'da_abbinare', t: 'Da abbinare', n: props.conteggi.da_abbinare }, { k: 'abbinato', t: 'Abbinati', n: props.conteggi.abbinato }, { k: 'ignorato', t: 'Ignorati', n: props.conteggi.ignorato }, { k: 'tutti', t: 'Tutti', n: null }]"
                        :key="s.k"
                        type="button"
                        class="rounded-full border px-3 py-1 text-sm"
                        :class="filtri.stato === s.k ? 'border-slate-900 bg-slate-900 text-white' : 'bg-white text-slate-700 hover:bg-slate-50'"
                        @click="scegliStato(s.k)"
                    >
                        {{ s.t }}<span v-if="s.n !== null" class="ml-1 opacity-70">{{ s.n }}</span>
                    </button>
                </div>
                <div class="mb-4 grid gap-2 md:grid-cols-6">
                    <select v-model="filtri.cassa_id" class="h-9 rounded-md border border-input bg-background px-2 text-sm" @change="applicaFiltri">
                        <option value="">Tutti i conti</option>
                        <option v-for="c in props.casse" :key="c.id" :value="c.id">{{ c.nome }}</option>
                    </select>
                    <select v-model="filtri.verso" class="h-9 rounded-md border border-input bg-background px-2 text-sm" @change="applicaFiltri">
                        <option value="">Entrate e uscite</option>
                        <option value="entrate">Solo entrate</option>
                        <option value="uscite">Solo uscite</option>
                    </select>
                    <Input v-model="filtri.data_da" type="date" @change="applicaFiltri" />
                    <Input v-model="filtri.data_a" type="date" @change="applicaFiltri" />
                    <Input v-model="filtri.search" placeholder="Cerca nella descrizione" class="md:col-span-2" @keyup.enter="applicaFiltri" />
                </div>

                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <p class="text-sm text-muted-foreground">{{ props.movimenti.total }} movimenti</p>
                    <div class="flex gap-2">
                        <Button variant="outline" size="sm" @click="mostraRegole = !mostraRegole">
                            <Wand2 class="h-4 w-4" /> Regole ({{ props.regole.length }})
                        </Button>
                        <Button size="sm" :disabled="!selezionati.length" @click="confermaMultipla">
                            <Sparkles class="h-4 w-4" /> Conferma proposte sicure ({{ selezionati.length }})
                        </Button>
                    </div>
                </div>

                <!-- Regole -->
                <div v-if="mostraRegole" class="mb-4 space-y-3 rounded-lg border p-4">
                    <p class="text-sm text-muted-foreground">Se la descrizione contiene il testo, la regola registra la spesa sul capitolo scelto (o ignora il movimento). Vale per «Conferma proposte sicure».</p>
                    <ul v-if="props.regole.length" class="divide-y text-sm">
                        <li v-for="r in props.regole" :key="r.id" class="flex items-center justify-between py-2">
                            <span><strong>{{ r.nome }}</strong> — contiene «{{ r.testo_contiene }}» ({{ r.verso }}) → {{ r.azione === 'ignora' ? 'ignora' : r.conto }}</span>
                            <Button variant="ghost" size="icon-sm" @click="eliminaRegola(r.id)"><Trash2 class="h-4 w-4" /></Button>
                        </li>
                    </ul>
                    <div class="grid gap-2 md:grid-cols-4">
                        <Input v-model="regolaNuova.nome" placeholder="Nome (es. Commissioni)" />
                        <Input v-model="regolaNuova.testo_contiene" placeholder="La descrizione contiene…" />
                        <select v-model="regolaNuova.verso" class="h-9 rounded-md border border-input bg-background px-2 text-sm">
                            <option value="uscita">Uscite</option>
                            <option value="entrata">Entrate</option>
                            <option value="entrambi">Entrambe</option>
                        </select>
                        <select v-model="regolaNuova.azione" class="h-9 rounded-md border border-input bg-background px-2 text-sm">
                            <option value="regolazione">Registra sul capitolo</option>
                            <option value="ignora">Ignora</option>
                        </select>
                        <template v-if="regolaNuova.azione === 'regolazione'">
                            <select v-model.number="regolaNuova.conto_id" class="h-9 rounded-md border border-input bg-background px-2 text-sm md:col-span-2">
                                <option :value="null">Capitolo di spesa…</option>
                                <option v-for="c in props.capitoli" :key="c.id" :value="c.id">{{ c.label }}</option>
                            </select>
                            <select v-model.number="regolaNuova.gestione_id" class="h-9 rounded-md border border-input bg-background px-2 text-sm">
                                <option :value="null">Gestione…</option>
                                <option v-for="g in props.gestioni" :key="g.id" :value="g.id">{{ g.nome }}</option>
                            </select>
                            <Input v-model="regolaNuova.causale" placeholder="Causale a giornale" />
                        </template>
                    </div>
                    <div class="flex justify-end">
                        <Button size="sm" :disabled="regolaNuova.nome.length < 3 || regolaNuova.testo_contiene.length < 3" @click="salvaRegola">Salva regola</Button>
                    </div>
                </div>

                <!-- Elenco -->
                <div class="overflow-x-auto rounded-lg border">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                            <tr>
                                <th class="w-8 p-2"><input type="checkbox" class="h-4 w-4" :checked="tuttiSelezionati" @change="selezionaTutti" /></th>
                                <th class="p-2 text-left">Data</th>
                                <th class="p-2 text-left">Descrizione</th>
                                <th class="p-2 text-right">Importo</th>
                                <th class="p-2 text-left">Stato</th>
                                <th class="p-2 text-right">Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="m in props.movimenti.data" :key="m.id" class="border-t align-top">
                                <td class="p-2">
                                    <input v-if="m.stato === 'da_abbinare'" v-model="selezionati" :value="m.id" type="checkbox" class="h-4 w-4" />
                                </td>
                                <td class="p-2 whitespace-nowrap">
                                    {{ data(m.data_contabile) }}
                                    <div class="text-xs text-muted-foreground">{{ m.cassa }}</div>
                                </td>
                                <td class="p-2">
                                    <div class="line-clamp-2">{{ m.descrizione }}</div>
                                    <div v-if="m.controparte || m.iban_controparte" class="text-xs text-muted-foreground">{{ m.controparte }} {{ m.iban_controparte }}</div>
                                </td>
                                <td class="p-2 text-right font-mono whitespace-nowrap" :class="m.importo > 0 ? 'text-emerald-700' : 'text-rose-700'">{{ euro(m.importo) }}</td>
                                <td class="p-2">
                                    <span v-if="m.stato === 'da_abbinare'" class="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-xs text-amber-700">Da abbinare</span>
                                    <template v-else-if="m.stato === 'abbinato'">
                                        <span class="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700">{{ etichettaAbbinamento[m.tipo_abbinamento ?? ''] ?? 'Abbinato' }}</span>
                                        <div v-if="m.scrittura" class="mt-1 text-xs">
                                            <Link :href="urlScrittura(m.scrittura.id)" class="text-blue-700 hover:underline">{{ m.scrittura.protocollo ?? ('#' + m.scrittura.id) }}</Link>
                                            <span class="text-muted-foreground"> {{ m.scrittura.causale }}</span>
                                        </div>
                                    </template>
                                    <template v-else>
                                        <span class="rounded-full border border-slate-200 bg-slate-100 px-2 py-0.5 text-xs text-slate-600">Ignorato</span>
                                        <div v-if="m.note" class="mt-1 text-xs text-muted-foreground">{{ m.note }}</div>
                                    </template>
                                </td>
                                <td class="p-2 text-right whitespace-nowrap">
                                    <Button v-if="m.stato === 'da_abbinare'" size="sm" @click="apri(m)">Abbina</Button>
                                    <Button v-else variant="ghost" size="sm" @click="scollega(m)">
                                        <Undo2 class="h-4 w-4" /> {{ m.stato === 'abbinato' ? 'Annulla abbinamento' : 'Rimetti da abbinare' }}
                                    </Button>
                                </td>
                            </tr>
                            <tr v-if="props.movimenti.data.length === 0">
                                <td colspan="6" class="p-6 text-center text-sm text-muted-foreground">Nessun movimento con questi filtri.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="props.movimenti.last_page > 1" class="mt-3 flex items-center justify-between text-sm">
                    <Link :href="paginaUrl(props.movimenti.prev_page_url)" :class="props.movimenti.prev_page_url ? '' : 'pointer-events-none opacity-40'" preserve-scroll>← Precedenti</Link>
                    <span>Pagina {{ props.movimenti.current_page }} di {{ props.movimenti.last_page }}</span>
                    <Link :href="paginaUrl(props.movimenti.next_page_url)" :class="props.movimenti.next_page_url ? '' : 'pointer-events-none opacity-40'" preserve-scroll>Successivi →</Link>
                </div>

                <!-- Pannello abbinamento -->
                <Dialog :open="aperto" @update:open="(v: boolean) => (aperto = v)">
                    <DialogScrollContent class="sm:max-w-3xl">
                        <DialogHeader>
                            <DialogTitle>Abbina il movimento</DialogTitle>
                            <DialogDescription v-if="corrente">
                                {{ data(corrente.data_contabile) }} · <span :class="corrente.importo > 0 ? 'text-emerald-700' : 'text-rose-700'">{{ euro(corrente.importo) }}</span> · {{ corrente.descrizione }}
                            </DialogDescription>
                        </DialogHeader>

                        <div v-if="erroreAbbinamento" class="whitespace-pre-line rounded-md border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{{ erroreAbbinamento }}</div>

                        <div v-if="caricamento" class="flex items-center gap-2 py-6 text-sm text-muted-foreground"><Loader2 class="h-4 w-4 animate-spin" /> Cerco le proposte…</div>

                        <div v-else-if="proposte && corrente" class="space-y-6">
                            <!-- Già a giornale -->
                            <section v-if="proposte.gia_registrate.length" class="space-y-2">
                                <h4 class="text-sm font-semibold">Già registrato a giornale</h4>
                                <div v-for="s in proposte.gia_registrate" :key="s.id" class="flex items-center justify-between rounded-md border bg-blue-50/40 p-2 text-sm">
                                    <span>{{ s.protocollo ?? ('#' + s.id) }} del {{ data(s.data) }} — {{ s.causale }}</span>
                                    <Button size="sm" @click="collegaScrittura(s.id)">Collega</Button>
                                </div>
                            </section>

                            <!-- Entrata: incasso rate -->
                            <section v-if="corrente.importo > 0" class="space-y-3">
                                <h4 class="text-sm font-semibold">Incasso rate</h4>
                                <div class="flex flex-wrap items-end gap-3">
                                    <div class="min-w-[16rem] flex-1 space-y-1">
                                        <Label>Condòmino che ha pagato</Label>
                                        <select v-model.number="incasso.pagante_id" class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm" @change="cambiaPagante">
                                            <option :value="null">Scegli…</option>
                                            <option v-for="a in props.anagrafiche" :key="a.id" :value="a.id">{{ a.nome }}</option>
                                        </select>
                                    </div>
                                    <p v-if="proposte.incasso && proposte.incasso.anagrafica.id === incasso.pagante_id" class="text-xs text-muted-foreground">{{ proposte.incasso.motivo }}</p>
                                </div>
                                <table v-if="incasso.righe.length" class="min-w-full text-sm">
                                    <thead class="text-xs text-slate-500">
                                        <tr><th class="p-1 text-left">Rata</th><th class="p-1 text-left">Scadenza</th><th class="p-1 text-right">Residuo</th><th class="p-1 text-right">Da pagare (€)</th></tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="r in incasso.righe" :key="r.quota_id" class="border-t">
                                            <td class="p-1">{{ r.descrizione }} <span class="text-xs text-muted-foreground">{{ r.gestione }} {{ r.unita }}</span>
                                                <span v-if="!r.emessa" class="ml-1 text-xs text-amber-700">(non emessa)</span></td>
                                            <td class="p-1">{{ data(r.scadenza) }}</td>
                                            <td class="p-1 text-right font-mono">{{ euro(r.residuo) }}</td>
                                            <td class="p-1 text-right">
                                                <Input
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    class="ml-auto h-8 w-28 text-right"
                                                    :model-value="inEuro(r.da_pagare ?? 0)"
                                                    @update:model-value="(v: string | number) => (r.da_pagare = centesimi(v))"
                                                />
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                                <p v-else-if="incasso.pagante_id" class="text-sm text-muted-foreground">Nessuna rata aperta per questo condòmino.</p>
                                <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                                    <span>Rate {{ euro(totaleRate) }} · eccedenza a credito {{ euro(eccedenza) }}</span>
                                    <div class="flex items-center gap-2">
                                        <Input v-model="incasso.descrizione" placeholder="Causale (facoltativa)" class="h-8 w-56" />
                                        <Button size="sm" :disabled="!incassoValido || invio" @click="registraIncasso">Registra incasso</Button>
                                    </div>
                                </div>
                            </section>

                            <!-- Uscita: pagamento fornitore -->
                            <section v-if="corrente.importo < 0" class="space-y-3">
                                <h4 class="text-sm font-semibold">Pagamento fattura</h4>
                                <table v-if="fattureElencabili.length" class="min-w-full text-sm">
                                    <thead class="text-xs text-slate-500">
                                        <tr><th class="p-1 text-left">Fornitore</th><th class="p-1 text-left">Fattura</th><th class="p-1 text-right">Residuo</th><th class="p-1 text-right">Pagato (€)</th></tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="f in fattureElencabili" :key="f.fattura_id" class="border-t">
                                            <td class="p-1">{{ f.fornitore.nome }}
                                                <div v-if="f.motivi?.length" class="text-xs text-emerald-700">{{ f.motivi.join(', ') }}</div></td>
                                            <td class="p-1">{{ f.numero }} <span class="text-xs text-muted-foreground">{{ data(f.data) }}</span></td>
                                            <td class="p-1 text-right font-mono">{{ euro(f.residuo) }}</td>
                                            <td class="p-1 text-right">
                                                <Input v-model="pagamento.scelte[f.fattura_id]" type="number" step="0.01" min="0" class="ml-auto h-8 w-28 text-right" />
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                                <p v-else class="text-sm text-muted-foreground">Nessuna fattura approvata da pagare.</p>
                                <div class="grid gap-2 md:grid-cols-2">
                                    <div class="space-y-1">
                                        <Label>Commissioni nello stesso addebito (€)</Label>
                                        <Input v-model="pagamento.commissioni" type="number" step="0.01" min="0" class="h-8" />
                                    </div>
                                    <div class="space-y-1 text-sm">
                                        <label v-if="codiceErrore === 'iban_discrepanza'" class="flex items-center gap-2"><input v-model="pagamento.iban_confermato" type="checkbox" /> confermo l'IBAN del bonifico</label>
                                        <label v-if="codiceErrore === 'possibile_duplicato'" class="flex items-center gap-2"><input v-model="pagamento.conferma_duplicato" type="checkbox" /> ho verificato che non è un doppione</label>
                                        <template v-if="codiceErrore === 'saldo_insufficiente'">
                                            <label class="flex items-center gap-2"><input v-model="pagamento.allow_overdraft" type="checkbox" /> registra anche con saldo insufficiente</label>
                                            <Input v-if="pagamento.allow_overdraft" v-model="pagamento.nota_override" placeholder="Motivo (obbligatorio)" class="h-8" />
                                        </template>
                                    </div>
                                </div>
                                <div class="flex items-center justify-between text-sm">
                                    <span :class="pagamentoValido ? '' : 'text-rose-600'">Totale {{ euro(totalePagamento) }} su {{ euro(Math.abs(corrente.importo)) }}</span>
                                    <Button size="sm" :disabled="!pagamentoValido || invio" @click="registraPagamento">Registra pagamento</Button>
                                </div>
                            </section>

                            <!-- Uscita: spesa diretta -->
                            <section v-if="corrente.importo < 0" class="space-y-3">
                                <h4 class="text-sm font-semibold">Commissioni, bolli e altre spese senza fattura</h4>
                                <p v-if="proposte.regole.length" class="text-xs text-emerald-700">Regola «{{ proposte.regole[0].nome }}»</p>
                                <div class="grid gap-2 md:grid-cols-3">
                                    <select v-model.number="spesa.conto_id" class="h-9 rounded-md border border-input bg-background px-2 text-sm md:col-span-2">
                                        <option :value="null">Capitolo di spesa…</option>
                                        <option v-for="c in props.capitoli" :key="c.id" :value="c.id">{{ c.label }}</option>
                                    </select>
                                    <select v-model.number="spesa.gestione_id" class="h-9 rounded-md border border-input bg-background px-2 text-sm">
                                        <option :value="null">Gestione…</option>
                                        <option v-for="g in props.gestioni" :key="g.id" :value="g.id">{{ g.nome }}</option>
                                    </select>
                                    <Input v-model="spesa.causale" placeholder="Causale a giornale" class="md:col-span-3" />
                                </div>
                                <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                                    <label class="flex items-center gap-2">
                                        <input v-model="spesa.crea_regola" type="checkbox" /> ricorda per i movimenti che contengono
                                        <Input v-model="spesa.testo_regola" :disabled="!spesa.crea_regola" class="h-8 w-48" />
                                    </label>
                                    <Button size="sm" :disabled="!spesa.conto_id || !spesa.gestione_id || invio" @click="registraSpesa">Registra spesa</Button>
                                </div>
                            </section>

                            <!-- Altro -->
                            <section class="grid gap-4 border-t pt-4 md:grid-cols-2">
                                <div class="space-y-2">
                                    <h4 class="text-sm font-semibold">Collega a una scrittura esistente</h4>
                                    <div class="flex items-center gap-2">
                                        <Input v-model="collega.scrittura_id" type="number" placeholder="N. scrittura" class="h-8 w-32" />
                                        <label class="flex items-center gap-1 text-xs"><input v-model="collega.forza" type="checkbox" /> anche con importo diverso</label>
                                        <Button size="sm" variant="outline" :disabled="!collega.scrittura_id" @click="collegaScrittura(collega.scrittura_id, collega.forza)">Collega</Button>
                                    </div>
                                </div>
                                <div class="space-y-2">
                                    <h4 class="text-sm font-semibold">Non va a giornale</h4>
                                    <div class="flex items-center gap-2">
                                        <Input v-model="notaIgnora" placeholder="Motivo" class="h-8" />
                                        <Button size="sm" variant="outline" @click="ignora">Ignora</Button>
                                    </div>
                                </div>
                            </section>
                        </div>
                    </DialogScrollContent>
                </Dialog>
            </MovimentiLayout>
        </div>
    </GestionaleLayout>
</template>
