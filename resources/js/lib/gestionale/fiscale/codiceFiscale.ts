/**
 * Lettura del codice fiscale di una persona fisica: sesso, data e codice catastale di nascita.
 *
 * Serve solo a **suggerire** e controllare i dati anagrafici chiesti dalla Certificazione Unica:
 * il secolo è ambiguo (si sceglie il più recente che non porti nel futuro) e l'omocodia sostituisce
 * le cifre con lettere, che qui si riconvertono. È lo specchio di
 * `App\Services\Gestionale\Fiscale\AnagraficaPercipiente::datiDaCodiceFiscale()`.
 */
const REGEX_PF = /^[A-Z]{6}[0-9LMNPQRSTUV]{2}[ABCDEHLMPRST][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]$/;
const MESI: Record<string, number> = { A: 1, B: 2, C: 3, D: 4, E: 5, H: 6, L: 7, M: 8, P: 9, R: 10, S: 11, T: 12 };
const OMOCODIA: Record<string, string> = { L: '0', M: '1', N: '2', P: '3', Q: '4', R: '5', S: '6', T: '7', U: '8', V: '9' };

const cifre = (s: string) => s.split('').map((c) => OMOCODIA[c] ?? c).join('');

export interface DatiDaCodiceFiscale {
    sesso: 'M' | 'F';
    /** ISO, `YYYY-MM-DD`. */
    dataNascita: string;
    codiceCatastale: string;
}

export function isCodiceFiscalePersonaFisica(cf: string | null | undefined): boolean {
    return !!cf && REGEX_PF.test(cf.trim().toUpperCase());
}

export function datiDaCodiceFiscale(cf: string | null | undefined, oggi: Date = new Date()): DatiDaCodiceFiscale | null {
    if (!isCodiceFiscalePersonaFisica(cf)) return null;

    const c = (cf as string).trim().toUpperCase();
    let anno = 2000 + Number(cifre(c.slice(6, 8)));
    if (anno > oggi.getFullYear()) anno -= 100;
    const mese = MESI[c[8]];
    let giorno = Number(cifre(c.slice(9, 11)));
    const sesso = giorno > 40 ? 'F' : 'M';
    if (giorno > 40) giorno -= 40;

    const data = new Date(Date.UTC(anno, mese - 1, giorno));
    if (data.getUTCMonth() !== mese - 1 || data.getUTCDate() !== giorno) return null;

    const pad = (n: number) => String(n).padStart(2, '0');

    return {
        sesso,
        dataNascita: `${anno}-${pad(mese)}-${pad(giorno)}`,
        codiceCatastale: c[11] + cifre(c.slice(12, 15)),
    };
}
