<script setup lang="ts">
/**
 * Pagamento in contanti a un fornitore: la norma prima, la conferma dopo.
 *
 * Art. 1129 c. 7 c.c.: le somme pagate per conto del condominio transitano sul conto corrente
 * intestato al condominio. Per gli appalti con ritenuta del 4% c'è anche l'art. 25-ter c. 2-ter
 * DPR 600/1973, con sanzione. Il messaggio arriva dal server, che sa se il fornitore è un
 * appaltatore.
 *
 * È una conferma e non un divieto, come le altre finestre bypassabili del pagamento: un
 * pagamento in contanti già avvenuto va registrato com'è, altrimenti si finisce per registrarlo
 * come bonifico. La motivazione è obbligatoria e resta nel record.
 */
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Banknote, FileText, Lock, Scale } from 'lucide-vue-next';

const props = defineProps<{ open: boolean; messaggio: string }>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void; (e: 'conferma', nota: string): void }>();

const nota = ref('');
watch(() => props.open, (aperta) => { if (aperta) nota.value = ''; });

const conferma = () => {
    emit('conferma', nota.value.trim());
    emit('update:open', false);
};
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition-all duration-300 ease-out" enter-from-class="opacity-0 scale-95" enter-to-class="opacity-100 scale-100">
            <div v-if="props.open" class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
                <div class="bg-white dark:bg-slate-900 rounded-3xl shadow-2xl w-full max-w-xl overflow-hidden border border-amber-200 dark:border-amber-900/50">
                    <div class="bg-gradient-to-br from-amber-50 to-amber-100/50 dark:from-amber-950/40 dark:to-amber-900/20 px-8 pt-8 pb-6 text-center border-b border-amber-100 dark:border-amber-900/30">
                        <div class="w-16 h-16 bg-white dark:bg-amber-900/50 rounded-2xl flex items-center justify-center mx-auto mb-4 border border-amber-100 dark:border-amber-800">
                            <Banknote class="w-8 h-8 text-amber-600" />
                        </div>
                        <h3 class="font-black text-slate-800 dark:text-slate-100 text-xl mb-1">Pagamento in contanti a un fornitore</h3>
                        <span class="inline-flex items-center gap-1.5 text-[9px] font-black uppercase tracking-widest text-amber-700 dark:text-amber-400 bg-amber-100 dark:bg-amber-900/50 px-2.5 py-1 rounded-full border border-amber-200 dark:border-amber-800">
                            <Scale class="w-3 h-3" /> Art. 1129 c. 7 c.c.
                        </span>
                    </div>

                    <div class="p-8 space-y-5">
                        <p class="text-[12px] text-slate-700 dark:text-slate-300 leading-relaxed">{{ props.messaggio }}</p>

                        <div class="space-y-2">
                            <label class="text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-400 flex items-center gap-2">
                                <FileText class="w-3.5 h-3.5" />
                                Motivazione (obbligatoria per procedere)
                            </label>
                            <textarea
                                v-model="nota"
                                rows="3"
                                placeholder="Es: pagamento già avvenuto in contanti il giorno ..., ricevuta n. ..."
                                class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-4 py-3 text-sm text-slate-700 dark:text-slate-300 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-400 resize-none transition-all"
                            />
                            <p class="text-[10px] text-slate-400 flex items-center gap-1.5">
                                <Lock class="w-3 h-3" />
                                Salvata nel record del pagamento e nel log di sistema.
                            </p>
                        </div>

                        <div class="flex gap-3 pt-2">
                            <Button variant="ghost" @click="emit('update:open', false)"
                                class="flex-1 h-12 rounded-xl font-bold text-slate-500 hover:text-slate-800 hover:bg-slate-100 dark:hover:bg-slate-800">
                                Pago con bonifico
                            </Button>
                            <Button @click="conferma"
                                :disabled="nota.trim().length < 10"
                                class="flex-1 h-12 rounded-xl bg-amber-600 hover:bg-amber-700 disabled:bg-slate-300 disabled:cursor-not-allowed text-white font-black uppercase tracking-widest text-[11px] transition-all">
                                Registro comunque
                            </Button>
                        </div>
                        <p v-if="nota.trim().length > 0 && nota.trim().length < 10" class="text-[10px] text-amber-700 text-center">
                            La motivazione deve essere almeno 10 caratteri.
                        </p>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
