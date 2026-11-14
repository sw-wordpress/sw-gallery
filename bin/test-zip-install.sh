#!/usr/bin/env bash
# Install test of a release ZIP in a throw-away WordPress (Docker), see docs/release.md.
#
#   bin/test-zip-install.sh <dist/sw-gallery-<version>.zip> [--keep]
#
# 1. bin/verify-zip.sh on the ZIP; 2. fresh WordPress + MariaDB (tests/e2e-env/compose.zip.yml, random port, own compose project);
# 3. `wp plugin install <zip>` + activate (the source tree is NOT mounted); 4. Playwright smoke run (e2e/zip/, playwright.zip.config.ts):
# admin app mounts, gallery created and rendered by the shortcode with the lightbox, block editor script, no failing plugin request;
# 5. PHP notices/warnings/fatals of the plugin in wp-content/debug.log fail the run. The stack is removed in every case.
# Environment (optional): SWG_E2E_WP_IMAGE / SWG_E2E_CLI_IMAGE (e.g. wordpress:6.4-php8.2-apache + wordpress:cli-php8.2).
# Needs Docker (compose plugin), node, npm, curl, openssl, python3, unzip. The normal bin/test-e2e.sh is not affected.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_DIR="$ROOT/tests/e2e-env"
GEN_DIR="$ENV_DIR/.generated"
ENV_FILE="$GEN_DIR/zip.env"

ZIP_ARG=""
keep=0
for arg in "$@"; do
  case "$arg" in
    --keep) keep=1 ;;
    -h | --help) sed -n '2,12p' "${BASH_SOURCE[0]}"; exit 0 ;;
    -*) echo "unknown option: $arg" >&2; exit 2 ;;
    *) ZIP_ARG="$arg" ;;
  esac
done
[ -n "$ZIP_ARG" ] || { echo "usage: $0 <zip> [--keep]" >&2; exit 2; }
[ -f "$ZIP_ARG" ] || { echo "no such file: $ZIP_ARG" >&2; exit 2; }
ZIP="$(cd "$(dirname "$ZIP_ARG")" && pwd)/$(basename "$ZIP_ARG")"
[[ "$(basename "$ZIP")" =~ ^sw-gallery-(.+)\.zip$ ]] || { echo "unexpected ZIP name (sw-gallery-<version>.zip)" >&2; exit 2; }
ZIP_VERSION="${BASH_REMATCH[1]}"

for tool in docker node npm curl openssl python3 unzip; do
  command -v "$tool" >/dev/null || { echo "missing prerequisite: $tool" >&2; exit 2; }
done
docker compose version >/dev/null 2>&1 || { echo "missing prerequisite: docker compose plugin" >&2; exit 2; }

echo ">> static verification"
"$ROOT/bin/verify-zip.sh" "$ZIP"

PROJECT="swg-zip-$(od -An -N4 -tx1 /dev/urandom | tr -d ' \n')"
compose() { docker compose --project-name "$PROJECT" --env-file "$ENV_FILE" -f "$ENV_DIR/compose.zip.yml" "$@"; }

cleanup() {
  local code=$?
  trap - EXIT INT TERM
  if [ -f "$ENV_FILE" ]; then
    if [ "$keep" = 1 ] && [ "$code" -ne 130 ]; then
      echo
      echo "Stack kept (project $PROJECT): $SWG_E2E_BASE_URL"
      echo "Remove it with: docker compose --project-name $PROJECT --env-file $ENV_FILE -f $ENV_DIR/compose.zip.yml down -v --remove-orphans"
    else
      echo "Tearing down $PROJECT ..."
      compose down --volumes --remove-orphans --timeout 5 >/dev/null 2>&1 || true
      rm -f "$ENV_FILE"
    fi
  fi
  exit "$code"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

free_port() { python3 -c 'import socket; s = socket.socket(); s.bind(("127.0.0.1", 0)); print(s.getsockname()[1]); s.close()'; }

echo ">> preparing the Playwright project"
(cd "$ROOT/e2e" && npm ci --no-audit --no-fund && npx playwright install chromium)

mkdir -p "$GEN_DIR"
umask 077
PORT="$(free_port)"
cat >"$ENV_FILE" <<EOF
SWG_E2E_PROJECT=$PROJECT
SWG_E2E_PORT=$PORT
SWG_E2E_BASE_URL=http://localhost:$PORT
SWG_E2E_PW_ADMIN=$(openssl rand -hex 12)
SWG_E2E_ZIP_FILE=$ZIP
SWG_E2E_ZIP_VERSION=$ZIP_VERSION
SWG_E2E_ENV_FILE=$ENV_FILE
EOF
[ -n "${SWG_E2E_WP_IMAGE:-}" ] && echo "SWG_E2E_WP_IMAGE=$SWG_E2E_WP_IMAGE" >>"$ENV_FILE"
[ -n "${SWG_E2E_CLI_IMAGE:-}" ] && echo "SWG_E2E_CLI_IMAGE=$SWG_E2E_CLI_IMAGE" >>"$ENV_FILE"
set -a
# shellcheck disable=SC1090
. "$ENV_FILE"
set +a

echo ">> starting $PROJECT on $SWG_E2E_BASE_URL and installing $(basename "$ZIP")"
compose up --detach --wait --wait-timeout 180 wordpress
compose run --rm wpcli

# Chromium aborts requests with ERR_NETWORK_CHANGED while Docker interfaces appear/disappear (see docs/development.md).
previous=""
stable=0
for _ in $(seq 1 60); do
  current="$(ls /sys/class/net 2>/dev/null | sort | tr '\n' ' ')"
  if [ "$current" = "$previous" ]; then stable=$((stable + 1)); else stable=0; fi
  [ "$stable" -ge 4 ] && break
  previous="$current"
  sleep 1
done
for _ in $(seq 1 60); do
  [ "$(curl -s -o /dev/null -w '%{http_code}' "$SWG_E2E_BASE_URL/wp-login.php")" = 200 ] && break
  sleep 1
done

echo ">> Playwright smoke run"
cd "$ROOT/e2e"
rm -rf playwright-report test-results .auth
set +e
npx playwright test -c playwright.zip.config.ts
code=$?
set -e

echo ">> PHP log of the installed plugin"
LOG="$(compose exec -T wordpress sh -c 'cat /var/www/html/wp-content/debug.log 2>/dev/null || true')"
PLUGIN_PROBLEMS="$(printf '%s\n' "$LOG" | grep -E 'PHP (Notice|Warning|Deprecated|Fatal error|Parse error|Strict Standards)' | grep 'plugins/sw-gallery/' || true)"
if [ -n "$PLUGIN_PROBLEMS" ]; then
  echo "PHP problems reported for sw-gallery:" >&2
  printf '%s\n' "$PLUGIN_PROBLEMS" | head -20 >&2
  code=1
else
  echo "no PHP notices, warnings or errors from sw-gallery in debug.log"
fi
exit "$code"
