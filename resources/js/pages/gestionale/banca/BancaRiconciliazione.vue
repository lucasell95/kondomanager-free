<script setup lang="ts">
/**
 * Banca → Riconciliazione: per conto e periodo, saldo dell'estratto conto contro saldo contabile,
 * e l'elenco di ciò che spiega la differenza.
 */
import { computed, reactive } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import MovimentiLayout from '@/layouts/gestionale/MovimentiLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import BancaNav from '@/components/gestionale/banca/BancaNav.vue';
import Alert from '@/components/Alert.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermission } from '@/composables/permissions';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { Scale, AlertTriangle, CheckCircle2, BookOpen } from 'lucide-vue-next';
import type { Flash } from '@/types/flash';

interface Differenza {
    tipo: string; data: string; descrizione: string | null; movimento_id: number | null; scrittura_id: number | null;
    protocollo?: string | null; importo_banca: number; importo_contabile: number; effetto: number;
}
interface Riconciliazione {
    periodo: { da: string; a: string };
    includi_fondi: boolean;
    saldo_banca_iniziale: number | null; saldo_banca_finale: number | null; saldo_banca_calcolato: number | null;
    saldo_contabile_iniziale: number; saldo_contabile_finale: number;
    differenza_iniziale: number | null; differenza_finale: number | null; differenza_spiegata: number;
    residuo_non_spiegato: number | null;
    movimenti_banca: { totale: number; abbinati: number; da_abbinare: number; ignorati: number; somma: number };
    differenze: Differenza[];
}

const props = defineProps<{
    condominio: any;
    condomini: any[];
    esercizio: any;
    esercizi: any[];
    casse: { id: number; nome: string; tipo: string; iban: string | null }[];
    cassa_id: number | null;
    periodo: { da: string; a: string } | null;
    ha_fondi: boolean;
    riconciliazione: Riconciliazione | null;
}>();

const { generateRoute, generatePath } = usePermission();
const { euro } = useCurrencyFormatter();
const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash?.message);

const breadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Banca' },
    { title: 'Riconciliazione' },
]);

const guide = [
    { title: 'Il confronto', description: 'Il saldo della banca a fine periodo contro il saldo del conto a giornale alla stessa data, per data dell\'operazione.', icon: Scale, colorVariant: 'blue' as const },
    { title: 'Le differenze', description: 'Movimenti in banca non ancora a giornale, scritture a giornale che la banca non ha ancora (o non ha mai) visto, importi diversi fra le due.', icon: BookOpen, colorVariant: 'amber' as const },
    { title: 'Il residuo', description: 'Se resta una differenza non spiegata, manca qualche movimento nell\'import o un saldo dichiarato è sbagliato.', icon: AlertTriangle, colorVariant: 'emerald' as const },
];

const filtri = reactive({
    cassa_id: props.cassa_id ?? '',
    da: props.periodo?.da ?? '',
    a: props.periodo?.a ?? '',
    includi_fondi: props.riconciliazione?.includi_fondi ?? false,
});

const ricalcola = () => {
    router.get(route(generateRoute('gestionale.banca.riconciliazione'), {
        condominio: props.condominio.id,
        cassa_id: filtri.cassa_id || undefined,
        da: filtri.da || undefined,
        a: filtri.a || undefined,
        includi_fondi: filtri.includi_fondi ? 1 : 0,
    }), {}, { preserveScroll: true });
};

const giornoPrima = (d: string) => {
    const x = new Date(d + 'T00:00:00');
    x.setDate(x.getDate() - 1);
    return `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}`;
};

const nuovoSaldo = reactive<{ iniziale: string; finale: string }>({
    iniziale: props.riconciliazione?.saldo_banca_iniziale != null ? (props.riconciliazione.saldo_banca_iniziale / 100).toFixed(2) : '',
    finale: props.riconciliazione?.saldo_banca_finale != null ? (props.riconciliazione.saldo_banca_finale / 100).toFixed(2) : '',
});

const salvaSaldo = (quale: 'iniziale' | 'finale') => {
    if (!props.cassa_id || !props.periodo) return;
    const valore = Number(String(nuovoSaldo[quale]).replace(',', '.'));
    if (!Number.isFinite(valore)) return;
    router.post(route(generateRoute('gestionale.banca.riconciliazione.saldo'), { condominio: props.condominio.id }), {
        cassa_id: props.cassa_id,
        data: quale === 'iniziale' ? giornoPrima(props.periodo.da) : props.periodo.a,
        saldo: Math.round(valore * 100),
    }, { preserveScroll: true });
};

const etichetta: Record<string, string> = {
    solo_banca: 'In banca, non a giornale',
    ignorato: 'In banca, ignorato',
    scrittura_fuori_periodo: 'In banca; a giornale in un altro periodo',
    solo_giornale: 'A giornale, non in banca',
    movimento_fuori_periodo: 'A giornale; in banca in un altro periodo',
    importo_diverso: 'Importi diversi',
};

const data = (d: string | null) => (d ? d.split('-').reverse().join('/') : '—');
const urlScrittura = (id: number) => generatePath('gestionale/:condominio/scritture/:scrittura', { condominio: props.condominio.id, scrittura: id });
const urlMovimenti = computed(() => generatePath('gestionale/:condominio/banca/movimenti', { condominio: props.condominio.id }));
const quadra = computed(() => props.riconciliazione?.differenza_finale === 0);
</script>

<template>
    <Head title="Riconciliazione bancaria" />

    <GestionaleLayout>
        <div class="px-6 py-8 space-y-3">
            <PageHeaderGuide
                page-title="Riconciliazione bancaria"
                page-subtitle="Il saldo dell'estratto conto contro il saldo contabile, con le differenze una per una."
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

                <div v-if="!props.cassa_id" class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                    Non c'è ancora un conto corrente da riconciliare.
                </div>

                <template v-else>
                    <div class="mb-4 grid gap-3 md:grid-cols-5 md:items-end">
                        <div class="space-y-1 md:col-span-2">
                            <Label>Conto</Label>
                            <select v-model.number="filtri.cassa_id" class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm">
                                <option v-for="c in props.casse" :key="c.id" :value="c.id">{{ c.nome }}</option>
                            </select>
                        </div>
                        <div class="space-y-1"><Label>Dal</Label><Input v-model="filtri.da" type="date" /></div>
                        <div class="space-y-1"><Label>Al</Label><Input v-model="filtri.a" type="date" /></div>
                        <Button @click="ricalcola">Calcola</Button>
                        <label v-if="props.ha_fondi" class="flex items-center gap-2 text-sm md:col-span-5">
                            <input v-model="filtri.includi_fondi" type="checkbox" class="h-4 w-4" />
                            includi i fondi (sono partizioni dello stesso conto corrente: la banca vede la somma)
                        </label>
                    </div>

                    <div v-if="props.riconciliazione" class="space-y-5">
                        <div class="grid gap-4 md:grid-cols-3">
                            <div class="rounded-xl border p-4">
                                <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Banca</p>
                                <div class="mt-2 space-y-2 text-sm">
                                    <div class="flex items-center justify-between gap-2">
                                        <span>al {{ data(giornoPrima(props.riconciliazione.periodo.da)) }}</span>
                                        <div class="flex items-center gap-1">
                                            <Input v-model="nuovoSaldo.iniziale" type="number" step="0.01" class="h-8 w-28 text-right" placeholder="saldo" />
                                            <Button size="sm" variant="outline" @click="salvaSaldo('iniziale')">Salva</Button>
                                        </div>
                                    </div>
                                    <div class="flex items-center justify-between gap-2">
                                        <span>al {{ data(props.riconciliazione.periodo.a) }}</span>
                                        <div class="flex items-center gap-1">
                                            <Input v-model="nuovoSaldo.finale" type="number" step="0.01" class="h-8 w-28 text-right" placeholder="saldo" />
                                            <Button size="sm" variant="outline" @click="salvaSaldo('finale')">Salva</Button>
                                        </div>
                                    </div>
                                    <p class="text-xs text-muted-foreground">
                                        Movimenti importati nel periodo: {{ props.riconciliazione.movimenti_banca.totale }} ({{ euro(props.riconciliazione.movimenti_banca.somma) }}).
                                        <template v-if="props.riconciliazione.saldo_banca_calcolato !== null">Saldo iniziale + movimenti = {{ euro(props.riconciliazione.saldo_banca_calcolato) }}.</template>
                                    </p>
                                </div>
                            </div>
                            <div class="rounded-xl border p-4">
                                <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Contabilità</p>
                                <div class="mt-2 space-y-2 text-sm">
                                    <div class="flex justify-between"><span>al {{ data(giornoPrima(props.riconciliazione.periodo.da)) }}</span><span class="font-mono">{{ euro(props.riconciliazione.saldo_contabile_iniziale) }}</span></div>
                                    <div class="flex justify-between"><span>al {{ data(props.riconciliazione.periodo.a) }}</span><span class="font-mono">{{ euro(props.riconciliazione.saldo_contabile_finale) }}</span></div>
                                    <p class="text-xs text-muted-foreground">
                                        Abbinati {{ props.riconciliazione.movimenti_banca.abbinati }},
                                        <Link :href="urlMovimenti" class="text-blue-700 hover:underline">da abbinare {{ props.riconciliazione.movimenti_banca.da_abbinare }}</Link>,
                                        ignorati {{ props.riconciliazione.movimenti_banca.ignorati }}.
                                    </p>
                                </div>
                            </div>
                            <div class="rounded-xl border p-4" :class="quadra ? 'border-emerald-300 bg-emerald-50/50' : ''">
                                <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Differenza (banca − contabilità)</p>
                                <div class="mt-2 space-y-2 text-sm">
                                    <div class="flex justify-between"><span>a inizio periodo</span><span class="font-mono">{{ props.riconciliazione.differenza_iniziale === null ? '—' : euro(props.riconciliazione.differenza_iniziale) }}</span></div>
                                    <div class="flex justify-between"><span>a fine periodo</span><span class="font-mono font-semibold">{{ props.riconciliazione.differenza_finale === null ? '—' : euro(props.riconciliazione.differenza_finale) }}</span></div>
                                    <div class="flex justify-between"><span>spiegata dalle differenze</span><span class="font-mono">{{ euro(props.riconciliazione.differenza_spiegata) }}</span></div>
                                    <div v-if="props.riconciliazione.residuo_non_spiegato !== null" class="flex items-center justify-between" :class="props.riconciliazione.residuo_non_spiegato !== 0 ? 'text-rose-700' : 'text-emerald-700'">
                                        <span class="flex items-center gap-1">
                                            <AlertTriangle v-if="props.riconciliazione.residuo_non_spiegato !== 0" class="h-4 w-4" />
                                            <CheckCircle2 v-else class="h-4 w-4" />
                                            non spiegata
                                        </span>
                                        <span class="font-mono font-semibold">{{ euro(props.riconciliazione.residuo_non_spiegato) }}</span>
                                    </div>
                                    <p v-else class="text-xs text-muted-foreground">Inserisci i saldi dell'estratto conto per il confronto completo.</p>
                                </div>
                            </div>
                        </div>

                        <div class="overflow-x-auto rounded-lg border">
                            <table class="min-w-full text-sm">
                                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                                    <tr>
                                        <th class="p-2 text-left">Data</th>
                                        <th class="p-2 text-left">Differenza</th>
                                        <th class="p-2 text-left">Descrizione</th>
                                        <th class="p-2 text-right">Banca</th>
                                        <th class="p-2 text-right">Giornale</th>
                                        <th class="p-2 text-right">Effetto</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(d, i) in props.riconciliazione.differenze" :key="i" class="border-t">
                                        <td class="p-2 whitespace-nowrap">{{ data(d.data) }}</td>
                                        <td class="p-2">{{ etichetta[d.tipo] ?? d.tipo }}</td>
                                        <td class="p-2">
                                            <div class="line-clamp-2">{{ d.descrizione }}</div>
                                            <Link v-if="d.scrittura_id" :href="urlScrittura(d.scrittura_id)" class="text-xs text-blue-700 hover:underline">{{ d.protocollo ?? ('scrittura #' + d.scrittura_id) }}</Link>
                                        </td>
                                        <td class="p-2 text-right font-mono">{{ d.importo_banca ? euro(d.importo_banca) : '' }}</td>
                                        <td class="p-2 text-right font-mono">{{ d.importo_contabile ? euro(d.importo_contabile) : '' }}</td>
                                        <td class="p-2 text-right font-mono" :class="d.effetto > 0 ? 'text-emerald-700' : 'text-rose-700'">{{ euro(d.effetto) }}</td>
                                    </tr>
                                    <tr v-if="props.riconciliazione.differenze.length === 0">
                                        <td colspan="6" class="p-6 text-center text-sm text-muted-foreground">Nessuna differenza nel periodo: ogni movimento della banca ha la sua scrittura.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </template>
            </MovimentiLayout>
        </div>
    </GestionaleLayout>
</template>
