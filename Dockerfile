FROM php:8.2-fpm-alpine AS base

RUN apk add --no-cache \
        icu-dev \
        libzip-dev \
        zip \
        unzip \
        git \
        $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" \
        intl \
        pdo_mysql \
        zip \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY docker/php/php.ini /usr/local/etc/php/conf.d/app.ini

ENV COMPOSER_PROCESS_TIMEOUT=600

COPY composer.json composer.lock* ./

RUN --mount=type=cache,target=/root/.composer/cache \
    composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-progress \
    || true

COPY . .

RUN --mount=type=cache,target=/root/.composer/cache \
    composer install \
        --no-dev \
        --optimize-autoloader \
        --no-progress

RUN mkdir -p var/cache var/log \
    && chown -R www-data:www-data var \
    && cp -a /var/www/html /var/www/html-image

COPY docker/php/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

COPY docker/php/crontab /etc/crontabs/root

EXPOSE 9000

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]
