#!/usr/bin/env bash
# Single source of the plugin version: the "Version:" header of sw-gallery.php.
#   bin/version.sh get            print the version
#   bin/version.sh check          fail when SWGALLERY_VERSION, the npm packages (and lockfiles) or the CHANGELOG disagree with the header
#   bin/version.sh set <semver>   write the version everywhere and turn "## [Unreleased]" of CHANGELOG.md into the release section
# Only needs bash and Node (JSON files are rewritten with the same 2-space layout npm uses). See docs/release.md.
# SWG_VERSION_ROOT points the script at another tree (used by the unit test with a temporary copy).
set -euo pipefail

ROOT="${SWG_VERSION_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
PLUGIN="$ROOT/sw-gallery.php"
CHANGELOG="$ROOT/CHANGELOG.md"
NPM_PROJECTS=(apps/admin assets assets/blocks e2e)
SEMVER='^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z]+(\.[0-9A-Za-z]+)*)?$'

fail() { echo "version: $*" >&2; exit 1; }
header_version() {
  sed -n -E 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+)[[:space:]]*$/\1/p' "$PLUGIN" | head -n1
}
constant_version() {
  sed -n -E "s/^define\( 'SWGALLERY_VERSION', '([^']+)' \);.*/\1/p" "$PLUGIN" | head -n1
}
# Prints the version of a package.json / package-lock.json and, for lockfiles, of the root package entry.
json_versions() {
  node -e '
    const fs = require("fs");
    const d = JSON.parse(fs.readFileSync(process.argv[1], "utf8"));
    console.log(d.version ?? "");
    if (d.packages && d.packages[""]) console.log(d.packages[""].version ?? "");
  ' "$1"
}
json_set() {
  node -e '
    const fs = require("fs");
    const [file, version] = process.argv.slice(1);
    const d = JSON.parse(fs.readFileSync(file, "utf8"));
    d.version = version;
    if (d.packages && d.packages[""]) d.packages[""].version = version;
    fs.writeFileSync(file, JSON.stringify(d, null, 2) + "\n");
  ' "$1" "$2"
}
changelog_version() {
  sed -n -E 's/^## \[([0-9][^]]*)\].*/\1/p' "$CHANGELOG" | head -n1
}

cmd_check() {
  local version errors=0 file v
  version="$(header_version)"
  [ -n "$version" ] || fail "no Version header in sw-gallery.php"
  [[ "$version" =~ $SEMVER ]] || fail "header version '$version' is not semver"
  v="$(constant_version)"
  [ "$v" = "$version" ] || { echo "SWGALLERY_VERSION is '$v', header says '$version'" >&2; errors=1; }
  for project in "${NPM_PROJECTS[@]}"; do
    for file in package.json package-lock.json; do
      [ -f "$ROOT/$project/$file" ] || continue
      while IFS= read -r v; do
        [ "$v" = "$version" ] || { echo "$project/$file has '$v', header says '$version'" >&2; errors=1; }
      done < <(json_versions "$ROOT/$project/$file")
    done
  done
  if [ -f "$CHANGELOG" ]; then
    v="$(changelog_version)"
    [ "$v" = "$version" ] || { echo "newest CHANGELOG.md section is '[$v]', header says '$version'" >&2; errors=1; }
  else
    echo "CHANGELOG.md is missing" >&2
    errors=1
  fi
  [ "$errors" = 0 ] || fail "version drift (fix with: bin/version.sh set <semver>)"
  echo "version $version is consistent"
}

cmd_set() {
  local version="${1:-}" today file
  [[ "$version" =~ $SEMVER ]] || fail "usage: version.sh set <semver> (e.g. 1.0.0 or 1.0.0-rc.1)"
  [ -f "$CHANGELOG" ] || fail "CHANGELOG.md is missing"
  grep -q '^## \[Unreleased\]' "$CHANGELOG" || fail "CHANGELOG.md has no '## [Unreleased]' section"
  if grep -q "^## \[$version\]" "$CHANGELOG"; then fail "CHANGELOG.md already has a section for $version"; fi
  sed -i -E "s/^( \*[[:space:]]+Version:[[:space:]]+)[^[:space:]]+[[:space:]]*\$/\1$version/" "$PLUGIN"
  sed -i -E "s/^(define\( 'SWGALLERY_VERSION', ')[^']+(' \);)/\1$version\2/" "$PLUGIN"
  for project in "${NPM_PROJECTS[@]}"; do
    for file in package.json package-lock.json; do
      if [ -f "$ROOT/$project/$file" ]; then json_set "$ROOT/$project/$file" "$version"; fi
    done
  done
  today="$(date -u +%F)"
  sed -i -E "s/^## \[Unreleased\]\$/## [Unreleased]\n\n## [$version] - $today/" "$CHANGELOG"
  cmd_check
}

case "${1:-}" in
  get) header_version ;;
  check) cmd_check ;;
  set) shift; cmd_set "$@" ;;
  *) echo "usage: $0 get | check | set <semver>" >&2; exit 2 ;;
esac
