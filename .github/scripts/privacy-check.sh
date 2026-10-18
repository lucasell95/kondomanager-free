#!/bin/bash
# Controllo privacy generico sul codice aggiunto rispetto alla release ufficiale di riferimento.
# Blocca: codici fiscali italiani, IBAN italiani, indirizzi IP privati, email non di esempio,
# identità dei commit non "noreply". Nessun dato reale è contenuto in questo script.
# Dati fittizi ammessi nei test: email @test.it / @example.*, codici fiscali che iniziano con TSTTST.
set -u
BASE="${1:?base commit}"; HEAD="${2:-HEAD}"
fail=0
added=$(git diff "$BASE" "$HEAD" -- . ':(exclude)resources/data/**' ':(exclude)*.lock' ':(exclude)package-lock.json' | grep '^+' | grep -v '^+++')
check() { local label="$1" re="$2"; local hits; hits=$(printf '%s\n' "$added" | grep -nE "$re" | grep -v 'TSTTST' | head -5); if [ -n "$hits" ]; then echo "::error::$label"; echo "$hits"; fail=1; fi; }
check "Codice fiscale italiano nel codice aggiunto" '\b[A-Z]{6}[0-9LMNPQRSTUV]{2}[ABCDEHLMPRST][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]\b'
check "IBAN italiano nel codice aggiunto" '\bIT[0-9]{2} ?[A-Z] ?[0-9]{5} ?[0-9]{5} ?[0-9A-Z]{12}\b'
check "Indirizzo IP privato nel codice aggiunto" '\b(192\.168|10\.[0-9]+|172\.(1[6-9]|2[0-9]|3[01]))\.[0-9]+\.[0-9]+\b'
mails=$(printf '%s\n' "$added" | grep -oiE '[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}' | grep -viE 'users\.noreply\.github\.com|noreply@anthropic\.com|@example\.(com|org|net|it)|@test\.it|@km\.com|@kondomanager\.(it|com)|@condominio\.local|@localhost' | sort -u)
if [ -n "$mails" ]; then echo "::error::Email reali nel codice aggiunto"; echo "$mails"; fail=1; fi
ids=$(git log --format='%ae%n%ce' "$BASE..$HEAD" | sort -u | grep -vE 'users\.noreply\.github\.com$|^noreply@github\.com$')
if [ -n "$ids" ]; then echo "::error::Commit con email non noreply"; echo "$ids"; fail=1; fi
[ $fail -eq 0 ] && echo "Privacy check OK ($BASE..$HEAD)"
exit $fail
