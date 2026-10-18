#!/usr/bin/env python3
"""Legge un report JUnit: annota i test falliti e fallisce solo per quelli non noti.

Uso: junit-annotations.py junit.xml [known-failures.txt]

Il report può contenere caratteri che XML 1.0 non ammette: un test che riceve una risposta
500 finisce nel messaggio di errore con la pagina d'errore, codici colore ANSI compresi
(ESC, 0x1B). Il parser li rifiuta e prima lo script moriva con un traceback, senza nessuna
annotazione: la CI era rossa e non diceva quale test. Per questo:

1. il testo si ripulisce dai caratteri non validi (anche scritti come riferimento `&#27;`)
   prima del parse;
2. se il parse fallisce comunque, si stampa un ::error con la causa, le righe attorno al
   punto dell'errore e i test falliti trovati con una ricerca testuale, e si esce con 1.
"""
import re
import sys
import xml.etree.ElementTree as ET

# Caratteri ammessi da XML 1.0: #x9 | #xA | #xD | [#x20-#xD7FF] | [#xE000-#xFFFD] | [#x10000-#x10FFFF]
CARATTERI_NON_VALIDI = re.compile('[^\t\n\r\x20-퟿-�\U00010000-\U0010ffff]')
RIFERIMENTO_NUMERICO = re.compile(r'&#(x[0-9a-fA-F]+|[0-9]+);')


def esc(t: str) -> str:
    return t.replace('%', '%25').replace('\r', '').replace('\n', '%0A')


def valido(cp: int) -> bool:
    return (cp in (0x9, 0xA, 0xD) or 0x20 <= cp <= 0xD7FF
            or 0xE000 <= cp <= 0xFFFD or 0x10000 <= cp <= 0x10FFFF)


def pulisci(testo: str) -> str:
    """Toglie i caratteri che XML 1.0 non ammette, letterali o come riferimento numerico."""
    def rif(m):
        v = m.group(1)
        try:
            cp = int(v[1:], 16) if v[0] in 'xX' else int(v)
        except ValueError:
            return '?'
        return m.group(0) if valido(cp) else '?'

    return CARATTERI_NON_VALIDI.sub('?', RIFERIMENTO_NUMERICO.sub(rif, testo))


def righe_utili(testo: str, errore: Exception) -> str:
    """Le righe attorno al punto del parse fallito, più i test falliti trovati col testo."""
    righe = testo.splitlines()
    parti = []
    pos = getattr(errore, 'position', None)
    if pos:
        n = pos[0]
        da, a = max(0, n - 3), min(len(righe), n + 2)
        parti.append(f"Righe {da + 1}-{a}:")
        parti += [r[:300] for r in righe[da:a]]
    falliti = re.findall(r'<testcase\b[^>]*\bname="([^"]*)"[^>]*>\s*<(?:failure|error)\b', testo)
    if falliti:
        parti.append(f"Test falliti trovati nel testo ({len(falliti)}):")
        parti += falliti[:30]
    if not parti:
        parti = [r[:300] for r in righe if r.strip()][:20]
    return '\n'.join(parti)


def main() -> int:
    if len(sys.argv) < 2:
        print("::error::uso: junit-annotations.py junit.xml [known-failures.txt]")
        return 1

    known = []
    if len(sys.argv) > 2:
        with open(sys.argv[2], encoding='utf-8') as f:
            known = [l.strip() for l in f if l.strip() and not l.startswith('#')]

    try:
        with open(sys.argv[1], 'rb') as f:
            grezzo = f.read().decode('utf-8', errors='replace')
    except OSError as e:
        print(f"::error title=Report JUnit illeggibile::{esc(str(e))}")
        return 1

    testo = pulisci(grezzo)
    try:
        # In byte: la dichiarazione `encoding=` del file resta valida per il parser.
        root = ET.fromstring(testo.encode('utf-8'))
    except ET.ParseError as e:
        print(f"::error title=Report JUnit non leggibile come XML::{esc(str(e) + chr(10) + righe_utili(testo, e))[:3500]}")
        return 1

    failed = []
    for case in root.iter('testcase'):
        for tag in ('failure', 'error'):
            el = case.find(tag)
            if el is not None:
                name = f"{case.get('class') or case.get('classname') or ''} > {case.get('name')}"
                failed.append((name, (el.text or el.get('message') or '').strip()))

    nuovi = [(n, t) for n, t in failed if not any(k in n for k in known)]
    noti = [n for n, _ in failed if any(k in n for k in known)]
    print(f"Test falliti: {len(failed)} (noti dalla release ufficiale: {len(noti)}, nuovi: {len(nuovi)})")
    for n in noti:
        print(f"::warning title=Fallimento noto della release ufficiale::{esc(n)}")
    for name, text in nuovi[:40]:
        print(f"::error title={esc(name)[:200]}::{esc(chr(10).join(text.splitlines()[:25]))[:3500]}")
    return 1 if nuovi else 0


if __name__ == '__main__':
    sys.exit(main())
