#!/bin/sh
set -eux

export DEBIAN_FRONTEND=noninteractive

apt-get update
apt-get install -y --no-install-recommends \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libmemcached-dev \
    libpng-dev \
    libpq-dev \
    libsasl2-dev \
    libssl-dev \
    libxml2-dev \
    libzip-dev \
    msmtp \
    zlib1g-dev

docker-php-ext-configure gd --with-freetype --with-jpeg

# OPcache is compiled into PHP 8.5 and is no longer built as a shared extension.
docker-php-ext-install -j"$(nproc)" \
    gd \
    zip \
    soap \
    exif \
    pgsql \
    mysqli \
    gettext \
    calendar \
    pdo_mysql \
    pdo_pgsql

pecl install \
    memcached \
    redis \
    xdebug

docker-php-ext-enable \
    memcached \
    redis \
    xdebug

rm -rf /tmp/pear ~/.pearrc /var/lib/apt/lists/*
