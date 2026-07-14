#!/bin/sh
set -eux

apk add --no-cache \
    cyrus-sasl \
    freetype \
    gettext \
    libjpeg-turbo \
    libmemcached-libs \
    libpng \
    libxml2 \
    libzip \
    postgresql-libs \
    zlib

apk add --no-cache --virtual .docker-cli-build-deps \
    ${PHPIZE_DEPS} \
    cyrus-sasl-dev \
    freetype-dev \
    gettext-dev \
    libjpeg-turbo-dev \
    libmemcached-dev \
    libpng-dev \
    libxml2-dev \
    libzip-dev \
    postgresql-dev \
    zlib-dev

docker-php-ext-configure gd --with-freetype --with-jpeg

docker-php-ext-install -j"$(nproc)" \
    gd \
    zip \
    soap \
    exif \
    pgsql \
    mysqli \
    opcache \
    gettext \
    calendar \
    pdo_mysql \
    pdo_pgsql

pecl install \
    memcached \
    redis

docker-php-ext-enable \
    memcached \
    redis

apk del .docker-cli-build-deps
rm -rf /tmp/pear ~/.pearrc
