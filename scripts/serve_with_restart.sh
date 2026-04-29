#!/usr/bin/env bash
set -euo pipefail

HOST="${HOST:-127.0.0.1}"
PORT="${PORT:-8000}"
RESTART_DELAY_SECONDS="${RESTART_DELAY_SECONDS:-1}"

stop_requested=0

handle_stop() {
    stop_requested=1
}

trap handle_stop INT TERM

while [[ "$stop_requested" -eq 0 ]]; do
    php artisan serve --host="$HOST" --port="$PORT" &
    child_pid=$!

    wait "$child_pid"
    exit_code=$?

    if [[ "$stop_requested" -ne 0 ]]; then
        exit "$exit_code"
    fi

    printf 'artisan serve exited with code %s; restarting in %ss...\n' "$exit_code" "$RESTART_DELAY_SECONDS" >&2
    sleep "$RESTART_DELAY_SECONDS"
done
