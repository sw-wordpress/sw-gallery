#!/usr/bin/env bash
# Playwright end-to-end run against a throw-away Docker stack (tests/e2e-env). See docs/development.md.
#
#   bin/test-e2e.sh [--keep] [--headed] [-- <extra playwright args>]
#
# --keep    leave the stack running afterwards (prints the clean-up command)
# --headed  show the browser
#
# Environment (all optional): SWG_E2E_PLUGIN_DIR (plugin copy to mount), SWG_E2E_WP_IMAGE (e.g. wordpress:6.4-php8.2-apache), SWG_E2E_CLI_IMAGE (matching wordpress:cli-phpX.Y),
# SWG_E2E_REBUILD=1 (force admin, block and frontend build / composer install). Never touches a real WordPress install or database:
# everything runs in containers and volumes of a unique compose project that is removed again, also on failure and CTRL-C.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_DIR="$ROOT/tests/e2e-env"
GEN_DIR="$ENV_DIR/.generated"
ENV_FILE="$GEN_DIR/e2e.env"

keep=0
headed=0
pw_args=()
while [ $# -gt 0 ]; do
  case "$1" in
    --keep) keep=1 ;;
    --headed) headed=1 ;;
    --) shift; pw_args=("$@"); break ;;
    -h | --help) sed -n '2,12p' "${BASH_SOURCE[0]}"; exit 0 ;;
    *) echo "unknown option: $1" >&2; exit 2 ;;
  esac
  shift
done

for tool in docker node npm curl openssl python3; do
  command -v "$tool" >/dev/null || { echo "missing prerequisite: $tool" >&2; exit 2; }
done
docker compose version >/dev/null 2>&1 || { echo "missing prerequisite: docker compose plugin" >&2; exit 2; }

# Plugin directory mounted read-only into WordPress (default: this checkout; override e.g. to test a patched copy).
PLUGIN_DIR="$(cd "${SWG_E2E_PLUGIN_DIR:-$ROOT}" && pwd)"
PROJECT="swg-e2e-$(od -An -N4 -tx1 /dev/urandom | tr -d ' \n')"
compose() { docker compose --project-name "$PROJECT" --env-file "$ENV_FILE" -f "$ENV_DIR/compose.yml" "$@"; }

cleanup() {
  local code=$?
  trap - EXIT INT TERM
  if [ -f "$ENV_FILE" ]; then
    if [ "$keep" = 1 ] && [ "$code" -ne 130 ]; then
      echo
      echo "Stack kept (project $PROJECT): $SWG_E2E_BASE_URL (admin / see $ENV_FILE)"
      echo "Remove it with: docker compose --project-name $PROJECT --env-file $ENV_FILE -f $ENV_DIR/compose.yml down -v --remove-orphans"
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
secret() { openssl rand -hex 12; }

# --- prerequisites: PHP dependencies (production only, in a helper container) and the built admin app -----------------
if [ ! -f "$ROOT/vendor/autoload.php" ] || [ "${SWG_E2E_REBUILD:-0}" = 1 ]; then
  echo "Installing production Composer dependencies in a helper container ..."
  docker run --rm --user "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer -v "$ROOT:/app" -w /app composer:2 \
    install --no-dev --no-interaction --prefer-dist --optimize-autoloader --ignore-platform-reqs
fi
if [ ! -f "$ROOT/assets/admin/.vite/manifest.json" ] || [ "${SWG_E2E_REBUILD:-0}" = 1 ]; then
  echo "Building the admin app ..."
  (cd "$ROOT/apps/admin" && { [ -d node_modules ] || npm ci; } && npm run build)
fi
# The block editor script and the frontend assets (layout CSS, lightbox) are generated as well and are mounted read-only.
if [ ! -f "$ROOT/assets/blocks/build/index.js" ] || [ "${SWG_E2E_REBUILD:-0}" = 1 ]; then
  echo "Building the block editor script ..."
  (cd "$ROOT/assets/blocks" && { [ -d node_modules ] || npm ci; } && npm run build)
fi
if [ ! -f "$ROOT/assets/build/.vite/manifest.json" ] || [ "${SWG_E2E_REBUILD:-0}" = 1 ]; then
  echo "Building the frontend assets ..."
  (cd "$ROOT/assets" && { [ -d node_modules ] || npm ci; } && npm run build)
fi
echo "Preparing the Playwright project ..."
(cd "$ROOT/e2e" && npm ci --no-audit --no-fund && npx playwright install chromium)

# --- generated, git-ignored environment (ports and throw-away credentials per run) ---------------------------------------
mkdir -p "$GEN_DIR"
umask 077
PORT="$(free_port)"
PORT_NOBUILD="$(free_port)"
while [ "$PORT_NOBUILD" = "$PORT" ]; do PORT_NOBUILD="$(free_port)"; done
cat >"$ENV_FILE" <<EOF
SWG_E2E_PROJECT=$PROJECT
SWG_E2E_PLUGIN_DIR=$PLUGIN_DIR
SWG_E2E_PORT=$PORT
SWG_E2E_PORT_NOBUILD=$PORT_NOBUILD
SWG_E2E_BASE_URL=http://localhost:$PORT
SWG_E2E_BASE_URL_NOBUILD=http://localhost:$PORT_NOBUILD
SWG_E2E_PW_ADMIN=$(secret)
SWG_E2E_PW_EDITOR=$(secret)
SWG_E2E_PW_AUTHOR=$(secret)
SWG_E2E_PW_CONTRIBUTOR=$(secret)
SWG_E2E_PW_SUBSCRIBER=$(secret)
SWG_E2E_PW_GERMAN=$(secret)
EOF
[ -n "${SWG_E2E_WP_IMAGE:-}" ] && echo "SWG_E2E_WP_IMAGE=$SWG_E2E_WP_IMAGE" >>"$ENV_FILE"
[ -n "${SWG_E2E_CLI_IMAGE:-}" ] && echo "SWG_E2E_CLI_IMAGE=$SWG_E2E_CLI_IMAGE" >>"$ENV_FILE"
echo "SWG_E2E_ENV_FILE=$ENV_FILE" >>"$ENV_FILE"
set -a
# shellcheck disable=SC1090
. "$ENV_FILE"
set +a

# --- stack ----------------------------------------------------------------------------------------------------------------
echo "Starting stack $PROJECT on $SWG_E2E_BASE_URL (no-build instance: $SWG_E2E_BASE_URL_NOBUILD) ..."
compose up --detach --wait --wait-timeout 180 wordpress wordpress-nobuild
compose run --rm wpcli

wait_for() {
  local url="$1" i
  for i in $(seq 1 60); do
    if [ "$(curl -s -o /dev/null -w '%{http_code}' "$url/wp-login.php")" = 200 ]; then return 0; fi
    sleep 1
  done
  echo "not ready: $url" >&2
  compose logs --no-log-prefix --tail 200 2>&1 | grep -v -E ' "(GET|POST|OPTIONS|HEAD) ' | tail -30 >&2 || true
  return 1
}
# Chromium aborts requests with ERR_NETWORK_CHANGED when a network interface appears/disappears (e.g. the veth of the
# finished provisioning container); wait until the interface list has been stable for a few seconds.
wait_for_stable_interfaces() {
  local previous="" current stable=0 i
  for i in $(seq 1 60); do
    current="$(ls /sys/class/net 2>/dev/null | sort | tr '\n' ' ')"
    if [ "$current" = "$previous" ]; then stable=$((stable + 1)); else stable=0; fi
    [ "$stable" -ge 4 ] && return 0
    previous="$current"
    sleep 1
  done
}
wait_for_stable_interfaces
wait_for "$SWG_E2E_BASE_URL"
wait_for "$SWG_E2E_BASE_URL_NOBUILD"

# --- tests ---------------------------------------------------------------------------------------------------------------
cd "$ROOT/e2e"
rm -rf playwright-report test-results .auth screenshots
set +e
if [ "$headed" = 1 ]; then
  npx playwright test --headed "${pw_args[@]}"
else
  npx playwright test "${pw_args[@]}"
fi
code=$?
set -e
echo
echo "Playwright report: $ROOT/e2e/playwright-report/index.html (traces/screenshots of failures: $ROOT/e2e/test-results, review screenshots: $ROOT/e2e/screenshots)"
if [ "$code" -ne 0 ]; then
  compose logs --no-log-prefix --tail 200 wordpress 2>&1 | grep -v -E ' "(GET|POST|OPTIONS|HEAD) ' | tail -20 >&2 || true
fi
exit "$code"
