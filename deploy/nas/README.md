# KondoManager su NAS Synology (immagine precompilata)

Variante dell'installazione Docker pensata per NAS con poca RAM (es. DS218+ con 2 GB):
l'immagine viene costruita da GitHub Actions (`.github/workflows/nas.yml`) e pubblicata su GHCR;
il NAS si limita a scaricarla. Watchtower aggiorna automaticamente il container dell'app
quando viene pubblicata una nuova immagine.

- `Dockerfile`: immagine "tutto in uno" (nginx + php-fpm 8.4 + queue worker + scheduler).
- `conf/`: configurazioni PHP, nginx, supervisord ed entrypoint (migrazioni automatiche, `.env` persistente in `/config`).
- `install.sh`: crea rete interna + macvlan (IP dedicato in LAN), MariaDB tarato per poca RAM, app e Watchtower.
- `nas.conf.example`: parametri di rete da copiare in `nas.conf` (non versionato).

Dati persistenti in `data/` accanto allo script: `db/`, `storage/`, `config/` (contiene `.env` con `APP_KEY`).
Password del database generate al primo avvio in `.secrets` (permessi 600).

## Modalità sviluppo

Con `DEV_CODE_DIR="codice"` in `nas.conf` il container monta `app`, `config`, `database`, `lang`,
`resources`, `routes` e `public/build` da quella cartella, con `KONDO_DEV=1` (niente cache, PHP riletto
a ogni richiesta) e senza Watchtower. Per aggiornare in blocco: caricare `codice.tar.gz` e un file
`azione` contenente `codice`, poi eseguire lo script (svuota le cache e lancia le migrazioni).
I singoli file si possono anche sovrascrivere direttamente nella cartella.
