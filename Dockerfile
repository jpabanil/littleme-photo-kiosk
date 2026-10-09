FROM node:22-alpine AS frontend
WORKDIR /src
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
RUN mkdir -p /backend/public && npm run build

FROM composer:2 AS vendor
WORKDIR /app
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts
COPY backend/ ./
RUN composer dump-autoload --optimize --no-dev

FROM dunglas/frankenphp:1-php8.4
RUN install-php-extensions mongodb gd

WORKDIR /app
COPY --from=vendor /app /app
COPY --from=frontend /backend/public/app /app/public/app
COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh \
    && printf 'upload_max_filesize=8M\npost_max_size=8M\nmemory_limit=512M\n' > /usr/local/etc/php/conf.d/uploads.ini

ENV SERVER_NAME=:8080
EXPOSE 8080
CMD ["/entrypoint.sh"]
