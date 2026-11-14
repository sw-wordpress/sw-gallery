#!/usr/bin/env bash
# Selects the WordPress version of the WordPress test suites (tests/wp, johnpbloch/wordpress-core + wp-phpunit/wp-phpunit).
#
#   SWG_WP_VERSION='6.4.*' bin/wp-version.sh          install that version into tests/wp/vendor (also: bin/wp-version.sh '6.4.*')
#   bin/wp-version.sh trunk                           development version (dev-master of both packages; CI treats it as experimental)
#   bin/wp-version.sh latest                          newest stable release
#   bin/wp-version.sh reset                           back to the committed tests/wp/composer.json + composer.lock (default suite)
#
# The version is any Composer constraint (6.4.*, 6.9.3, ^6.9). The script changes tests/wp/composer.json and composer.lock in the working
# tree (CI does the same in a throw-away checkout); `bin/wp-version.sh reset` restores both. See docs/development.md.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP_DIR="$ROOT/tests/wp"
ARG="${1:-${SWG_WP_VERSION:-}}"
[ -n "$ARG" ] || { sed -n '2,11p' "${BASH_SOURCE[0]}" >&2; exit 2; }

cd "$WP_DIR"
if [ "$ARG" = reset ]; then
  git -C "$ROOT" checkout -- tests/wp/composer.json tests/wp/composer.lock
  composer install --prefer-dist --no-interaction --no-progress
  exit 0
fi

case "$ARG" in
  trunk) CONSTRAINT=dev-master ;;
  latest) CONSTRAINT='*' ;;
  *) CONSTRAINT="$ARG" ;;
esac
# Only constraint characters: the value ends up in composer.json.
[[ "$CONSTRAINT" =~ ^[A-Za-z0-9.*^~,\ \|-]+$ ]] || { echo "unsupported version constraint: $CONSTRAINT" >&2; exit 2; }

echo "WordPress test environment: johnpbloch/wordpress-core and wp-phpunit/wp-phpunit at '$CONSTRAINT'"
composer require --dev --no-update --no-interaction "johnpbloch/wordpress-core:$CONSTRAINT" "wp-phpunit/wp-phpunit:$CONSTRAINT"
composer update johnpbloch/wordpress-core wp-phpunit/wp-phpunit --prefer-dist --no-interaction --no-progress
composer show johnpbloch/wordpress-core | grep -E '^versions'
