FROM php:8.2-fpm

COPY scripts/install-extensions.sh /usr/local/bin/docker-cli-install-extensions
COPY scripts/setup-runtime-user.sh /usr/local/bin/docker-cli-setup-runtime-user

RUN set -eux; \
    chmod +x /usr/local/bin/docker-cli-install-extensions /usr/local/bin/docker-cli-setup-runtime-user; \
    docker-cli-install-extensions
