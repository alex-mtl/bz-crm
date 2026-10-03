#!/bin/sh
# Deploys the demo stand from the code already unpacked in this directory (docs/deployment/demo-server.md).
#
#   ./deploy/demo/deploy.sh            — update: dependencies, front-end, migrations; the demo world is kept
#   ./deploy/demo/deploy.sh --fresh    — the same, and the demo world is built anew (all data is lost)
#
# The .env of the stand is created once by ./deploy/demo/init-env.sh and never overwritten.
set -e
cd "$(dirname "$0")/../.."
DC=./deploy/demo/dc
# Artisan runs as www-data, like the application itself: files it creates stay writable for php-fpm.
ART="$DC exec -T -u www-data app php artisan"

[ -f .env ] || { echo "No .env — run ./deploy/demo/init-env.sh first." >&2; exit 1; }
# The asset server of development must not be referenced: the stand uses the built files.
rm -f public/hot
# Written by the application (uid 33 = www-data in the image).
sudo chown -R 33:33 storage bootstrap/cache

$DC build app
$DC up -d mysql redis
# vendor/ is installed by the app container on its first start; wait for it.
$DC up -d app
echo "Waiting for dependencies…"
i=0
until $DC exec -T app test -f vendor/autoload.php; do
    i=$((i + 1)); [ "$i" -lt 180 ] || { echo "vendor/ did not appear" >&2; exit 1; }
    sleep 5
done
$DC exec -T app composer install --no-interaction --prefer-dist --optimize-autoloader

$DC run --rm assets

# The demo world posts messages while it is built: the WebSocket server should be listening by then.
$DC up -d reverb

if [ "$1" = "--fresh" ]; then
    $ART migrate:fresh --seed --force
else
    $ART migrate --force
fi
$DC exec -T app php artisan storage:link >/dev/null 2>&1 || true
$ART view:clear >/dev/null

$DC up -d
$DC restart queue scheduler reverb >/dev/null
$DC ps --format 'table {{.Service}}\t{{.Status}}'
