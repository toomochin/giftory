FROM node:24-bookworm-slim AS frontend
WORKDIR /build/frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run build

FROM composer:2 AS composer

FROM php:8.3-cli-bookworm
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader
COPY . .
COPY --from=frontend /build/public/app ./public/app
RUN touch .env.testing \
    && composer dump-autoload --optimize --no-interaction
ENTRYPOINT ["sh", "/app/docker/start.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
