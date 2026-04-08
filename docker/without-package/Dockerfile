FROM php:8.3-cli

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip zip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www

RUN composer create-project laravel/laravel demo "^12.0" --prefer-dist --no-interaction

WORKDIR /var/www/demo

RUN composer require predis/predis:^2.2 --no-interaction \
    && printf '\nAPP_ENV=local\nAPP_DEBUG=true\nSESSION_DRIVER=array\nQUEUE_CONNECTION=sync\nCACHE_STORE=redis\nREDIS_CLIENT=predis\nREDIS_HOST=redis\n' >> .env \
    && php artisan key:generate --force --ansi \
    && php artisan config:clear --ansi

COPY docker/without-package/routes.web.php /var/www/demo/routes/web.php

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
