#!/usr/bin/env sh
set -eu

if [ "${DB_CONNECTION:-}" = "pgsql" ]; then
  echo "Waiting for Postgres at ${DB_HOST:-db}:${DB_PORT:-5432}..."
  until nc -z "${DB_HOST:-db}" "${DB_PORT:-5432}"; do
    sleep 1
  done
fi

if [ ! -d vendor/laravel ]; then
  composer install
fi

exec "$@"
