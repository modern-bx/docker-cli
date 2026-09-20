#!/bin/sh

set -eu

api=http://dbtrail:8090/api
prefix=docker-cli/

request() {
    method=$1
    path=$2
    body=${3-}
    if [ -n "$body" ]; then
        curl --fail --silent --show-error --request "$method" \
            --header "Authorization: Bearer $DBTRAIL_API_TOKEN" \
            --header "Content-Type: application/json" --data "$body" "$api$path"
        return
    fi
    curl --fail --silent --show-error --request "$method" \
        --header "Authorization: Bearer $DBTRAIL_API_TOKEN" "$api$path"
}

reconcile() {
    containers=$(docker ps --all --filter label=docker-cli.dbtrail-source=mysql --format '{{.Names}}' | sort)
    servers=$(request GET /servers)

    for container in $containers; do
        if [ "$(docker inspect --format '{{.State.Running}}' "$container")" != true ]; then
            continue
        fi
        docker exec --env DBTRAIL_MYSQL_USER="$DBTRAIL_MYSQL_USER" \
            --env DBTRAIL_MYSQL_PASSWORD="$DBTRAIL_MYSQL_PASSWORD" "$container" sh -ec \
            'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot --execute="CREATE USER IF NOT EXISTS '\''$DBTRAIL_MYSQL_USER'\''@'\''%'\'' IDENTIFIED BY '\''$DBTRAIL_MYSQL_PASSWORD'\''; ALTER USER '\''$DBTRAIL_MYSQL_USER'\''@'\''%'\'' IDENTIFIED BY '\''$DBTRAIL_MYSQL_PASSWORD'\''; GRANT SELECT, REPLICATION SLAVE, REPLICATION CLIENT ON *.* TO '\''$DBTRAIL_MYSQL_USER'\''@'\''%'\'';"'

        name=$prefix$container
        id=$(printf '%s' "$servers" | jq --arg name "$name" -r \
            '.servers[] | select(.name == $name) | .id' | head -n 1)
        if [ -z "$id" ]; then
            dsn="$DBTRAIL_MYSQL_USER:$DBTRAIL_MYSQL_PASSWORD@tcp($container:3306)/"
            created=$(request POST /servers "$(jq -cn --arg name "$name" --arg dsn "$dsn" \
                '{name: $name, source_dsn: $dsn, flavor: "mysql"}')")
            id=$(printf '%s' "$created" | jq -r '.id')
            request POST "/servers/$id/monitor/start" >/dev/null
            continue
        fi
        desired=$(printf '%s' "$servers" | jq --arg id "$id" -r \
            '.servers[] | select(.id == $id) | .monitor_desired // false')
        if [ "$desired" != true ]; then
            request POST "/servers/$id/monitor/start" >/dev/null
        fi
    done

    printf '%s' "$servers" | jq -r --arg prefix "$prefix" \
        '.servers[] | select(.name | startswith($prefix)) | [.id, .name] | @tsv' |
        while IFS="$(printf '\t')" read -r id name; do
            container=${name#"$prefix"}
            if printf '%s\n' "$containers" | grep --fixed-strings --line-regexp --quiet "$container"; then
                continue
            fi
            request POST "/servers/$id/monitor/stop" >/dev/null
            request DELETE "/servers/$id" >/dev/null
        done
}

until curl --fail --silent "$api/healthz" >/dev/null; do
    sleep 1
done

while true; do
    if ! reconcile; then
        echo "Не удалось синхронизировать выделенные MySQL с DBTrail." >&2
    fi
    sleep 60
done
