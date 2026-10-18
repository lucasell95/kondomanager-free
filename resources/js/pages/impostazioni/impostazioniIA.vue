<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { computed, ref } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { Sparkles, Save, ShieldCheck, AlertCircle, Eye, EyeOff, Loader2, KeyRound, Lock, Wallet, PlugZap, ExternalLink } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import Alert from '@/components/Alert.vue';
import { Card, CardContent, CardFooter } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Checkbox } from '@/components/ui/checkbox';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import axios from 'axios';

interface Utilizzo {
    id: number;
    data: string | null;
    tipo_documento: string;
    modello: string;
    token_ingresso: number;
    token_uscita: number;
    costo_usd: number;
    esito: string;
    codice_errore: string | null;
}

const props = defineProps<{
    impostazioni: {
        ia_abilitata: boolean;
        modello: string;
        tetto_spesa_mensile: string | null;
        informativa_confermata_il: string | null;
    };
    // La chiave non arriva mai qui: solo se c'è, se è leggibile e le ultime quattro cifre.
    chiave: { impostata: boolean; leggibile: boolean; finale: string | null };
    modelli_suggeriti: Record<string, string>;
    modello_predefinito: string;
    spesa: { mese_usd: number; tetto_usd: number | null; chiamate: number };
    utilizzi: Utilizzo[];
}>();

const page = usePage();
const flashMessage = computed(() => (page.props as any).flash?.message);

const form = useForm({
    ia_abilitata: props.impostazioni.ia_abilitata,
    api_key: '',
    rimuovi_chiave: false,
    modello: props.impostazioni.modello || props.modello_predefinito,
    tetto_spesa_mensile: props.impostazioni.tetto_spesa_mensile ?? '',
    conferma_informativa: false,
});

const mostraChiave = ref(false);
const provaInCorso = ref(false);
const esitoProva = ref<'ok' | 'errore' | null>(null);
const messaggioProva = ref('');

const breadcrumbs = [
    { title: 'Impostazioni', href: '/impostazioni' },
    { title: 'Assistenza IA', href: '/impostazioni/ia' },
];

const pageGuides = [
    {
        title: 'Bozze, non documenti',
        description: "L'IA propone il testo di convocazioni, verbali e note. Numeri, date, quorum ed esiti li inserisce il programma; ogni bozza va riletta e approvata.",
        icon: Sparkles,
        colorVariant: 'blue' as const,
    },
    {
        title: 'Dati pseudonimizzati',
        description: 'Nomi, ragioni sociali e indirizzi partono come codici. Codici fiscali, IBAN, e-mail e telefoni non partono mai.',
        icon: Lock,
        colorVariant: 'emerald' as const,
    },
    {
        title: 'Spesa sotto controllo',
        description: 'Ogni chiamata viene registrata con token e costo stimato. Con un tetto mensile il programma si ferma prima di superarlo.',
        icon: Wallet,
        colorVariant: 'amber' as const,
    },
];

const segnapostoChiave = computed(() => {
    if (props.chiave.impostata && props.chiave.leggibile) return `Chiave salvata (…${props.chiave.finale}). Lascia vuoto per non cambiarla`;
    if (props.chiave.impostata) return 'Chiave salvata non leggibile: reinseriscila';
    return 'sk-ant-…';
});


const formatoUsd = (valore: number) =>
    new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'USD', minimumFractionDigits: 2, maximumFractionDigits: 4 }).format(valore);

const formatoData = (iso: string | null) =>
    iso ? new Date(iso).toLocaleString('it-IT', { dateStyle: 'short', timeStyle: 'short' }) : '—';

const etichetteEsito: Record<string, { testo: string; classe: string }> = {
    ok: { testo: 'Riuscita', classe: 'text-emerald-700 bg-emerald-50 border-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-300' },
    bozza_non_valida: { testo: 'Bozza scartata dai controlli', classe: 'text-amber-700 bg-amber-50 border-amber-200 dark:bg-amber-900/30 dark:text-amber-300' },
    rifiuto: { testo: 'Rifiutata dal modello', classe: 'text-amber-700 bg-amber-50 border-amber-200 dark:bg-amber-900/30 dark:text-amber-300' },
    troncata: { testo: 'Risposta troncata', classe: 'text-amber-700 bg-amber-50 border-amber-200 dark:bg-amber-900/30 dark:text-amber-300' },
    errore: { testo: 'Errore', classe: 'text-rose-700 bg-rose-50 border-rose-200 dark:bg-rose-900/30 dark:text-rose-300' },
    bloccata_tetto: { testo: 'Bloccata dal tetto', classe: 'text-slate-700 bg-slate-100 border-slate-200 dark:bg-slate-800/50 dark:text-slate-300' },
};

const etichetteTipo: Record<string, string> = {
    convocazione: 'Convocazione',
    verbale: 'Verbale',
    nota_preventivo: 'Nota al preventivo',
    nota_rendiconto: 'Nota al rendiconto',
};

const salva = () => {
    form.post(route('impostazioni.ia.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset('api_key', 'rimuovi_chiave', 'conferma_informativa'),
    });
};

const provaConnessione = async () => {
    provaInCorso.value = true;
    esitoProva.value = null;
    messaggioProva.value = '';

    try {
        const risposta = await axios.post(route('impostazioni.ia.test'), {
            api_key: form.api_key || null,
            modello: form.modello || null,
        });
        esitoProva.value = 'ok';
        messaggioProva.value = risposta.data.message;
    } catch (errore: any) {
        esitoProva.value = 'errore';
        messaggioProva.value = errore.response?.data?.message || 'Errore di connessione';
    } finally {
        provaInCorso.value = false;
    }
};
</script>

<template>
    <AppLayout :breadcrumbs="[]">
        <Head title="Assistenza IA" />

        <div class="px-4 py-6 space-y-6">
            <PageHeaderGuide
                page-title="Assistenza IA"
                page-subtitle="Bozze di convocazioni, verbali e note di preventivo e rendiconto scritte con Claude (Anthropic)"
                :icon="Sparkles"
                :guides="pageGuides"
                :breadcrumbs="breadcrumbs"
                back-url="/impostazioni"
                back-text="Impostazioni"
            />

            <div v-if="flashMessage">
                <Alert :message="flashMessage.message" :type="flashMessage.type" />
            </div>

            <form @submit.prevent="salva" class="space-y-6">
                <Card class="border shadow-none">
                    <div class="px-6 pt-6 pb-2">
                        <div class="flex items-center gap-3 bg-muted/30 px-3 py-2 rounded-md border w-fit">
                            <span class="relative inline-flex rounded-full h-3 w-3" :class="impostazioni.ia_abilitata ? 'bg-emerald-500' : 'bg-slate-400'"></span>
                            <span class="text-xs font-semibold text-slate-700 dark:text-slate-300">Stato:</span>
                            <span class="px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider border rounded-md"
                                :class="impostazioni.ia_abilitata
                                    ? 'text-emerald-700 bg-emerald-100 border-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-300'
                                    : 'text-slate-600 bg-slate-100 border-slate-200 dark:bg-slate-800/50 dark:text-slate-400'">
                                {{ impostazioni.ia_abilitata ? 'Attiva' : 'Spenta' }}
                            </span>
                        </div>
                    </div>

                    <CardContent class="space-y-6 pt-4">
                        <!-- Attivazione -->
                        <div class="flex flex-row items-center justify-between gap-4 border rounded-lg p-4 bg-background shadow-sm">
                            <div class="flex-1">
                                <Label class="block text-sm font-medium leading-none mb-1">Usa l'IA per le bozze</Label>
                                <p class="text-sm text-muted-foreground">
                                    Spenta: il programma usa i testi fissi. Accesa: nelle pagine che lo prevedono compare il pulsante per generare una bozza da rivedere.
                                </p>
                            </div>
                            <Switch v-model="form.ia_abilitata" />
                        </div>

                        <!-- Chiave API -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <Label class="block text-sm font-medium mb-1.5">Chiave API Anthropic</Label>
                                <div class="relative">
                                    <Input v-model="form.api_key" :type="mostraChiave ? 'text' : 'password'" autocomplete="off"
                                        class="w-full text-sm pr-10" :placeholder="segnapostoChiave" :disabled="form.rimuovi_chiave" />
                                    <button type="button" @click="mostraChiave = !mostraChiave"
                                        class="absolute right-3 top-2.5 text-muted-foreground hover:text-foreground">
                                        <Eye v-if="!mostraChiave" class="h-4 w-4" />
                                        <EyeOff v-else class="h-4 w-4" />
                                    </button>
                                </div>
                                <InputError :message="form.errors.api_key" />
                                <p v-if="chiave.impostata && chiave.leggibile && !form.api_key"
                                    class="text-[11px] text-emerald-600 dark:text-emerald-400 mt-1 flex items-center gap-1">
                                    <ShieldCheck class="w-3 h-3" /> Chiave salvata e cifrata sul server (termina con {{ chiave.finale }}).
                                </p>
                                <p v-else-if="chiave.impostata && !chiave.leggibile"
                                    class="text-[11px] text-rose-600 dark:text-rose-400 mt-1 flex items-center gap-1">
                                    <AlertCircle class="w-3 h-3" /> La chiave salvata non si riesce a decifrare (APP_KEY cambiata): reinseriscila.
                                </p>
                                <label v-if="chiave.impostata" class="mt-2 flex items-center gap-2 text-xs text-muted-foreground">
                                    <Checkbox v-model="form.rimuovi_chiave" /> Rimuovi la chiave salvata
                                </label>
                                <p class="text-[11px] text-muted-foreground mt-1">
                                    Creala nella Console Anthropic, meglio in una workspace dedicata al condominio con un limite di spesa.
                                </p>
                            </div>

                            <div>
                                <Label class="block text-sm font-medium mb-1.5">Modello</Label>
                                <Input v-model="form.modello" list="modelli-ia" class="w-full text-sm" :placeholder="modello_predefinito" />
                                <datalist id="modelli-ia">
                                    <option v-for="(descrizione, id) in modelli_suggeriti" :key="id" :value="id">{{ descrizione }}</option>
                                </datalist>
                                <InputError :message="form.errors.modello" />
                                <p class="text-[11px] text-muted-foreground mt-1">
                                    Predefinito: <code>{{ modello_predefinito }}</code>. I nomi cambiano con le nuove generazioni:
                                    <a href="https://platform.claude.com/docs/en/models/overview" target="_blank" rel="noopener" class="underline">elenco dei modelli</a>.
                                </p>
                            </div>
                        </div>

                        <!-- Tetto di spesa -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 border-t pt-4">
                            <div>
                                <Label class="block text-sm font-medium mb-1.5">Tetto di spesa mensile (dollari USA, facoltativo)</Label>
                                <Input v-model="form.tetto_spesa_mensile" type="number" min="0" step="0.01" class="w-full text-sm" placeholder="Nessun tetto" />
                                <InputError :message="form.errors.tetto_spesa_mensile" />
                                <p class="text-[11px] text-muted-foreground mt-1">
                                    Il programma rifiuta una nuova richiesta se, nel caso peggiore, farebbe superare il tetto. È una stima sul listino:
                                    imposta anche un limite nella Console Anthropic.
                                </p>
                            </div>
                            <div class="rounded-lg border bg-muted/20 p-4 text-sm">
                                <p class="font-medium mb-1 flex items-center gap-2"><Wallet class="w-4 h-4 text-primary" /> Questo mese</p>
                                <p>Spesa stimata: <strong>{{ formatoUsd(spesa.mese_usd) }}</strong>
                                    <span v-if="spesa.tetto_usd !== null"> su {{ formatoUsd(spesa.tetto_usd) }}</span></p>
                                <p class="text-muted-foreground">Richieste: {{ spesa.chiamate }}</p>
                            </div>
                        </div>

                        <!-- Prova connessione -->
                        <div class="border-t pt-4">
                            <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                                <Button type="button" variant="outline" @click="provaConnessione" :disabled="provaInCorso" class="gap-2 shrink-0">
                                    <Loader2 v-if="provaInCorso" class="w-4 h-4 animate-spin" />
                                    <PlugZap v-else class="w-4 h-4" />
                                    Prova connessione
                                </Button>
                                <span class="text-xs text-muted-foreground">
                                    Verifica chiave e modello senza consumare token. Usa la chiave scritta nel campo o, se vuoto, quella salvata.
                                </span>
                            </div>
                            <div v-if="esitoProva === 'ok'"
                                class="mt-3 p-3 bg-emerald-50 dark:bg-emerald-900/20 text-emerald-800 dark:text-emerald-300 rounded-md text-sm flex items-center gap-2 border border-emerald-200">
                                <ShieldCheck class="w-4 h-4 text-emerald-600" /> {{ messaggioProva }}
                            </div>
                            <div v-if="esitoProva === 'errore'"
                                class="mt-3 p-3 bg-rose-50 dark:bg-rose-900/20 text-rose-800 dark:text-rose-300 rounded-md text-sm flex items-start gap-2 border border-rose-200">
                                <AlertCircle class="w-4 h-4 text-rose-600 mt-0.5 shrink-0" /> {{ messaggioProva }}
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <!-- Informativa privacy -->
                <Card class="border shadow-none">
                    <CardContent class="pt-6 space-y-4 text-sm leading-relaxed">
                        <h3 class="text-base font-semibold flex items-center gap-2"><Lock class="w-4 h-4 text-primary" /> Informativa sul trattamento dei dati</h3>

                        <div>
                            <p class="font-medium">Quali dati vengono inviati</p>
                            <p class="text-muted-foreground">
                                Solo ciò che serve alla bozza richiesta: i punti all'ordine del giorno, le note di seduta scritte dall'amministratore,
                                i dati contabili già calcolati dal programma (importi, scostamenti, scadenze) e le indicazioni scritte da chi genera la bozza.
                                Non vengono mai inviati codici fiscali, partite IVA, IBAN, indirizzi e-mail e PEC, numeri di telefono, dati di nascita o di documenti:
                                il programma li toglie anche se compaiono nel testo libero.
                            </p>
                        </div>

                        <div>
                            <p class="font-medium">Pseudonimizzazione</p>
                            <p class="text-muted-foreground">
                                Prima dell'invio nomi, cognomi, ragioni sociali, indirizzi e il nome del condominio vengono sostituiti con codici
                                (per esempio <code>[PERSONA_1]</code>, <code>[AZIENDA_1]</code>). La corrispondenza fra codici e nomi resta solo su questo server e
                                serve a ricomporre la bozza. I dati pseudonimizzati restano comunque dati personali: in un condominio piccolo chi conosce
                                le persone potrebbe riconoscerle dal contesto. Nelle note non vanno scritte informazioni sulla salute o sulla vita privata.
                            </p>
                        </div>

                        <div>
                            <p class="font-medium">Chi riceve i dati e per quanto tempo</p>
                            <p class="text-muted-foreground">
                                I dati sono elaborati da Anthropic (per i clienti dello Spazio economico europeo, Anthropic Ireland, Limited) come responsabile
                                del trattamento secondo il Data Processing Addendum incorporato nei Commercial Terms, e non sono usati per addestrare i modelli.
                                Con l'uso standard dell'API Anthropic cancella richieste e risposte di norma entro 30 giorni; possono essere conservate più a lungo
                                (fino a 2 anni) se i sistemi di sicurezza le segnalano per una violazione delle norme d'uso. Lo schema della risposta resta in cache
                                fino a 24 ore. La conservazione zero (ZDR) è disponibile solo su accordo commerciale.
                            </p>
                        </div>

                        <div>
                            <p class="font-medium">Trasferimento fuori dall'Unione europea</p>
                            <p class="text-muted-foreground">
                                L'API diretta di Anthropic non offre l'elaborazione nell'UE: i dati sono elaborati negli Stati Uniti o in altre regioni.
                                Il trasferimento si basa sulle clausole contrattuali standard (SCC) incluse nel DPA.
                            </p>
                        </div>

                        <div>
                            <p class="font-medium">Cosa resta su questo server</p>
                            <p class="text-muted-foreground">
                                Per ogni richiesta il programma registra data, tipo di documento, modello, token, costo stimato, esito e un'impronta del contesto
                                inviato: mai il testo inviato o ricevuto. La chiave API è salvata cifrata e non viene mai mostrata.
                            </p>
                        </div>

                        <div>
                            <p class="font-medium">Revisione</p>
                            <p class="text-muted-foreground">
                                I dati personali vengono sostituiti da codici prima dell'invio; non inserire nomi o dati personali nei campi di testo libero.
                                Ogni bozza va riletta e approvata da una persona.
                            </p>
                        </div>

                        <ul class="flex flex-wrap gap-x-4 gap-y-1 text-xs">
                            <li><a class="underline inline-flex items-center gap-1" target="_blank" rel="noopener" href="https://www.anthropic.com/legal/commercial-terms">Commercial Terms <ExternalLink class="w-3 h-3" /></a></li>
                            <li><a class="underline inline-flex items-center gap-1" target="_blank" rel="noopener" href="https://www.anthropic.com/legal/data-processing-addendum">Data Processing Addendum <ExternalLink class="w-3 h-3" /></a></li>
                            <li><a class="underline inline-flex items-center gap-1" target="_blank" rel="noopener" href="https://www.anthropic.com/subprocessors">Sub-responsabili <ExternalLink class="w-3 h-3" /></a></li>
                            <li><a class="underline inline-flex items-center gap-1" target="_blank" rel="noopener" href="https://platform.claude.com/docs/en/manage-claude/api-and-data-retention">Conservazione dei dati API <ExternalLink class="w-3 h-3" /></a></li>
                            <li><a class="underline inline-flex items-center gap-1" target="_blank" rel="noopener" href="https://privacy.claude.com/en/articles/7996866-how-long-do-you-store-my-organization-s-data">Tempi di conservazione <ExternalLink class="w-3 h-3" /></a></li>
                            <li><a class="underline inline-flex items-center gap-1" target="_blank" rel="noopener" href="https://platform.claude.com/docs/en/manage-claude/data-residency">Luogo dell'elaborazione <ExternalLink class="w-3 h-3" /></a></li>
                        </ul>

                    </CardContent>

                    <CardFooter class="flex flex-col sm:flex-row items-center justify-between gap-4 border-t px-6 py-4 bg-slate-50/50 dark:bg-slate-900/50">
                        <span class="text-xs text-muted-foreground flex items-center gap-1">
                            <KeyRound class="w-3 h-3" /> La chiave scritta nel campo viene salvata cifrata e non torna più alla pagina.
                        </span>
                        <Button type="submit" :disabled="form.processing" class="w-full sm:w-auto gap-2">
                            <Save class="w-4 h-4" /> Salva impostazioni
                        </Button>
                    </CardFooter>
                </Card>
            </form>

            <!-- Ultimi utilizzi -->
            <Card v-if="utilizzi.length" class="border shadow-none">
                <CardContent class="pt-6">
                    <h3 class="text-sm font-semibold mb-3">Ultime richieste</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead class="text-left text-muted-foreground border-b">
                                <tr>
                                    <th class="py-2 pr-3 font-medium">Data</th>
                                    <th class="py-2 pr-3 font-medium">Documento</th>
                                    <th class="py-2 pr-3 font-medium">Modello</th>
                                    <th class="py-2 pr-3 font-medium text-right">Token (in/out)</th>
                                    <th class="py-2 pr-3 font-medium text-right">Costo stimato</th>
                                    <th class="py-2 font-medium">Esito</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="u in utilizzi" :key="u.id" class="border-b last:border-0">
                                    <td class="py-2 pr-3 whitespace-nowrap">{{ formatoData(u.data) }}</td>
                                    <td class="py-2 pr-3">{{ etichetteTipo[u.tipo_documento] ?? u.tipo_documento }}</td>
                                    <td class="py-2 pr-3"><code>{{ u.modello }}</code></td>
                                    <td class="py-2 pr-3 text-right tabular-nums">{{ u.token_ingresso.toLocaleString('it-IT') }} / {{ u.token_uscita.toLocaleString('it-IT') }}</td>
                                    <td class="py-2 pr-3 text-right tabular-nums">{{ formatoUsd(u.costo_usd) }}</td>
                                    <td class="py-2">
                                        <span class="px-2 py-0.5 border rounded-md whitespace-nowrap" :class="etichetteEsito[u.esito]?.classe">
                                            {{ etichetteEsito[u.esito]?.testo ?? u.esito }}
                                        </span>
                                        <span v-if="u.codice_errore" class="ml-1 text-muted-foreground">{{ u.codice_errore }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
