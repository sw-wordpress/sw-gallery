#!/bin/sh
# Runs inside the wp-cli container (see compose.yml) against a fresh throw-away stack.
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

wp core install --url="$SWG_E2E_BASE_URL" --title="SWG E2E" --admin_user=admin \
  --admin_password="$SWG_E2E_PW_ADMIN" --admin_email=admin@example.test --skip-email
# German language pack. The wordpress.org translation API has a fixed 3 s timeout, which fails on slow networks:
# try it once, then download the pack directly (long timeout, retries).
install_de_pack() {
  wp eval '
    $version = get_bloginfo( "version" );
    require_once ABSPATH . "wp-admin/includes/file.php";
    WP_Filesystem();
    $zip = download_url( "https://downloads.wordpress.org/translation/core/{$version}/de_DE.zip", 180 );
    if ( is_wp_error( $zip ) ) { fwrite( STDERR, $zip->get_error_message() . "\n" ); exit( 1 ); }
    $result = unzip_file( $zip, WP_LANG_DIR );
    unlink( $zip );
    if ( is_wp_error( $result ) ) { fwrite( STDERR, $result->get_error_message() . "\n" ); exit( 1 ); }
  '
}
if ! wp language core install de_DE; then
  n=0
  until install_de_pack; do
    n=$((n + 1))
    [ "$n" -ge 5 ] && { echo "could not download the de_DE language pack" >&2; exit 1; }
    sleep 3
  done
fi
wp language core is-installed de_DE
wp option update timezone_string UTC >/dev/null

wp plugin activate sw-gallery

mkuser() { # login role password [locale]
  wp user create "$1" "$1@example.test" --role="$2" --user_pass="$3" >/dev/null
  if [ -n "${4:-}" ]; then
    wp user meta update "$1" locale "$4" >/dev/null
  fi
}
mkuser editor editor "$SWG_E2E_PW_EDITOR"
mkuser author author "$SWG_E2E_PW_AUTHOR"
mkuser contributor contributor "$SWG_E2E_PW_CONTRIBUTOR"
mkuser subscriber subscriber "$SWG_E2E_PW_SUBSCRIBER"
mkuser german editor "$SWG_E2E_PW_GERMAN" de_DE

wp plugin list --format=csv --fields=name,status,version
echo "Provisioning done."
