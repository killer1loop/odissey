#!/bin/sh

set -eu

curl --fail --silent --show-error --max-time 3 http://127.0.0.1:8000/up > /dev/null

running_processes="$(
    supervisorctl -c /etc/supervisor/conf.d/odissey.conf status 2>/dev/null \
        | awk '$2 == "RUNNING" { count++ } END { print count + 0 }'
)"

[ "${running_processes}" -eq 4 ]
