#!/usr/bin/env bash
hook_type=${1:-}
event=${2:-}
shift 2 || true
project=""
skip_next=0
for arg in "$@"; do
  if (( skip_next )); then
    skip_next=0
    continue
  fi
  case "$arg" in
    --language|--framework)
      skip_next=1
      ;;
    --language=*|--framework=*|--no-restart|--force)
      ;;
    --*)
      ;;
    *)
      project=$arg
      break
      ;;
  esac
done
if [[ -z $project ]]; then
  project=$(basename "$PWD")
fi
case "$event" in
  project:up:before|project:up:after)
    printf 'Хук %s видит, что проект %s запущен.\n' "$hook_type" "$project"
    ;;
  *)
    printf 'Команда %s не поддерживается этим хуком.\n' "$event"
    ;;
esac
