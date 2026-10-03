#!/bin/sh
# Creates the .env of the demo stand once, with fresh random secrets. Prints no secret.
#
#   ./deploy/demo/init-env.sh <public-ip> <public-hostname>
set -e
cd "$(dirname "$0")/../.."
[ -f .env ] && { echo ".env exists — not touched."; exit 0; }
[ $# -eq 2 ] || { echo "usage: $0 <public-ip> <public-hostname>" >&2; exit 1; }
IP=$1
HOST=$2

secret() { head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c "$1"; }

cp .env.example .env
set_key() {
    # key=value, replacing the line of .env.example or appending it.
    if grep -q "^$1=" .env; then
        sed -i "s|^$1=.*|$1=$2|" .env
    else
        echo "$1=$2" >> .env
    fi
}

set_key APP_ENV demo
set_key APP_DEBUG false
# HTTPS lives on the IP: Let's Encrypt refuses names under amazonaws.com (see the Caddyfile).
set_key APP_URL "https://$IP"
set_key APP_KEY "base64:$(head -c 32 /dev/urandom | base64)"
set_key LOG_LEVEL info
set_key DB_PASSWORD "$(secret 32)"
set_key DB_ROOT_PASSWORD "$(secret 32)"
set_key REVERB_APP_ID "$(secret 8)"
set_key REVERB_APP_KEY "$(secret 24)"
set_key REVERB_APP_SECRET "$(secret 32)"
set_key REVERB_HOST reverb
set_key REVERB_PORT 8080
set_key REVERB_SCHEME http
set_key REVERB_CLIENT_HOST "$IP"
set_key REVERB_CLIENT_PORT 443
set_key REVERB_CLIENT_SCHEME https
# Letters of the demo world go nowhere: its addresses do not exist.
set_key MAIL_MAILER log
set_key DEMO_USER_PASSWORD "$(secret 20)"
set_key DEMO_TOTP_SECRET "$(head -c 20 /dev/urandom | base32 | tr -d '=')"
set_key SEED_ADMIN_PASSWORD "$(secret 24)"
set_key TRUSTED_PROXIES '*'
set_key DEMO_HOSTNAME "$HOST"
set_key DEMO_IP "$IP"

# Readable by the application (www-data, uid 33) and by the deploying user's group; nobody else.
sudo chown 33:"$(id -gn)" .env
chmod 640 .env
echo ".env created for $HOST ($IP)."
