#!/bin/bash
# Avvio KondoManager (NAS): .env persistente in /config, storage persistente, migrazioni automatiche.
set -e
cd /var/www
ENVF=/config/.env
mkdir -p /config

as_www() { su -s /bin/bash www-data -c "cd /var/www && $*"; }
setenv() { # setenv KEY VALUE  -> scrive/aggiorna la chiave nel .env persistente
  local k="$1" v="$2"
  if grep -q "^${k}=" "$ENVF"; then sed -i "s|^${k}=.*|${k}=${v}|" "$ENVF"; else echo "${k}=${v}" >> "$ENVF"; fi
}

if [ ! -f "$ENVF" ]; then
  echo "[kondo] Primo avvio: creo $ENVF"
  KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
  cat > "$ENVF" <<ENV
APP_NAME=KondoManager
APP_ENV=production
APP_KEY=${KEY}
APP_DEBUG=false
APP_URL=${APP_URL:-http://localhost}
APP_LOCALE=it
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=it_IT
APP_USER_TIMEZONE=Europe/Rome
APP_MAINTENANCE_DRIVER=file
SCHEDULE_QUEUE_WORKER=false
BCRYPT_ROUNDS=12
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning
DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=kondomanager
DB_USERNAME=kondo
DB_PASSWORD=
TRUSTED_PROXIES=PRIVATE_SUBNETS
BACKUP_ENABLED=true
SESSION_DRIVER=file
SESSION_LIFETIME=240
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=file
MAIL_MAILER=log
MAIL_FROM_ADDRESS="noreply@condominio.local"
MAIL_FROM_NAME="\${APP_NAME}"
VITE_APP_NAME="\${APP_NAME}"
ENV
fi
# Valori governati dal compose (aggiornati a ogni avvio)
[ -n "$APP_URL" ]     && setenv APP_URL "$APP_URL"
[ -n "$DB_HOST" ]     && setenv DB_HOST "$DB_HOST"
[ -n "$DB_DATABASE" ] && setenv DB_DATABASE "$DB_DATABASE"
[ -n "$DB_USERNAME" ] && setenv DB_USERNAME "$DB_USERNAME"
[ -n "$DB_PASSWORD" ] && setenv DB_PASSWORD "$DB_PASSWORD"
chown www-data:www-data "$ENVF"; chmod 640 "$ENVF"

# Storage persistente: al primo avvio copia la struttura di base
if [ ! -d /var/www/storage/framework ]; then
  echo "[kondo] Inizializzo storage"
  cp -a /opt/storage-template/. /var/www/storage/
fi
mkdir -p storage/app/public storage/app/backups storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# Attesa database
DBH=$(grep '^DB_HOST=' "$ENVF" | cut -d= -f2)
for i in $(seq 1 90); do nc -z -w2 "$DBH" 3306 && break; echo "[kondo] attendo database ($i)"; sleep 2; done

as_www "php artisan package:discover --ansi" >/dev/null || true
as_www "php artisan optimize:clear" >/dev/null
as_www "php artisan migrate --force"

USERS=$(as_www "php artisan tinker --execute='echo \\App\\Models\\User::count();'" 2>/dev/null | tail -1 || echo 0)
if [ "$USERS" = "0" ]; then
  echo "[kondo] Database vuoto: seed iniziale"
  as_www "php artisan db:seed --force"
fi

VER=$(php -r '$c=require "/var/www/config/app.php"; echo $c["version"] ?? "x";' 2>/dev/null || echo x)
if [ "$(cat /config/.version 2>/dev/null)" != "$VER" ]; then
  echo "[kondo] Versione $VER: allineo ruoli e permessi"
  as_www "php artisan db:seed --class=RolesAndPermissionsSeeder --force" || true
  echo "$VER" > /config/.version
fi

if [ "${KONDO_DEV:-0}" = "1" ]; then
  # Anteprima: codice montato dall'esterno, nessuna cache, file PHP riletti a ogni richiesta
  echo "[kondo] Modalità anteprima: niente cache"
  setenv APP_DEBUG true
  printf 'opcache.validate_timestamps = 1\nopcache.revalidate_freq = 0\n' > /usr/local/etc/php/conf.d/zz-dev.ini
  as_www "php artisan optimize:clear" >/dev/null
else
  setenv APP_DEBUG false
  rm -f /usr/local/etc/php/conf.d/zz-dev.ini
  as_www "php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache" || as_www "php artisan optimize:clear"
fi
echo "[kondo] Pronto"
exec "$@"
