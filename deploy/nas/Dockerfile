# KondoManager - immagine "tutto in uno" (nginx + php-fpm 8.4 + coda + scheduler via supervisord)
# per NAS Synology x86-64 con poca RAM. Costruita da GitHub Actions e pubblicata su GHCR.

# ---------- Stage 1: dipendenze PHP ----------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts

# ---------- Stage 2: asset frontend ----------
FROM node:20-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
COPY --from=vendor /app/vendor ./vendor
RUN NODE_OPTIONS=--max-old-space-size=768 npm run build && rm -rf node_modules

# ---------- Stage 3: runtime ----------
FROM php:8.4-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx supervisor netcat-openbsd \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libonig-dev libxml2-dev libzip-dev libicu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j2 pdo_mysql mbstring exif pcntl bcmath gd zip intl opcache \
    && apt-get purge -y --auto-remove libpng-dev libjpeg62-turbo-dev libfreetype6-dev libonig-dev libxml2-dev libzip-dev libicu-dev \
    && apt-get install -y --no-install-recommends libpng16-16 libjpeg62-turbo libfreetype6 libonig5 libxml2 libzip4 libicu72 \
    && rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled/default

COPY deploy/nas/conf/php.ini      /usr/local/etc/php/conf.d/zz-kondomanager.ini
COPY deploy/nas/conf/www.conf     /usr/local/etc/php-fpm.d/zz-www.conf
COPY deploy/nas/conf/nginx.conf   /etc/nginx/conf.d/kondomanager.conf
COPY deploy/nas/conf/supervisord.conf /etc/supervisor/conf.d/kondomanager.conf
COPY deploy/nas/conf/entrypoint.sh /usr/local/bin/kondo-entrypoint
RUN chmod +x /usr/local/bin/kondo-entrypoint \
    && sed -i 's/^worker_processes.*/worker_processes 1;/' /etc/nginx/nginx.conf

WORKDIR /var/www
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build
RUN rm -rf Dockerfile* docker deploy tests node_modules .git .github \
    && cp -a storage /opt/storage-template \
    && rm -rf public/storage && ln -s /var/www/storage/app/public public/storage \
    && rm -f .env && ln -s /config/.env .env \
    && chown -R www-data:www-data /var/www /opt/storage-template

EXPOSE 80
ENV TZ=Europe/Rome
ENTRYPOINT ["/usr/local/bin/kondo-entrypoint"]
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]
