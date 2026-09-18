#!/bin/sh
set -eux

export DEBIAN_FRONTEND=noninteractive

apt-get update
apt-get install -y --no-install-recommends \
    curl \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libldap2-dev \
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
docker-php-ext-configure ldap --with-libdir="lib/$(dpkg-architecture --query DEB_HOST_MULTIARCH)"

# OPcache is compiled into PHP 8.5 and is no longer built as a shared extension.
docker-php-ext-install -j"$(nproc)" \
    gd \
    ldap \
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
    redis

docker-php-ext-enable \
    memcached \
    redis

if [ "${PHP_ENABLE_XDEBUG:-1}" = "1" ]; then
    pecl install xdebug
    docker-php-ext-enable xdebug
fi

if [ "${PHP_ENABLE_SPX:-1}" = "1" ]; then
    apt-get install -y --no-install-recommends $PHPIZE_DEPS
    curl -fsSL https://github.com/NoiseByNorthwest/php-spx/archive/refs/tags/v0.4.22.tar.gz \
        | tar -xz -C /tmp
    cd /tmp/php-spx-0.4.22
    phpize
    ./configure
    make -j"$(nproc)"
    make install
    docker-php-ext-enable spx
    cd /
    apt-get purge -y --auto-remove $PHPIZE_DEPS
    rm -rf /tmp/php-spx-0.4.22
fi

rm -rf /tmp/pear ~/.pearrc /var/lib/apt/lists/*
