FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    git unzip curl libzip-dev zip \
    && docker-php-ext-install zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

RUN mkdir -p storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

COPY . .

RUN chmod -R 775 storage bootstrap/cache

RUN composer install --no-dev --optimize-autoloader --no-interaction

RUN cp .env.example .env || true

RUN php artisan key:generate --force || true

RUN mkdir -p database && touch database/database.sqlite

RUN php artisan migrate --force || true

RUN php artisan optimize:clear || true
RUN php artisan config:cache || true
RUN php artisan view:cache || true
RUN php artisan route:cache || true

EXPOSE 10000

CMD php artisan optimize:clear && php artisan serve --host=0.0.0.0 --port=$PORT