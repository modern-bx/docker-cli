# shellcheck shell=sh
# Keeps Xdebug CLI sessions on the same per-project port as browser sessions.

docker_cli_xdebug_refresh() {
    search_dir="$PWD"
    while [ "$search_dir" != / ]; do
        project_meta="$search_dir/.docker-cli.yaml"
        if [ -f "$project_meta" ]; then
            project_name="$(sed -n 's/^[[:space:]]*name:[[:space:]]*//p' "$project_meta" | head -n 1 | sed 's/["'"'"']//g')"
            project_file="$HOME/.config/docker-cli/projects/$project_name/project.yaml"
            project_port="$(sed -n 's/^[[:space:]]*port:[[:space:]]*//p' "$project_file" 2>/dev/null | head -n 1 | sed 's/["'"'"']//g')"
            case "$project_port" in
                ''|*[!0-9]*) unset XDEBUG_CONFIG ;;
                *) export XDEBUG_CONFIG="client_host=host.docker.internal client_port=$project_port idekey=PHPSTORM" ;;
            esac
            return
        fi
        search_dir="$(dirname "$search_dir")"
    done
    unset XDEBUG_CONFIG
}

cd() {
    if [ "$#" -eq 0 ]; then
        command cd || return
    else
        command cd "$@" || return
    fi
    docker_cli_xdebug_refresh
}

docker_cli_xdebug_refresh
