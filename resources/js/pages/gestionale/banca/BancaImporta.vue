<script setup lang="ts">
/**
 * Banca → Importa estratto conto.
 *
 * Tre passi sulla stessa pagina: scegli conto e file, controlla l'anteprima (e, per CSV/XLSX, quale
 * colonna è la data, quale l'importo…), conferma. L'anteprima arriva dal server e si aggiorna sul
 * posto a ogni correzione della mappatura: le regole di lettura stanno in un posto solo.
 */
import { computed, reactive, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import MovimentiLayout from '@/layouts/gestionale/MovimentiLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import BancaNav from '@/components/gestionale/banca/BancaNav.vue';
import Alert from '@/components/Alert.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { usePermission } from '@/composables/permissions';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { Upload, FileSpreadsheet, ShieldCheck, Link2, Loader2, RefreshCw } from 'lucide-vue-next';
import type { Flash } from '@/types/flash';

interface CassaOpt { id: number; nome: string; tipo: string; iban: string | null }
interface Importazione {
    id: number; cassa: string | null; formato: string; nome_file: string | null;
    data_da: string | null; data_a: string | null; righe_importate: number; righe_duplicate: number;
    righe_collegate: number; creata: string | null;
}
interface RigaAnteprima {
    riga: number | null; hash: string; data_contabile: string; data_valuta: string | null; importo: number;
    divisa: string; descrizione: string; controparte: string | null; iban_controparte: string | null;
    esito: 'nuovo' | 'duplicato' | 'possibile_duplicato' | 'gia_registrato';
    scrittura: { id: number; protocollo: string | null; causale: string | null; data: string; tipo: string } | null;
}
interface Configurazione {
    riga_intestazione: number | null;
    colonne: Record<string, number | null>;
    nomi_colonne?: Record<string, string>;
    separatore_decimale: string;
    formato_data: string;
    inverti_segno: boolean;
}
interface Anteprima {
    formato: string; nome_file: string | null; file_gia_importato: boolean;
    movimenti: RigaAnteprima[];
    scarti: { riga: number | null; motivo: string }[];
    conteggi: { nuovi: number; duplicati: number; possibili_duplicati: number; gia_registrati: number; scartati: number };
    periodo: { da: string | null; a: string | null };
    saldi: { iniziale: number | null; data_iniziale: string | null; finale: number | null; data_finale: string | null };
    iban_file: string | null; iban_conto: string | null;
    tabella: { riga_intestazione?: number | null; intestazioni?: string[]; righe_anteprima?: string[][]; configurazione?: Configurazione };
}

const props = defineProps<{
    condominio: any;
    condomini: any[];
    esercizio: any;
    esercizi: any[];
    casse: CassaOpt[];
    profili: { id: number; cassa_id: number; nome: string | null }[];
    importazioni: Importazione[];
    ruoli: Record<string, string>;
    limiteFile: string;
}>();

const { generateRoute, generatePath } = usePermission();
const { euro } = useCurrencyFormatter();
const page = usePage<{ flash: { message?: Flash }; errors: Record<string, string> }>();
const flashMessage = computed(() => page.props.flash?.message);

const breadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Banca' },
    { title: 'Importa estratto conto' },
]);

const guide = [
    { title: 'Formati', description: 'Lista movimenti CSV o Excel (anche con righe di intestazione sopra la tabella), CAMT.053/052 in XML, OFX. Il PDF dell\'estratto non si legge: scarica dalla banca la lista movimenti.', icon: FileSpreadsheet, colorVariant: 'blue' as const },
    { title: 'Niente doppioni', description: 'Puoi ricaricare lo stesso mese: i movimenti già importati si riconoscono e si saltano. Quelli già registrati a mano a giornale vengono proposti per il collegamento.', icon: ShieldCheck, colorVariant: 'emerald' as const },
    { title: 'Profilo del conto', description: 'La prima volta indichi quale colonna è la data, quale l\'importo… La scelta resta salvata per il conto: dal mese dopo basta caricare il file.', icon: Link2, colorVariant: 'amber' as const },
];

const cassaId = ref<number | null>(props.casse[0]?.id ?? null);
const file = ref<File | null>(null);
const token = ref<string | null>(null);
const anteprima = ref<Anteprima | null>(null);
const configurazione = ref<Configurazione | null>(null);
const caricamento = ref(false);
const errore = ref<string | null>(null);
const salvaProfilo = ref(true);

const scelte = reactive<{ possibili: Record<string, boolean>; collega: Record<string, boolean> }>({ possibili: {}, collega: {} });
const saldi = reactive<{ iniziale: string; data_iniziale: string; finale: string; data_finale: string }>({
    iniziale: '', data_iniziale: '', finale: '', data_finale: '',
});

const profiloDelConto = computed(() => props.profili.find((p) => p.cassa_id === cassaId.value) ?? null);
const tabellare = computed(() => anteprima.value?.tabella?.configurazione !== undefined);

const scegliFile = (e: Event) => {
    const input = e.target as HTMLInputElement;
    file.value = input.files?.[0] ?? null;
};

const messaggioErrore = (e: any): string => {
    const dati = e?.response?.data;
    if (dati?.errors) {
        return Object.values(dati.errors as Record<string, string[]>).flat().join(' ');
    }
    return dati?.message ?? 'Il file non si può leggere.';
};

const applicaAnteprima = (dati: { token: string; anteprima: Anteprima }) => {
    token.value = dati.token;
    anteprima.value = dati.anteprima;
    configurazione.value = dati.anteprima.tabella?.configurazione
        ? JSON.parse(JSON.stringify(dati.anteprima.tabella.configurazione))
        : null;

    scelte.possibili = {};
    scelte.collega = {};
    for (const r of dati.anteprima.movimenti) {
        if (r.esito === 'gia_registrato') scelte.collega[r.hash] = true;
    }

    const cent = (v: number | null) => (v === null ? '' : (v / 100).toFixed(2));
    saldi.iniziale = cent(dati.anteprima.saldi.iniziale);
    saldi.data_iniziale = dati.anteprima.saldi.data_iniziale ?? '';
    saldi.finale = cent(dati.anteprima.saldi.finale);
    saldi.data_finale = dati.anteprima.saldi.data_finale ?? '';
};

const leggi = async () => {
    if (!cassaId.value || !file.value) return;
    caricamento.value = true;
    errore.value = null;

    const dati = new FormData();
    dati.append('cassa_id', String(cassaId.value));
    dati.append('file', file.value);

    try {
        const r = await axios.post(route(generateRoute('gestionale.banca.importa.anteprima'), { condominio: props.condominio.id }), dati);
        applicaAnteprima(r.data);
    } catch (e) {
        errore.value = messaggioErrore(e);
    } finally {
        caricamento.value = false;
    }
};

const aggiorna = async () => {
    if (!token.value || !configurazione.value) return;
    caricamento.value = true;
    errore.value = null;

    try {
        const r = await axios.post(route(generateRoute('gestionale.banca.importa.anteprima'), { condominio: props.condominio.id }), {
            cassa_id: cassaId.value,
            token: token.value,
            configurazione: configurazione.value,
        });
        applicaAnteprima(r.data);
    } catch (e) {
        errore.value = messaggioErrore(e);
    } finally {
        caricamento.value = false;
    }
};

/** Il ruolo di una colonna (indice) secondo la configurazione corrente. */
const ruoloDi = (indice: number): string => {
    const c = configurazione.value?.colonne ?? {};
    return Object.keys(c).find((r) => c[r] === indice) ?? '';
};

const assegnaRuolo = (indice: number, ruolo: string) => {
    if (!configurazione.value) return;
    const c = configurazione.value.colonne;
    for (const r of Object.keys(c)) {
        if (c[r] === indice) c[r] = null;
    }
    if (ruolo) c[ruolo] = indice;
};

const intestazioni = computed<string[]>(() => {
    const t = anteprima.value?.tabella;
    if (!t) return [];
    if (t.intestazioni && t.intestazioni.length) return t.intestazioni;
    const prima = t.righe_anteprima?.[0] ?? [];
    return prima.map((_, i) => `Colonna ${i + 1}`);
});

const centesimi = (v: string): number | null => {
    if (v === '' || v === null || v === undefined) return null;
    const n = Number(String(v).replace(',', '.'));
    return Number.isFinite(n) ? Math.round(n * 100) : null;
};

const daImportare = computed(() => {
    if (!anteprima.value) return 0;
    return anteprima.value.movimenti.filter((r) => r.esito === 'nuovo' || r.esito === 'gia_registrato'
        || (r.esito === 'possibile_duplicato' && scelte.possibili[r.hash])).length;
});

const conferma = () => {
    if (!anteprima.value || !token.value) return;

    const collega: Record<string, number | null> = {};
    for (const r of anteprima.value.movimenti) {
        if (r.esito === 'gia_registrato' && r.scrittura) {
            collega[r.hash] = scelte.collega[r.hash] ? r.scrittura.id : null;
        }
    }

    router.post(route(generateRoute('gestionale.banca.importa.conferma'), { condominio: props.condominio.id }), {
        cassa_id: cassaId.value,
        token: token.value,
        configurazione: tabellare.value ? (configurazione.value as any) : null,
        salva_profilo: salvaProfilo.value,
        importa_possibili_duplicati: Object.keys(scelte.possibili).filter((h) => scelte.possibili[h]),
        collega,
        saldo_iniziale: centesimi(saldi.iniziale),
        data_saldo_iniziale: saldi.data_iniziale || null,
        saldo_finale: centesimi(saldi.finale),
        data_saldo_finale: saldi.data_finale || null,
    });
};

const annulla = async () => {
    if (token.value && cassaId.value) {
        try {
            await axios.post(route(generateRoute('gestionale.banca.importa.scarta'), { condominio: props.condominio.id }), {
                token: token.value,
                cassa_id: cassaId.value,
            });
        } catch {
            // Già scaduto: niente da togliere.
        }
    }
    token.value = null;
    anteprima.value = null;
    configurazione.value = null;
    file.value = null;
};

const etichettaEsito: Record<RigaAnteprima['esito'], string> = {
    nuovo: 'Nuovo',
    duplicato: 'Già importato',
    possibile_duplicato: 'Possibile doppione',
    gia_registrato: 'Già a giornale',
};

const classeEsito: Record<RigaAnteprima['esito'], string> = {
    nuovo: 'bg-emerald-50 text-emerald-700 border-emerald-200',
    duplicato: 'bg-slate-100 text-slate-500 border-slate-200',
    possibile_duplicato: 'bg-amber-50 text-amber-700 border-amber-200',
    gia_registrato: 'bg-blue-50 text-blue-700 border-blue-200',
};

const data = (d: string | null) => (d ? d.split('-').reverse().join('/') : '—');
</script>

<template>
    <Head title="Importa estratto conto" />

    <GestionaleLayout>
        <div class="px-6 py-8 space-y-3">
            <PageHeaderGuide
                page-title="Importa estratto conto"
                page-subtitle="Carica la lista movimenti della banca: i movimenti si abbinano poi a incassi, pagamenti e spese senza inserirli a mano."
                :guides="guide"
                :breadcrumbs="(breadcrumbs as any)"
                :condominio="props.condominio"
                :condomini="props.condomini"
                :esercizio="props.esercizio"
                :esercizi="props.esercizi"
            />

            <MovimentiLayout>
                <BancaNav :condominio-id="props.condominio.id" />

                <div v-if="flashMessage" class="mb-4">
                    <Alert :message="flashMessage.message" :type="flashMessage.type" />
                </div>
                <div v-if="page.props.errors?.importazione" class="mb-4 rounded-md border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                    {{ page.props.errors.importazione }}
                </div>

                <div v-if="props.casse.length === 0" class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                    Non c'è ancora un conto corrente: crealo dalla sezione Casse e conti, poi torna qui.
                </div>

                <!-- Passo 1: conto e file -->
                <section v-else-if="!anteprima" class="grid gap-4 md:grid-cols-[1fr_1fr_auto] md:items-end">
                    <div class="space-y-1.5">
                        <Label for="cassa">Conto</Label>
                        <select id="cassa" v-model.number="cassaId" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm">
                            <option v-for="c in props.casse" :key="c.id" :value="c.id">{{ c.nome }}{{ c.iban ? ' — ' + c.iban : '' }}</option>
                        </select>
                        <p class="text-xs text-muted-foreground">
                            {{ profiloDelConto ? 'Mappatura delle colonne salvata: verrà riusata.' : 'Nessuna mappatura salvata: la indovino dai nomi delle colonne.' }}
                        </p>
                    </div>
                    <div class="space-y-1.5">
                        <Label for="file">File dell'estratto conto</Label>
                        <input
                            id="file"
                            type="file"
                            accept=".csv,.txt,.xlsx,.xls,.xml,.ofx,.qfx"
                            class="block w-full text-sm file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm"
                            @change="scegliFile"
                        />
                        <p class="text-xs text-muted-foreground">CSV, XLSX, XLS, CAMT (XML), OFX — max {{ props.limiteFile }}</p>
                    </div>
                    <Button :disabled="!file || !cassaId || caricamento" @click="leggi">
                        <Loader2 v-if="caricamento" class="h-4 w-4 animate-spin" />
                        <Upload v-else class="h-4 w-4" />
                        Leggi il file
                    </Button>
                    <p v-if="errore" class="md:col-span-3 text-sm text-rose-600">{{ errore }}</p>
                </section>

                <!-- Passo 2: anteprima -->
                <section v-else class="space-y-5">
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="font-semibold">{{ anteprima.nome_file }}</span>
                        <Badge variant="outline">{{ anteprima.formato.toUpperCase() }}</Badge>
                        <span class="text-muted-foreground">dal {{ data(anteprima.periodo.da) }} al {{ data(anteprima.periodo.a) }}</span>
                        <Badge variant="secondary">{{ anteprima.conteggi.nuovi }} nuovi</Badge>
                        <Badge v-if="anteprima.conteggi.gia_registrati" variant="secondary">{{ anteprima.conteggi.gia_registrati }} già a giornale</Badge>
                        <Badge v-if="anteprima.conteggi.duplicati" variant="secondary">{{ anteprima.conteggi.duplicati }} già importati</Badge>
                        <Badge v-if="anteprima.conteggi.possibili_duplicati" variant="secondary">{{ anteprima.conteggi.possibili_duplicati }} possibili doppioni</Badge>
                    </div>

                    <div v-if="anteprima.file_gia_importato" class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                        Questo stesso file è già stato importato su questo conto: i movimenti già presenti verranno saltati.
                    </div>
                    <div v-if="anteprima.iban_file && anteprima.iban_conto && anteprima.iban_file !== anteprima.iban_conto" class="rounded-md border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                        L'IBAN del file ({{ anteprima.iban_file }}) non è quello del conto scelto ({{ anteprima.iban_conto }}). Controlla di aver scelto il conto giusto.
                    </div>
                    <p v-if="errore" class="text-sm text-rose-600">{{ errore }}</p>

                    <!-- Mappatura colonne (solo CSV/XLSX) -->
                    <div v-if="tabellare && configurazione" class="rounded-lg border p-4 space-y-3">
                        <div class="flex flex-wrap items-end gap-4">
                            <div class="space-y-1">
                                <Label>Riga di intestazione</Label>
                                <Input
                                    type="number"
                                    min="1"
                                    class="w-24"
                                    :model-value="configurazione.riga_intestazione === null ? '' : configurazione.riga_intestazione + 1"
                                    @update:model-value="(v: string | number) => { if (configurazione) configurazione.riga_intestazione = v === '' ? null : Number(v) - 1 }"
                                />
                            </div>
                            <div class="space-y-1">
                                <Label>Decimali</Label>
                                <select v-model="configurazione.separatore_decimale" class="h-9 rounded-md border border-input bg-background px-2 text-sm">
                                    <option value=",">virgola (1.234,56)</option>
                                    <option value=".">punto (1,234.56)</option>
                                </select>
                            </div>
                            <div class="space-y-1">
                                <Label>Date</Label>
                                <select v-model="configurazione.formato_data" class="h-9 rounded-md border border-input bg-background px-2 text-sm">
                                    <option value="auto">automatico</option>
                                    <option value="d/m/Y">gg/mm/aaaa</option>
                                    <option value="d/m/y">gg/mm/aa</option>
                                    <option value="d-m-Y">gg-mm-aaaa</option>
                                    <option value="d.m.Y">gg.mm.aaaa</option>
                                    <option value="Y-m-d">aaaa-mm-gg</option>
                                    <option value="m/d/Y">mm/gg/aaaa</option>
                                </select>
                            </div>
                            <label class="flex items-center gap-2 text-sm">
                                <input v-model="configurazione.inverti_segno" type="checkbox" class="h-4 w-4" />
                                inverti il segno degli importi
                            </label>
                            <Button variant="outline" size="sm" :disabled="caricamento" @click="aggiorna">
                                <RefreshCw class="h-4 w-4" :class="caricamento ? 'animate-spin' : ''" />
                                Aggiorna anteprima
                            </Button>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full text-xs">
                                <thead>
                                    <tr>
                                        <th v-for="(nome, i) in intestazioni" :key="i" class="p-1 text-left align-bottom">
                                            <select
                                                :value="ruoloDi(i)"
                                                class="mb-1 w-full rounded border border-input bg-background px-1 py-0.5 text-xs"
                                                @change="assegnaRuolo(i, ($event.target as HTMLSelectElement).value)"
                                            >
                                                <option value="">— non usare —</option>
                                                <option v-for="(etichetta, ruolo) in props.ruoli" :key="ruolo" :value="ruolo">{{ etichetta }}</option>
                                            </select>
                                            <div class="font-semibold text-slate-700">{{ nome }}</div>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="(riga, r) in anteprima.tabella.righe_anteprima ?? []"
                                        :key="r"
                                        :class="r === configurazione.riga_intestazione ? 'bg-blue-50 font-semibold' : (configurazione.riga_intestazione !== null && r < configurazione.riga_intestazione ? 'text-slate-400' : '')"
                                    >
                                        <td v-for="(cella, c) in riga" :key="c" class="border-t p-1 whitespace-nowrap max-w-[16rem] truncate">{{ cella }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="text-xs text-muted-foreground">Le prime righe del file. In blu l'intestazione della tabella; sopra, le righe che non sono movimenti.</p>
                    </div>

                    <!-- Movimenti letti -->
                    <div class="overflow-x-auto rounded-lg border">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                                <tr>
                                    <th class="p-2 text-left">Data</th>
                                    <th class="p-2 text-left">Descrizione</th>
                                    <th class="p-2 text-right">Importo</th>
                                    <th class="p-2 text-left">Esito</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in anteprima.movimenti" :key="r.hash" class="border-t" :class="r.esito === 'duplicato' ? 'opacity-50' : ''">
                                    <td class="p-2 whitespace-nowrap">{{ data(r.data_contabile) }}</td>
                                    <td class="p-2">
                                        <div class="line-clamp-2">{{ r.descrizione }}</div>
                                        <div v-if="r.controparte || r.iban_controparte" class="text-xs text-muted-foreground">
                                            {{ r.controparte }} {{ r.iban_controparte }}
                                        </div>
                                    </td>
                                    <td class="p-2 text-right font-mono whitespace-nowrap" :class="r.importo > 0 ? 'text-emerald-700' : 'text-rose-700'">{{ euro(r.importo) }}</td>
                                    <td class="p-2">
                                        <span class="inline-block rounded-full border px-2 py-0.5 text-xs" :class="classeEsito[r.esito]">{{ etichettaEsito[r.esito] }}</span>
                                        <label v-if="r.esito === 'possibile_duplicato'" class="mt-1 flex items-center gap-1 text-xs">
                                            <input v-model="scelte.possibili[r.hash]" type="checkbox" class="h-3.5 w-3.5" /> importa comunque
                                        </label>
                                        <label v-if="r.esito === 'gia_registrato' && r.scrittura" class="mt-1 flex items-center gap-1 text-xs">
                                            <input v-model="scelte.collega[r.hash]" type="checkbox" class="h-3.5 w-3.5" />
                                            collega a {{ r.scrittura.protocollo ?? ('#' + r.scrittura.id) }} del {{ data(r.scrittura.data) }}
                                        </label>
                                    </td>
                                </tr>
                                <tr v-if="anteprima.movimenti.length === 0">
                                    <td colspan="4" class="p-4 text-center text-sm text-muted-foreground">Nessun movimento letto: controlla la riga di intestazione e i ruoli delle colonne.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <details v-if="anteprima.scarti.length" class="text-sm">
                        <summary class="cursor-pointer text-muted-foreground">{{ anteprima.scarti.length }} righe non lette</summary>
                        <ul class="mt-2 list-disc pl-5 text-xs text-muted-foreground">
                            <li v-for="(s, i) in anteprima.scarti" :key="i">{{ s.riga ? 'Riga ' + s.riga + ': ' : '' }}{{ s.motivo }}</li>
                        </ul>
                    </details>

                    <!-- Saldi dichiarati -->
                    <div class="grid gap-3 rounded-lg border p-4 md:grid-cols-4">
                        <div class="space-y-1">
                            <Label>Saldo iniziale (€)</Label>
                            <Input v-model="saldi.iniziale" type="number" step="0.01" placeholder="facoltativo" />
                        </div>
                        <div class="space-y-1">
                            <Label>a fine giornata del</Label>
                            <Input v-model="saldi.data_iniziale" type="date" />
                        </div>
                        <div class="space-y-1">
                            <Label>Saldo finale (€)</Label>
                            <Input v-model="saldi.finale" type="number" step="0.01" placeholder="facoltativo" />
                        </div>
                        <div class="space-y-1">
                            <Label>a fine giornata del</Label>
                            <Input v-model="saldi.data_finale" type="date" />
                        </div>
                        <p class="md:col-span-4 text-xs text-muted-foreground">I saldi servono alla riconciliazione. Se il file li riporta sono già compilati.</p>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <label v-if="tabellare" class="flex items-center gap-2 text-sm">
                            <input v-model="salvaProfilo" type="checkbox" class="h-4 w-4" />
                            ricorda questa mappatura per il conto
                        </label>
                        <span v-else />
                        <div class="flex gap-2">
                            <Button variant="outline" @click="annulla">Annulla</Button>
                            <Button :disabled="daImportare === 0" @click="conferma">Importa {{ daImportare }} movimenti</Button>
                        </div>
                    </div>
                </section>

                <!-- Ultimi import -->
                <section v-if="props.importazioni.length" class="mt-8">
                    <h3 class="mb-2 text-sm font-semibold text-slate-700">Ultimi import</h3>
                    <div class="overflow-x-auto rounded-lg border">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                                <tr>
                                    <th class="p-2 text-left">Caricato</th>
                                    <th class="p-2 text-left">Conto</th>
                                    <th class="p-2 text-left">File</th>
                                    <th class="p-2 text-left">Periodo</th>
                                    <th class="p-2 text-right">Importati</th>
                                    <th class="p-2 text-right">Saltati</th>
                                    <th class="p-2 text-right">Collegati</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="i in props.importazioni" :key="i.id" class="border-t">
                                    <td class="p-2 whitespace-nowrap">{{ i.creata?.slice(0, 16) }}</td>
                                    <td class="p-2">{{ i.cassa }}</td>
                                    <td class="p-2">{{ i.nome_file }} <span class="text-xs text-muted-foreground">({{ i.formato }})</span></td>
                                    <td class="p-2 whitespace-nowrap">{{ data(i.data_da) }} – {{ data(i.data_a) }}</td>
                                    <td class="p-2 text-right">{{ i.righe_importate }}</td>
                                    <td class="p-2 text-right">{{ i.righe_duplicate }}</td>
                                    <td class="p-2 text-right">{{ i.righe_collegate }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </MovimentiLayout>
        </div>
    </GestionaleLayout>
</template>
