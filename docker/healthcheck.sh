#!/bin/sh

set -eu

curl --fail --silent --show-error --max-time 3 http://127.0.0.1:8000/up > /dev/null

status="$(
    supervisorctl -c /etc/supervisor/conf.d/odissey.conf status 2>/dev/null || true
)"

# Critical programs must be RUNNING; the finite queue workers restart on
# their own --max-time cadence, so tolerate one worker in a restart window
# and retry once before failing to avoid flapping the container unhealthy.
for attempt in 1 2; do
    running_processes="$(
        printf '%s\n' "${status}" \
            | awk '$2 == "RUNNING" { count++ } END { print count + 0 }'
    )"
    fatal_processes="$(
        printf '%s\n' "${status}" \
            | awk '$2 == "FATAL" || $2 == "BACKOFF" || $2 == "EXITED" { count++ } END { print count + 0 }'
    )"

    if [ "${fatal_processes}" -gt 0 ]; then
        printf 'Supervisor reports a dead program.\n' >&2
        exit 1
    fi

    if [ "${running_processes}" -ge 13 ]; then
        exit 0
    fi

    if [ "${attempt}" -lt 2 ]; then
        sleep 3
        status="$(
            supervisorctl -c /etc/supervisor/conf.d/odissey.conf status 2>/dev/null || true
        )"
    fi
done

printf 'Too few Supervisor programs running: %s\n' "${running_processes}" >&2
exit 1
