FROM php:8.2-fpm

COPY scripts/install-extensions.sh /usr/local/bin/docker-cli-install-extensions
COPY scripts/setup-runtime-user.sh /usr/local/bin/docker-cli-setup-runtime-user
COPY scripts/setup-xdebug-cli.sh /usr/local/bin/docker-cli-setup-xdebug-cli
COPY msmtprc /etc/msmtprc

RUN set -eux; \
    chmod 0644 /etc/msmtprc; \
    chmod +x /usr/local/bin/docker-cli-install-extensions /usr/local/bin/docker-cli-setup-runtime-user /usr/local/bin/docker-cli-setup-xdebug-cli; \
    docker-cli-install-extensions
