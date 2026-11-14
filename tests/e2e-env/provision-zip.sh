#!/bin/sh
# Runs inside the wp-cli container (see compose.zip.yml): core install and installation of the release ZIP exactly as an
# administrator would do it (wp plugin install <zip>), then activation.
set -eu

cd /var/www/html
wp() { command wp --path=/var/www/html "$@"; }

echo "Waiting for the database ..."
i=0
until wp db check >/dev/null 2>&1; do
  i=$((i + 1))
  [ "$i" -gt 60 ] && { echo "database not reachable" >&2; exit 1; }
  sleep 2
done

wp core install --url="$SWG_E2E_BASE_URL" --title="SWG ZIP" --admin_user=admin \
  --admin_password="$SWG_E2E_PW_ADMIN" --admin_email=admin@example.test --skip-email
wp option update timezone_string UTC >/dev/null

wp plugin install /zip/plugin.zip
wp plugin activate sw-gallery
wp plugin list --format=csv --fields=name,status,version
echo "Provisioning done."
