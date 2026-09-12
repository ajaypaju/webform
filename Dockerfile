# Assets: the public form page's JS/CSS, served same-origin (I9). No node in the runtime image.
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json vite.config.js ./
RUN npm ci --no-audit --no-fund
COPY resources ./resources
RUN npm run build

FROM dunglas/frankenphp:1-php8.4

# WHY: pdo_pgsql for Postgres, redis for the cache-backed RateLimiter, pcntl for the consumer's signal handling,
# rdkafka for the Redpanda producer/consumer, zip for composer dist installs
RUN install-php-extensions pdo_pgsql redis pcntl rdkafka zip

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

# WHY: dependencies in their own layer so code edits don't reinstall vendor/
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY . .
COPY --from=assets /app/public/build ./public/build

RUN composer dump-autoload --optimize --no-interaction \
    && chmod -R a+rwX storage bootstrap/cache

EXPOSE 8000

CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=8000"]
