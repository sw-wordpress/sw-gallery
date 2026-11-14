#!/usr/bin/env bash
# Static verification of a release ZIP (run by the release workflow after bin/build-zip.sh; see docs/release.md).
#
#   bin/verify-zip.sh <zip>
#
# Rules (all violations are reported, the exit code is 1 when there is at least one):
#   1. ZIP is intact, named sw-gallery-<semver>.zip, every entry lives below sw-gallery/ (no absolute paths, no "..").
#   2. Allowlist of top-level entries and of assets/* and assets/blocks/*.
#   3. Forbidden paths: tests, node_modules, VCS/CI/editor files, .env*, .npmrc, source maps (unless SWG_ZIP_ALLOW_SOURCEMAPS=1),
#      coverage, docs, bin, e2e, apps, TypeScript/Vue sources, lockfiles, composer.json, *.dist, dev dependencies (vendor/bin, phpunit, ...).
#   4. Content heuristics: private keys, GitHub/AWS/Slack tokens, npm auth tokens, absolute developer paths (/var/www, /home/<user>, C:\Users).
#   5. Header Version == file name version == SWGALLERY_VERSION; Plugin URI is not a placeholder.
#   6. Every PHP file lints (php -l) and the generated autoloader loads the plugin classes.
#   7. Required files exist: main file, uninstall.php, LICENSE, THIRD-PARTY-NOTICES.md, vendor/autoload.php, block.json, block and admin/frontend build with every file
#      the Vite manifests reference, a compiled .mo per .po, the block JSON catalog named after the md5 of assets/blocks/build/index.js.
#   8. Size budget: compressed size <= SWG_ZIP_MAX_BYTES (default 3 MiB; the current build is below 1 MiB).
# "TODO" markers are deliberately not checked: minified bundles and third-party code contain them legitimately.
set -uo pipefail

ZIP="${1:-}"
SLUG=sw-gallery
MAX_BYTES="${SWG_ZIP_MAX_BYTES:-3145728}"
errors=0
err() { echo "FAIL: $*" >&2; errors=$((errors + 1)); }

[ -n "$ZIP" ] || { echo "usage: $0 <zip>" >&2; exit 2; }
[ -f "$ZIP" ] || { echo "verify-zip: no such file: $ZIP" >&2; exit 2; }
for tool in unzip php md5sum grep; do
  command -v "$tool" >/dev/null || { echo "verify-zip: missing prerequisite: $tool" >&2; exit 2; }
done

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# 1. integrity, name, containment
unzip -tq "$ZIP" >/dev/null 2>&1 || { err "not a valid ZIP archive"; exit 1; }
NAME="$(basename "$ZIP")"
NAME_VERSION=""
if [[ "$NAME" =~ ^sw-gallery-([0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z]+(\.[0-9A-Za-z]+)*)?)\.zip$ ]]; then
  NAME_VERSION="${BASH_REMATCH[1]}"
else
  err "file name '$NAME' does not match sw-gallery-<semver>.zip"
fi
unzip -Z1 "$ZIP" >"$TMP/entries.txt"
[ -s "$TMP/entries.txt" ] || { err "ZIP is empty"; exit 1; }
if grep -Ev "^$SLUG/" "$TMP/entries.txt" | grep -q .; then
  err "entries outside $SLUG/: $(grep -Ev "^$SLUG/" "$TMP/entries.txt" | head -3 | tr '\n' ' ')"
fi
if grep -Eq '(^|/)\.\.(/|$)|^/' "$TMP/entries.txt"; then err "entries with absolute or parent-relative paths"; fi

# 2. allowlists
while IFS= read -r top; do
  case "$top" in
    sw-gallery.php | uninstall.php | CHANGELOG.md | LICENSE | THIRD-PARTY-NOTICES.md | readme.txt | packages | vendor | languages | assets) ;;
    *) err "top-level entry not allowed: $top" ;;
  esac
done < <(grep -E "^$SLUG/." "$TMP/entries.txt" | cut -d/ -f2 | sort -u)
ASSETS_OK="^$SLUG/assets/(admin/.+|build/.+|media-library/media-library\\.js|blocks/block\\.json|blocks/build/.+)$"
if bad="$(grep -E "^$SLUG/assets/" "$TMP/entries.txt" | grep -Ev "$ASSETS_OK" | grep -Ev '/$')" && [ -n "$bad" ]; then
  err "not allowed below assets/: $(echo "$bad" | head -3 | tr '\n' ' ')"
fi

# 3. forbidden paths
FORBIDDEN_DIR='(^|/)(tests?|Tests?|node_modules|coverage|docs|bin|e2e|apps|\.git|\.github|\.claude|\.vscode|\.idea|__tests__|\.phpunit\.cache)(/|$)'
FORBIDDEN_FILE='(^|/)(\.env[^/]*|\.npmrc|\.yarnrc[^/]*|\.gitignore|\.gitattributes|\.editorconfig|\.DS_Store|composer\.(json|lock)|package(-lock)?\.json|yarn\.lock|pnpm-lock\.yaml|phpunit[^/]*|phpcs[^/]*|phpstan[^/]*|sonar-project\.properties|TODO\.md|DONE\.md|AGENTS\.md|CODE-STYLE\.md|SPECIFICATION\.md|[^/]*\.dist|[^/]*\.log|[^/]*\.(ts|tsx|vue|scss|sh|bak|orig|swp))$'
if bad="$(grep -E "$FORBIDDEN_DIR" "$TMP/entries.txt")" && [ -n "$bad" ]; then
  err "forbidden directories: $(echo "$bad" | head -3 | tr '\n' ' ')"
fi
if bad="$(grep -E "$FORBIDDEN_FILE" "$TMP/entries.txt")" && [ -n "$bad" ]; then
  err "forbidden files: $(echo "$bad" | head -3 | tr '\n' ' ')"
fi
if [ "${SWG_ZIP_ALLOW_SOURCEMAPS:-0}" != 1 ] && grep -Eq '\.map$' "$TMP/entries.txt"; then
  err "source maps in the ZIP: $(grep -E '\.map$' "$TMP/entries.txt" | head -3 | tr '\n' ' ')"
fi
if grep -Eq "^$SLUG/vendor/(bin|phpunit|squizlabs|phpstan|wp-coding-standards|dealerdirect|phpcompatibility|szepeviktor|sebastian|myclabs|nikic|theseer|phar-io)" "$TMP/entries.txt"; then
  err "development dependencies in vendor/"
fi

# Unpack for the content checks.
unzip -q "$ZIP" -d "$TMP/unpacked" 2>/dev/null || { err "ZIP cannot be extracted"; exit 1; }
PKG="$TMP/unpacked/$SLUG"
[ -d "$PKG" ] || { err "no $SLUG/ directory after extraction"; exit 1; }

# 4. content heuristics
SECRETS='-----BEGIN [A-Z ]*PRIVATE KEY-----|ghp_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{20,}|gh[ousr]_[A-Za-z0-9]{30,}|AKIA[0-9A-Z]{16}|xox[abprs]-[A-Za-z0-9-]{10,}|_authToken|npm_[A-Za-z0-9]{36}'
if hits="$(grep -rIlE -e "$SECRETS" "$PKG" 2>/dev/null)" && [ -n "$hits" ]; then
  err "possible secrets in: $(echo "$hits" | sed "s#$PKG/##" | head -3 | tr '\n' ' ')"
fi
PATHS='/var/www/|/home/[A-Za-z0-9._-]+/|[A-Za-z]:\\Users\\|/Users/[A-Za-z0-9._-]+/'
if hits="$(grep -rIlE -e "$PATHS" "$PKG" 2>/dev/null)" && [ -n "$hits" ]; then
  err "absolute developer paths in: $(echo "$hits" | sed "s#$PKG/##" | head -3 | tr '\n' ' ')"
fi

# 5. version
MAIN="$PKG/sw-gallery.php"
if [ -f "$MAIN" ]; then
  header="$(sed -n -E 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+)[[:space:]]*$/\1/p' "$MAIN" | head -n1)"
  const="$(sed -n -E "s/^define\( 'SWGALLERY_VERSION', '([^']+)' \);.*/\1/p" "$MAIN" | head -n1)"
  [ -n "$header" ] || err "no Version header in sw-gallery.php"
  [ "$header" = "$NAME_VERSION" ] || err "header Version '$header' differs from the file name version '$NAME_VERSION'"
  [ "$header" = "$const" ] || err "header Version '$header' differs from SWGALLERY_VERSION '$const'"
  uri="$(sed -n -E 's/^[[:space:]]*\*[[:space:]]*Plugin URI:[[:space:]]*(.*[^[:space:]])[[:space:]]*$/\1/p' "$MAIN" | head -n1)"
  case "$uri" in
    https://*example.* | '') err "Plugin URI is empty or a placeholder: '$uri'" ;;
  esac
  if [ -f "$PKG/CHANGELOG.md" ] && ! grep -Eq "^## \[$header\]" "$PKG/CHANGELOG.md"; then
    err "CHANGELOG.md has no section for $header"
  fi
else
  err "sw-gallery.php is missing"
fi

# 6. PHP lint and autoloader
while IFS= read -r -d '' php_file; do
  if ! out="$(php -l "$php_file" 2>&1)"; then err "PHP syntax error: ${php_file#"$PKG"/}: $out"; fi
done < <(find "$PKG" -name '*.php' -print0)
if [ -f "$PKG/vendor/autoload.php" ]; then
  php -r 'require $argv[1] . "/vendor/autoload.php"; exit(class_exists("SWGallery\\WordPress\\Plugin") && class_exists("SWGallery\\Core\\Domain\\Gallery\\Gallery") ? 0 : 1);' "$PKG" >/dev/null 2>&1 \
    || err "the generated autoloader does not load the plugin classes"
fi

# 7. required files
require() { [ -f "$PKG/$1" ] || err "required file missing: $1"; }
require sw-gallery.php
require uninstall.php
require LICENSE
require THIRD-PARTY-NOTICES.md
require vendor/autoload.php
require assets/blocks/block.json
require assets/blocks/build/index.js
require assets/media-library/media-library.js
for manifest in assets/admin/.vite/manifest.json assets/build/.vite/manifest.json; do
  require "$manifest"
  [ -f "$PKG/$manifest" ] || continue
  base="${manifest%/.vite/manifest.json}"
  missing="$(php -r '
    $base = $argv[1];
    $m = json_decode((string) file_get_contents($argv[2]), true);
    if (!is_array($m) || $m === array()) { echo "unreadable or empty manifest"; exit; }
    $missing = array();
    foreach ($m as $entry) {
      $files = array_merge(array($entry["file"] ?? null), $entry["css"] ?? array(), $entry["assets"] ?? array());
      foreach ($files as $f) { if (is_string($f) && !is_file($base . "/" . $f)) { $missing[] = $f; } }
    }
    echo implode(", ", array_unique($missing));' "$PKG/$base" "$PKG/$manifest")"
  [ -z "$missing" ] || err "$manifest references missing files: $missing"
done
require languages/sw-gallery.pot
BLOCK_HASH="$(printf '%s' 'assets/blocks/build/index.js' | md5sum | cut -d' ' -f1)"
po_count=0
for po in "$PKG"/languages/sw-gallery-*.po; do
  [ -f "$po" ] || continue
  po_count=$((po_count + 1))
  locale="$(basename "$po" .po)"
  locale="${locale#sw-gallery-}"
  require "languages/sw-gallery-$locale.mo"
  require "languages/sw-gallery-$locale-$BLOCK_HASH.json"
done
[ "$po_count" -gt 0 ] || err "no translation catalog (.po) in languages/"

# 8. size budget
SIZE="$(stat -c %s "$ZIP")"
[ "$SIZE" -le "$MAX_BYTES" ] || err "ZIP is $SIZE bytes, budget is $MAX_BYTES (SWG_ZIP_MAX_BYTES)"

if [ "$errors" -gt 0 ]; then
  echo "verify-zip: $errors problem(s) in $ZIP" >&2
  exit 1
fi
echo "OK: $ZIP ($(wc -l <"$TMP/entries.txt") files, $SIZE bytes)"
