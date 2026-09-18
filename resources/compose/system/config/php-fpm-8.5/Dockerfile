FROM php:8.5-fpm

ARG PHP_ENABLE_XDEBUG=1
ARG PHP_ENABLE_SPX=1

COPY scripts/install-extensions.sh /usr/local/bin/docker-cli-install-extensions
COPY scripts/setup-runtime-user.sh /usr/local/bin/docker-cli-setup-runtime-user
COPY scripts/setup-xdebug-cli.sh /usr/local/bin/docker-cli-setup-xdebug-cli
COPY msmtprc /etc/msmtprc

RUN set -eux; \
    chmod 0644 /etc/msmtprc; \
    chmod +x /usr/local/bin/docker-cli-install-extensions /usr/local/bin/docker-cli-setup-runtime-user /usr/local/bin/docker-cli-setup-xdebug-cli; \
    PHP_ENABLE_XDEBUG="$PHP_ENABLE_XDEBUG" PHP_ENABLE_SPX="$PHP_ENABLE_SPX" docker-cli-install-extensions
