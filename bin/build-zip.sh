#!/usr/bin/env bash
# Builds the reproducible release ZIP dist/sw-gallery-<version>.zip (top-level folder sw-gallery/). See docs/release.md.
#
#   bin/build-zip.sh [output-dir]        default output dir: dist/
#
# Steps: version check -> npm ci + build of apps/admin, assets, assets/blocks (clean state) -> translation check ->
# staging copy of exactly the production files -> `composer install --no-dev --classmap-authoritative` in the staging
# copy (the working vendor/ is never used) -> normalised mtimes/modes -> zip -X -9 from a sorted file list.
# Runs on a stock GitHub runner: bash, zip, node/npm, php, composer, msgfmt (gettext). No Docker.
#
# Environment: SOURCE_DATE_EPOCH (mtime of every entry; default: time of the last commit, else of sw-gallery.php),
#   SWG_ZIP_REUSE_NODE_MODULES=1  skip `npm ci` (local speed-up only; releases always install from the lockfiles).
# The script never modifies tracked files; it fails if the working tree changed during the build.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$ROOT/dist}"
SLUG=sw-gallery

die() { echo "build-zip: $*" >&2; exit 1; }
step() { echo ">> $*"; }

for tool in zip unzip node npm php composer msgfmt find sort touch; do
  command -v "$tool" >/dev/null || die "missing prerequisite: $tool"
done

step "checking the version"
"$ROOT/bin/version.sh" check
VERSION="$("$ROOT/bin/version.sh" get)"

if [ -z "${SOURCE_DATE_EPOCH:-}" ]; then
  if SOURCE_DATE_EPOCH="$(git -C "$ROOT" log -1 --format=%ct 2>/dev/null)" && [ -n "$SOURCE_DATE_EPOCH" ]; then :; else
    SOURCE_DATE_EPOCH="$(stat -c %Y "$ROOT/sw-gallery.php")"
  fi
fi
export SOURCE_DATE_EPOCH
step "SOURCE_DATE_EPOCH=$SOURCE_DATE_EPOCH"

tree_state() { git -C "$ROOT" status --porcelain 2>/dev/null || true; }
STATE_BEFORE="$(tree_state)"

# --- frontend builds from a clean state -------------------------------------------------------------------------
# Block JSON catalog: WordPress names it by the md5 of the script path (assets/blocks/build/index.js), not of its content,
# so the build order does not matter for it; bin/i18n.sh check below proves that the shipped catalogs are current.
for project in apps/admin assets assets/blocks; do
  step "building $project"
  if [ "${SWG_ZIP_REUSE_NODE_MODULES:-0}" != 1 ]; then
    (cd "$ROOT/$project" && npm ci --no-audit --no-fund --loglevel=error)
  fi
  (cd "$ROOT/$project" && npm run build --silent)
done
[ -f "$ROOT/assets/admin/.vite/manifest.json" ] || die "admin app build output missing (assets/admin/.vite/manifest.json)"
[ -f "$ROOT/assets/build/.vite/manifest.json" ] || die "frontend build output missing (assets/build/.vite/manifest.json)"
[ -f "$ROOT/assets/blocks/build/index.js" ] || die "block editor build output missing (assets/blocks/build/index.js)"

step "checking the translations"
"$ROOT/bin/i18n.sh" check

# --- staging ----------------------------------------------------------------------------------------------------
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
PKG="$STAGE/$SLUG"
mkdir -p "$PKG"

copy_file() { # relative path
  [ -f "$ROOT/$1" ] || die "required file missing: $1"
  mkdir -p "$PKG/$(dirname "$1")"
  cp "$ROOT/$1" "$PKG/$1"
}
copy_tree() { # relative directory, find expression(s) selecting files
  local dir="$1"
  shift
  [ -d "$ROOT/$dir" ] || die "required directory missing: $dir"
  (cd "$ROOT" && find "$dir" -type f "$@" -print0) | while IFS= read -r -d '' file; do
    mkdir -p "$PKG/$(dirname "$file")"
    cp "$ROOT/$file" "$PKG/$file"
  done
}

step "staging production files"
copy_file sw-gallery.php
copy_file uninstall.php
copy_file CHANGELOG.md
copy_file LICENSE
copy_file THIRD-PARTY-NOTICES.md
# The GitHub README is not shipped; a wordpress.org readme.txt would be (none exists yet).
if [ -f "$ROOT/readme.txt" ]; then copy_file readme.txt; fi
copy_tree packages -name '*.php'
copy_tree languages \( -name '*.pot' -o -name '*.po' -o -name '*.json' \)
copy_tree assets/build ! -name '*.map'
copy_tree assets/admin ! -name '*.map'
copy_file assets/blocks/block.json
copy_tree assets/blocks/build ! -name '*.map'
copy_file assets/media-library/media-library.js
copy_file assets/js/sw-gallery-updates.js

# Compiled catalogs come from the .po files of this very tree (the committed .mo is only a development convenience).
for po in "$PKG"/languages/*.po; do
  msgfmt --check-format -o "${po%.po}.mo" "$po"
done

# --- production Composer dependencies ---------------------------------------------------------------------------
step "installing production Composer dependencies into the staging copy"
RUNTIME_DEPS="$(php -r '
  $c = json_decode((string) file_get_contents($argv[1]), true);
  foreach (array_keys($c["require"] ?? []) as $name) {
    if ($name !== "php" && !str_starts_with($name, "ext-")) { echo $name, " "; }
  }' "$ROOT/composer.json")"
if [ -z "$RUNTIME_DEPS" ]; then
  echo "   no runtime dependencies (only php/ext requirements): no Mozart prefixing needed, shipping the generated autoloader only"
else
  die "composer.json has runtime dependencies ($RUNTIME_DEPS): add Mozart prefixing (SWGallery\\Dependencies\\) to this script before releasing (TODO M5-09)"
fi
cp "$ROOT/composer.json" "$ROOT/composer.lock" "$PKG/"
(cd "$PKG" && composer install --no-dev --classmap-authoritative --no-interaction --no-progress --no-scripts --quiet)
rm -f "$PKG/composer.json" "$PKG/composer.lock"
[ -f "$PKG/vendor/autoload.php" ] || die "vendor/autoload.php was not generated"
# Composer's metadata would carry the development reference and paths; keep the runtime files only.
rm -f "$PKG/vendor/composer/installed.json"
rm -rf "$PKG/vendor/bin"

# --- normalise and zip ------------------------------------------------------------------------------------------
step "normalising modes and timestamps"
find "$PKG" -type d -exec chmod 755 {} +
find "$PKG" -type f -exec chmod 644 {} +
find "$STAGE" -exec touch -h -d "@$SOURCE_DATE_EPOCH" {} +

mkdir -p "$OUT"
ZIP="$(cd "$OUT" && pwd)/$SLUG-$VERSION.zip"
# zip -r would update an existing archive and keep stale entries: always start from scratch.
rm -f "$ZIP"
step "zipping $ZIP"
(cd "$STAGE" && find "$SLUG" -type f | LC_ALL=C sort | TZ=UTC zip -X -9 -q "$ZIP" -@)

STATE_AFTER="$(tree_state)"
[ "$STATE_BEFORE" = "$STATE_AFTER" ] || die "the working tree changed during the build (tracked or untracked files): $(diff <(echo "$STATE_BEFORE") <(echo "$STATE_AFTER") | head -5)"

echo "ZIP:     $ZIP"
echo "Files:   $(unzip -Z1 "$ZIP" | wc -l)"
echo "Size:    $(stat -c %s "$ZIP") bytes"
echo "SHA-256: $(sha256sum "$ZIP" | cut -d' ' -f1)"
