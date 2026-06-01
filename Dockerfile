# Stage 1: compile frontend assets
FROM node:20-alpine AS node-builder
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts
COPY resources/ resources/
COPY vite.config.js tailwind.config.js postcss.config.js ./
RUN npm run build

# Stage 2: FrankenPHP (single-process PHP+HTTP server, no S6-overlay required)
FROM dunglas/frankenphp:1-php8.4

# pdo_sqlite for the SQLite DB; unzip is required by Composer to extract packages
RUN apt-get update && apt-get install -y --no-install-recommends unzip && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_sqlite

# FrankenPHP doesn't bundle Composer — copy from the official image
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

# Layer-cache PHP deps separately from app code
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# Copy app (vendor/ excluded via .dockerignore, preserved from layer above)
COPY --chown=www-data:www-data . .

# Overwrite public/build with freshly compiled assets
COPY --from=node-builder --chown=www-data:www-data /app/public/build ./public/build

RUN composer dump-autoload --optimize

# Creates public/storage -> storage/app/public symlink (safe, no APP_KEY needed)
RUN php artisan storage:link

# Startup entrypoint: creates SQLite file on the mounted volume, runs migrations
# (idempotent), then starts FrankenPHP. No S6/PID-1 requirement.
USER root
RUN printf '#!/bin/sh\nset -e\nmkdir -p "$(dirname "${DB_DATABASE:-/var/www/html/storage/app/database.sqlite}")"\n[ -f "${DB_DATABASE:-/var/www/html/storage/app/database.sqlite}" ] || touch "${DB_DATABASE:-/var/www/html/storage/app/database.sqlite}"\nchown -R www-data:www-data /var/www/html/storage\ncd /var/www/html && php artisan migrate --force\nexec frankenphp run --config /etc/caddy/Caddyfile\n' \
    > /docker-entrypoint.sh && chmod +x /docker-entrypoint.sh

ENTRYPOINT ["/docker-entrypoint.sh"]
