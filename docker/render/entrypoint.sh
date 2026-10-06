#!/bin/sh
set -eu

PORT="${PORT:-10000}"
export PORT

if [ -z "${APP_URL:-}" ]; then
    if [ -n "${RENDER_EXTERNAL_URL:-}" ]; then
        APP_URL="$RENDER_EXTERNAL_URL"
    else
        echo "APP_URL is required (or set RENDER_EXTERNAL_URL)." >&2
        exit 1
    fi
fi

APP_URL="$(php -r '
$parts = parse_url($argv[1]);
if ($parts === false || ! isset($parts["scheme"], $parts["host"])) {
    fwrite(STDERR, "APP_URL must be an absolute URL.\n");
    exit(1);
}
$host = $parts["host"];
if (str_contains($host, ":") && $host[0] !== "[") {
    $host = "[" . $host . "]";
}
echo $parts["scheme"] . "://" . $host . rtrim($parts["path"] ?? "", "/");
' "$APP_URL")"
export APP_URL

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is required. Set a Laravel-formatted base64 key in the Render dashboard." >&2
    exit 1
fi

if [ "${EPHEMERA_STORAGE_DISK:-local}" = "s3" ]; then
    for variable in AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_BUCKET AWS_ENDPOINT AWS_DEFAULT_REGION; do
        case "$variable" in
            AWS_ACCESS_KEY_ID) value="${AWS_ACCESS_KEY_ID:-}" ;;
            AWS_SECRET_ACCESS_KEY) value="${AWS_SECRET_ACCESS_KEY:-}" ;;
            AWS_BUCKET) value="${AWS_BUCKET:-}" ;;
            AWS_ENDPOINT) value="${AWS_ENDPOINT:-}" ;;
            AWS_DEFAULT_REGION) value="${AWS_DEFAULT_REGION:-}" ;;
        esac
        if [ -z "$value" ]; then
            echo "EPHEMERA_STORAGE_DISK=s3 requires $variable to be set in the Render dashboard." >&2
            exit 1
        fi
    done
fi

envsubst '${PORT}' < /etc/nginx/http.d/default.conf.template > /etc/nginx/http.d/default.conf

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

exec /usr/bin/supervisord -c /etc/supervisord.conf
