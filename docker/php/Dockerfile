FROM php:8.3-fpm-alpine

# UID пользователя хоста: файлы, созданные в контейнере (var/, vendor/, миграции),
# будут принадлежать вам, а не root.
ARG UID=1000

RUN apk add --no-cache icu-libs libpq libzip rabbitmq-c shadow \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libpq-dev libzip-dev rabbitmq-c-dev linux-headers \
    && docker-php-ext-install -j"$(nproc)" intl opcache pdo_pgsql zip \
    && pecl install amqp-2.1.2 \
    && docker-php-ext-enable amqp \
    && apk del .build-deps \
    && rm -rf /tmp/pear \
    && usermod -u "${UID}" www-data

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

ENV COMPOSER_HOME=/tmp/composer

WORKDIR /app
USER www-data
