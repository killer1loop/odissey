#!/bin/sh

set -eu

data_path="${ODISSEY_DATA_PATH:-/var/lib/odissey}"
database_path="${DB_DATABASE:-${data_path}/database.sqlite}"
transcode_path="${ODISSEY_TRANSCODE_PATH:-/var/cache/odissey/transcodes}"
app_key_path="${ODISSEY_APP_KEY_FILE:-${data_path}/app.key}"

validate_managed_path() {
    variable_name="$1"
    candidate="$2"

    case "${candidate}" in
        ""|/|/app|/app/|/var|/var/)
            echo "${variable_name} must point to a dedicated directory, not ${candidate:-an empty path}." >&2
            exit 1
            ;;
        /*) ;;
        *)
            echo "${variable_name} must be an absolute path inside the container." >&2
            exit 1
            ;;
    esac

    case "${candidate}" in
        *//*|*/./*|*/.|*/../*|*/..)
            echo "${variable_name} must be normalized without '.', '..', or repeated slash segments." >&2
            exit 1
            ;;
    esac
}

validate_managed_file_path() {
    variable_name="$1"
    candidate="$2"

    case "${candidate}" in
        ""|/|/app|/app/|/var|/var/)
            echo "${variable_name} must point to a dedicated file, not ${candidate:-an empty path}." >&2
            exit 1
            ;;
        /*) ;;
        *)
            echo "${variable_name} must be an absolute path inside the container." >&2
            exit 1
            ;;
    esac

    case "${candidate}" in
        *//*|*/./*|*/.|*/../*|*/..)
            echo "${variable_name} must be normalized without '.', '..', or repeated slash segments." >&2
            exit 1
            ;;
    esac

    if [ -L "${candidate}" ] || [ -d "${candidate}" ]; then
        echo "${variable_name} must not be a symlink or directory." >&2
        exit 1
    fi
}

validate_managed_path ODISSEY_DATA_PATH "${data_path}"
validate_managed_path ODISSEY_TRANSCODE_PATH "${transcode_path}"
validate_managed_file_path DB_DATABASE "${database_path}"
validate_managed_file_path ODISSEY_APP_KEY_FILE "${app_key_path}"

restore_marker="${database_path}.restore-in-progress"
if [ -L "${restore_marker}" ] || [ -e "${restore_marker}" ]; then
    echo "An interrupted Odissey restore was detected at ${restore_marker}." >&2
    echo "Keep the service offline and recover a matching database/key pair before removing the marker." >&2
    exit 1
fi

database_had_data=false
if [ -s "${database_path}" ]; then
    database_had_data=true
fi

mkdir -p \
    "${data_path}" \
    "$(dirname "${database_path}")" \
    "$(dirname "${app_key_path}")" \
    "${transcode_path}" \
    bootstrap/cache \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

if [ ! -f "${database_path}" ]; then
    if [ "$(id -u)" -eq 0 ]; then
        install -o www-data -g www-data -m 0660 /dev/null "${database_path}"
    else
        install -m 0660 /dev/null "${database_path}"
    fi
fi

if [ -z "${APP_KEY:-}" ]; then
    if [ ! -s "${app_key_path}" ]; then
        if [ "${database_had_data}" = true ]; then
            echo "APP_KEY is unavailable while ${database_path} contains application data." >&2
            echo "Restore the matching key or provide APP_KEY; refusing to generate a replacement." >&2
            exit 1
        fi

        app_key_temp="${app_key_path}.tmp.$$"
        umask 077
        php -r 'fwrite(STDOUT, "base64:".base64_encode(random_bytes(32)));' > "${app_key_temp}"
        if [ "$(id -u)" -eq 0 ]; then
            chown www-data:www-data "${app_key_temp}"
        fi
        chmod 0600 "${app_key_temp}"
        mv "${app_key_temp}" "${app_key_path}"
    fi

    APP_KEY="$(tr -d '\r\n' < "${app_key_path}")"
    export APP_KEY
    ODISSEY_APP_KEY_SOURCE=file
else
    ODISSEY_APP_KEY_SOURCE=environment
fi
export ODISSEY_APP_KEY_SOURCE

if [ "$(id -u)" -eq 0 ]; then
    chown -R www-data:www-data \
        "${data_path}" \
        "${transcode_path}" \
        bootstrap/cache \
        storage
else
    for writable_path in \
        "${data_path}" \
        "${transcode_path}" \
        bootstrap/cache \
        storage
    do
        if [ ! -w "${writable_path}" ]; then
            echo "${writable_path} must be writable by the container user." >&2
            exit 1
        fi
    done
fi

if [ "$(id -u)" -eq 0 ]; then
    gosu www-data php artisan migrate --force --no-interaction
    gosu www-data php artisan media:sources:scan --recover-interrupted --no-interaction
    gosu www-data php artisan media:captions:prune-unconfigured --no-interaction
    gosu www-data php artisan iptv:catalog:refresh --recover-upgrade --no-interaction
    gosu www-data php artisan optimize

    exec gosu www-data "$@"
fi

php artisan migrate --force --no-interaction
php artisan media:sources:scan --recover-interrupted --no-interaction
php artisan media:captions:prune-unconfigured --no-interaction
php artisan iptv:catalog:refresh --recover-upgrade --no-interaction
php artisan optimize

exec "$@"
