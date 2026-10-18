#!/usr/bin/env python3
"""Legge un report JUnit: annota i test falliti e fallisce solo per quelli non noti.

Uso: junit-annotations.py junit.xml [known-failures.txt]
"""
import sys, xml.etree.ElementTree as ET

def esc(t: str) -> str:
    return t.replace('%', '%25').replace('\r', '').replace('\n', '%0A')

known = []
if len(sys.argv) > 2:
    with open(sys.argv[2], encoding='utf-8') as f:
        known = [l.strip() for l in f if l.strip() and not l.startswith('#')]

root = ET.parse(sys.argv[1]).getroot()
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
sys.exit(1 if nuovi else 0)
