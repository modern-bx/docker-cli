#!/bin/sh
set -eu

if [ -d /docker-cli/php/conf.d ]; then
    cp /docker-cli/php/conf.d/*.ini /usr/local/etc/php/conf.d/
fi

if [ -d /docker-cli/php-fpm.d ]; then
    rm -f /usr/local/etc/php-fpm.d/www.conf
    cp /docker-cli/php-fpm.d/*.conf /usr/local/etc/php-fpm.d/
fi

target_uid="${HOST_UID:-1000}"
target_gid="${HOST_GID:-1000}"

group_name="$(awk -F: -v gid="${target_gid}" '$3 == gid { print $1; exit }' /etc/group)"
if [ -z "${group_name}" ]; then
    group_name=docker-cli
    if command -v groupadd >/dev/null 2>&1; then
        groupadd --gid "${target_gid}" "${group_name}"
    else
        addgroup -g "${target_gid}" "${group_name}"
    fi
fi

user_name="$(awk -F: -v uid="${target_uid}" '$3 == uid { print $1; exit }' /etc/passwd)"
if [ -z "${user_name}" ]; then
    user_name=docker-cli
    if command -v useradd >/dev/null 2>&1; then
        useradd --uid "${target_uid}" --gid "${group_name}" --no-create-home --shell /usr/sbin/nologin "${user_name}"
    else
        adduser -D -H -u "${target_uid}" -G "${group_name}" "${user_name}"
    fi
fi

{
    echo '[www]'
    echo "user = ${user_name}"
    echo "group = ${group_name}"
} > /usr/local/etc/php-fpm.d/zz-runtime-user.conf

exec php-fpm
