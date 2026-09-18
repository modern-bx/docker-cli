FROM php:8.4-fpm

ARG PHP_ENABLE_XDEBUG=1
ARG PHP_ENABLE_SPX=1

COPY php-fpm-8.4/spx-web-ui.patch /usr/local/src/spx-web-ui.patch
COPY php-spx/settings.css /usr/local/src/spx-settings.css
COPY php-fpm-8.4/scripts/install-extensions.sh /usr/local/bin/docker-cli-install-extensions
COPY php-fpm-8.4/scripts/setup-runtime-user.sh /usr/local/bin/docker-cli-setup-runtime-user
COPY php-fpm-8.4/scripts/setup-xdebug-cli.sh /usr/local/bin/docker-cli-setup-xdebug-cli
COPY php-fpm-8.4/msmtprc /etc/msmtprc

RUN set -eux; \
    chmod 0644 /etc/msmtprc; \
    chmod +x /usr/local/bin/docker-cli-install-extensions /usr/local/bin/docker-cli-setup-runtime-user /usr/local/bin/docker-cli-setup-xdebug-cli; \
    PHP_ENABLE_XDEBUG="$PHP_ENABLE_XDEBUG" PHP_ENABLE_SPX="$PHP_ENABLE_SPX" docker-cli-install-extensions
