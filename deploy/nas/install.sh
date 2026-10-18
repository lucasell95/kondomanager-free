#!/bin/bash
# Installazione/aggiornamento di KondoManager su NAS Synology (DSM 7, pacchetto Docker).
# Da eseguire come root (Utilità di pianificazione DSM). Nessun dato o segreto in questo file:
#  - parametri di rete in nas.conf (accanto allo script, NON versionato)
#  - password del database generate al primo avvio in .secrets (solo root)
BASE="$(cd "$(dirname "$0")" && pwd)"
LOG="$BASE/install.log"
exec >>"$LOG" 2>&1
echo "===== $(date '+%F %T') avvio install.sh"
set -e
export PATH=/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin
cd "$BASE"

# --- Parametri (sovrascrivibili in nas.conf) ---
IMAGE="ghcr.io/OWNER/kondomanager-nas:latest"
APP_IP=""; APP_MAC=""; APP_HOSTNAME="condominio"; APP_URL=""
LAN_IF="eth0"; LAN_SUBNET=""; LAN_GATEWAY=""
WATCHTOWER_INTERVAL=300
# Modalità sviluppo: se DEV_CODE_DIR è valorizzato (es. "codice"), il codice dell'app viene montato da
# quella cartella e le modifiche caricate si vedono subito (niente cache, niente Watchtower).
DEV_CODE_DIR=""
DEV_MOUNTS="app config database lang resources routes public/build"
[ -f nas.conf ] && . ./nas.conf
: "${APP_IP:?imposta APP_IP in nas.conf}" "${LAN_SUBNET:?imposta LAN_SUBNET}" "${LAN_GATEWAY:?imposta LAN_GATEWAY}"
APP_URL="${APP_URL:-http://$APP_HOSTNAME}"

# --- Azione richiesta (file "azione" accanto allo script, letto una volta): install (default) | codice
AZIONE=install
if [ -f azione ]; then AZIONE="$(tr -d ' \r\n' < azione)"; rm -f azione; fi
echo "--- azione: $AZIONE"

# Codice per la modalità sviluppo: codice.tar.gz caricato accanto allo script sostituisce le cartelle montate
if [ -n "$DEV_CODE_DIR" ] && [ -f codice.tar.gz ]; then
  rm -rf "$DEV_CODE_DIR.new" && mkdir -p "$DEV_CODE_DIR.new" "$DEV_CODE_DIR"
  tar xzf codice.tar.gz -C "$DEV_CODE_DIR.new" && rm -f codice.tar.gz
  # Aggiornamento sul posto (rsync): i bind mount del container restano validi
  for d in $DEV_MOUNTS; do
    [ -d "$DEV_CODE_DIR.new/$d" ] || continue
    mkdir -p "$DEV_CODE_DIR/$d"
    rsync -a --delete "$DEV_CODE_DIR.new/$d/" "$DEV_CODE_DIR/$d/"
  done
  rm -rf "$DEV_CODE_DIR.new"
  chmod -R a+rX "$DEV_CODE_DIR"
  echo "--- codice aggiornato in $DEV_CODE_DIR"
fi
if [ "$AZIONE" = "codice" ]; then
  docker exec kondo_app su -s /bin/bash www-data -c "cd /var/www && php artisan optimize:clear && php artisan migrate --force"
  echo "===== $(date '+%F %T') fine install.sh (codice) OK"; exit 0
fi

# --- Segreti DB (una sola volta, solo root) ---
if [ ! -f .secrets ]; then
  umask 077
  echo "DB_PASS=$(openssl rand -hex 16)"   >  .secrets
  echo "ROOT_PASS=$(openssl rand -hex 16)" >> .secrets
  umask 022
fi
. ./.secrets
mkdir -p data/db data/storage data/config

# --- Immagini ---
docker pull "$IMAGE"
docker image inspect mariadb:10.11 >/dev/null 2>&1 || docker pull mariadb:10.11

# --- Reti: interna (app<->db) e macvlan (IP dedicato in LAN) ---
docker network inspect kondo_int >/dev/null 2>&1 || docker network create kondo_int
docker network inspect kondo_lan >/dev/null 2>&1 || docker network create -d macvlan \
  --subnet="$LAN_SUBNET" --gateway="$LAN_GATEWAY" --ip-range="${APP_IP}/32" -o parent="$LAN_IF" kondo_lan

# --- Database (ricreato solo se non esiste) ---
if ! docker container inspect kondo_db >/dev/null 2>&1; then
docker run -d --name kondo_db --restart unless-stopped --network kondo_int -m 320m \
  -v "$BASE/data/db:/var/lib/mysql" \
  -e TZ=Europe/Rome -e MARIADB_DATABASE=kondomanager -e MARIADB_USER=kondo \
  -e MARIADB_PASSWORD="$DB_PASS" -e MARIADB_ROOT_PASSWORD="$ROOT_PASS" \
  mariadb:10.11 \
  --innodb-buffer-pool-size=64M --innodb-log-buffer-size=8M --performance-schema=OFF \
  --max-connections=30 --key-buffer-size=8M --tmp-table-size=16M --max-heap-table-size=16M \
  --table-open-cache=400 --skip-name-resolve \
  --innodb-flush-log-at-trx-commit=2 --innodb-flush-method=O_DIRECT \
  --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci
fi

# --- Applicazione ---
docker rm -f kondo_app >/dev/null 2>&1 || true
MACOPT=""; [ -n "$APP_MAC" ] && MACOPT="--mac-address $APP_MAC"
DEVOPT=""; WTLABEL="--label com.centurylinklabs.watchtower.enable=true"
if [ -n "$DEV_CODE_DIR" ]; then
  [ -d "$DEV_CODE_DIR" ] || { echo "manca la cartella $DEV_CODE_DIR (caricare codice.tar.gz)"; exit 1; }
  DEVOPT="-e KONDO_DEV=1"; WTLABEL="--label com.centurylinklabs.watchtower.enable=false"
  for d in $DEV_MOUNTS; do [ -d "$DEV_CODE_DIR/$d" ] && DEVOPT="$DEVOPT -v $BASE/$DEV_CODE_DIR/$d:/var/www/$d:ro"; done
  echo "--- modalità sviluppo: $DEVOPT"
fi
docker run -d --name kondo_app --restart unless-stopped -m 700m \
  $WTLABEL $DEVOPT \
  --network kondo_lan --ip "$APP_IP" $MACOPT --hostname "$APP_HOSTNAME" \
  -e APP_URL="$APP_URL" -e DB_HOST=kondo_db -e DB_DATABASE=kondomanager \
  -e DB_USERNAME=kondo -e DB_PASSWORD="$DB_PASS" \
  -v "$BASE/data/storage:/var/www/storage" -v "$BASE/data/config:/config" \
  "$IMAGE"
docker network connect kondo_int kondo_app

# --- Aggiornamento automatico: Watchtower aggiorna SOLO i container con l'etichetta ---
docker rm -f kondo_watchtower >/dev/null 2>&1 || true
docker run -d --name kondo_watchtower --restart unless-stopped -m 64m \
  -v /var/run/docker.sock:/var/run/docker.sock \
  -e TZ=Europe/Rome \
  containrrr/watchtower --label-enable --cleanup --interval "$WATCHTOWER_INTERVAL"

# --- Verifica ---
for i in $(seq 1 60); do docker logs kondo_app 2>&1 | grep -q "\[kondo\] Pronto" && break; sleep 5; done
echo "--- log app"; docker logs --tail 40 kondo_app 2>&1
echo "--- stato"; docker ps -a --filter name=kondo_ --format '{{.Names}} {{.Status}} {{.Image}}'
echo "===== $(date '+%F %T') fine install.sh OK"
