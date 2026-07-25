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

validate_managed_path ODISSEY_DATA_PATH "${data_path}"
validate_managed_path ODISSEY_TRANSCODE_PATH "${transcode_path}"

case "${database_path}" in
    /*) ;;
    *)
        echo "DB_DATABASE must be an absolute path inside the container." >&2
        exit 1
        ;;
esac

mkdir -p \
    "${data_path}" \
    "$(dirname "${database_path}")" \
    "${transcode_path}" \
    bootstrap/cache \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

if [ ! -f "${database_path}" ]; then
    install -o www-data -g www-data -m 0660 /dev/null "${database_path}"
fi

if [ -z "${APP_KEY:-}" ]; then
    if [ ! -s "${app_key_path}" ]; then
        app_key_temp="${app_key_path}.tmp.$$"
        umask 077
        php -r 'fwrite(STDOUT, "base64:".base64_encode(random_bytes(32)));' > "${app_key_temp}"
        chown www-data:www-data "${app_key_temp}"
        chmod 0600 "${app_key_temp}"
        mv "${app_key_temp}" "${app_key_path}"
    fi

    APP_KEY="$(tr -d '\r\n' < "${app_key_path}")"
    export APP_KEY
fi

chown -R www-data:www-data \
    "${data_path}" \
    "${transcode_path}" \
    bootstrap/cache \
    storage

gosu www-data php artisan migrate --force --no-interaction
gosu www-data php artisan optimize

exec "$@"
