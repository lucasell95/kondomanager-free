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
[ -f nas.conf ] && . ./nas.conf
: "${APP_IP:?imposta APP_IP in nas.conf}" "${LAN_SUBNET:?imposta LAN_SUBNET}" "${LAN_GATEWAY:?imposta LAN_GATEWAY}"
APP_URL="${APP_URL:-http://$APP_HOSTNAME}"

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
docker run -d --name kondo_app --restart unless-stopped -m 700m \
  --label com.centurylinklabs.watchtower.enable=true \
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
