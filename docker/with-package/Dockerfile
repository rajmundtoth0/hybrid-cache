FROM php:8.3-cli

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends $PHPIZE_DEPS git unzip zip \
    && pecl channel-update pecl.php.net \
    && printf '\n' | pecl install apcu-5.1.24 \
    && docker-php-ext-enable apcu \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www

COPY . /opt/hybrid-cache

RUN composer create-project laravel/laravel demo "^12.0" --prefer-dist --no-interaction

WORKDIR /var/www/demo

RUN composer config repositories.hybrid-cache path /opt/hybrid-cache \
    && composer require rajmundtoth0/hybrid-cache:@dev predis/predis:^2.2 --no-interaction \
    && php /opt/hybrid-cache/docker/with-package/configure-cache-stores.php \
    && printf '\nAPP_ENV=local\nAPP_DEBUG=true\nSESSION_DRIVER=array\nQUEUE_CONNECTION=sync\nCACHE_STORE=redis\nREDIS_CLIENT=predis\nREDIS_HOST=redis\nHYBRID_CACHE_LOCAL_STORE=apc\nHYBRID_CACHE_DISTRIBUTED_STORE=redis\nHYBRID_CACHE_STALE_TTL=60\n' >> .env \
    && php artisan key:generate --force --ansi \
    && php artisan config:clear --ansi

COPY docker/with-package/routes.web.php /var/www/demo/routes/web.php

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
