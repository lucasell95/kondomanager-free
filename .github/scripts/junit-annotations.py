#!/usr/bin/env python3
"""Trasforma i test falliti di un report JUnit in annotazioni GitHub (visibili nel riepilogo del job)."""
import sys, xml.etree.ElementTree as ET

def esc(t: str) -> str:
    return t.replace('%', '%25').replace('\r', '').replace('\n', '%0A')

root = ET.parse(sys.argv[1]).getroot()
failed = []
for case in root.iter('testcase'):
    for tag in ('failure', 'error'):
        el = case.find(tag)
        if el is not None:
            name = f"{case.get('class') or case.get('classname') or ''} > {case.get('name')}"
            text = (el.text or el.get('message') or '').strip()
            failed.append((name, text))
print(f"Test falliti: {len(failed)}")
for name, _ in failed:
    print(" -", name)
for name, text in failed[:40]:
    lines = text.splitlines()[:25]
    print(f"::error title={esc(name)[:200]}::{esc(chr(10).join(lines))[:3500]}")
