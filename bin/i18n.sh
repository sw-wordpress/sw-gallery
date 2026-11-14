#!/usr/bin/env bash
# Translation workflow for the PHP side (text domain "sw-gallery").
#   bin/i18n.sh pot       regenerate languages/sw-gallery.pot from the source code
#   bin/i18n.sh update    pot + merge new/changed strings into every languages/sw-gallery-<locale>.po (msgmerge) + compile + json
#   bin/i18n.sh compile   compile every .po to .mo
#   bin/i18n.sh json      write the JSON catalog of the block script per locale (languages/sw-gallery-<locale>-<md5>.json)
#   bin/i18n.sh check     fail when the .pot is outdated, a .po lacks a translation or a JSON catalog is stale (used by the PHPUnit test and CI)
# The .pot holds the PHP strings (xgettext) and the block's JS and block.json strings (assets/blocks/scripts/i18n.mjs, plain Node).
# Needs GNU gettext (xgettext, msgmerge, msgcat, msgfmt) and Node on the DEVELOPMENT machine only.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LANG_DIR="$ROOT/languages"
POT="$LANG_DIR/sw-gallery.pot"
LOCALES=(de_DE)
VERSION="$(grep -m1 "Version:" "$ROOT/sw-gallery.php" | awk '{print $NF}')"

make_pot() {
  local out="${1:-$POT}" php_pot block_pot
  php_pot="$(mktemp)"; block_pot="$(mktemp)"
  php "$ROOT/bin/i18n-core-messages.php" >/dev/null
  (cd "$ROOT" && find sw-gallery.php uninstall.php packages/wordpress/src -name '*.php' | sort -u | \
    xargs xgettext --language=PHP --from-code=UTF-8 --add-comments=translators \
      --keyword=__:1 --keyword=_e:1 --keyword=esc_html__:1 --keyword=esc_attr__:1 --keyword=esc_html_e:1 \
      --keyword=_x:1,2c --keyword=_n:1,2 --keyword=_nx:1,2,4c \
      --package-name="sw-gallery" --package-version="$VERSION" --msgid-bugs-address="https://github.com/sw-wordpress/sw-gallery/issues" \
      --copyright-holder="sw-gallery contributors" --sort-by-file --no-wrap -o "$php_pot")
  # Block: JS strings (src/**/*.ts(x)) and block.json metadata, merged into the same catalog.
  node "$ROOT/assets/blocks/scripts/i18n.mjs" pot "$block_pot"
  sed -i 's/charset=CHARSET/charset=UTF-8/' "$php_pot"
  # msgcat (without --use-first) merges the references of strings used by PHP and JS; it also marks the merged headers.
  msgcat --sort-by-file --no-wrap -o "$out" "$php_pot" "$block_pot"
  sed -i '/^# #-#-#-#-#/d; /^"#-#-#-#-#/d' "$out"
  awk '/^"Content-Type: /{ if (seen++) next } { print }' "$out" > "$out.tmp" && mv "$out.tmp" "$out"
  rm -f "$php_pot" "$block_pot"
  # Stable header: no creation date noise, UTF-8.
  sed -i 's/^"POT-Creation-Date:.*$/"POT-Creation-Date: YEAR-MO-DA HO:MI+ZONE\\n"/; s/charset=CHARSET/charset=UTF-8/' "$out"
  sed -i '1,/^$/ s/^# SOME DESCRIPTIVE TITLE\./# sw-gallery translation template./' "$out"
}

make_json() {
  local dest="${1:-$LANG_DIR}" locale
  for locale in "${LOCALES[@]}"; do
    node "$ROOT/assets/blocks/scripts/i18n.mjs" json "$LANG_DIR/sw-gallery-$locale.po" "$dest"
  done
}

case "${1:-}" in
  pot) make_pot ;;
  json) make_json ;;
  update)
    make_pot
    for locale in "${LOCALES[@]}"; do
      po="$LANG_DIR/sw-gallery-$locale.po"
      if [ -f "$po" ]; then msgmerge --quiet --update --no-fuzzy-matching --backup=none --no-wrap "$po" "$POT"; fi
    done
    "$0" compile
    make_json
    ;;
  compile)
    for po in "$LANG_DIR"/sw-gallery-*.po; do msgfmt --check-format -o "${po%.po}.mo" "$po"; done
    ;;
  check)
    tmp="$(mktemp)"; trap 'rm -f "$tmp" "$tmp.stat"' EXIT; make_pot "$tmp"
    if ! diff <(grep -E '^(msgid|msgctxt)' "$tmp") <(grep -E '^(msgid|msgctxt)' "$POT") >/dev/null; then
      echo "languages/sw-gallery.pot is outdated - run bin/i18n.sh update" >&2; exit 1
    fi
    for locale in "${LOCALES[@]}"; do
      po="$LANG_DIR/sw-gallery-$locale.po"
      [ -f "$po" ] || { echo "missing $po" >&2; exit 1; }
      msgfmt --check-format --statistics -o /dev/null "$po" 2> "$tmp.stat" || exit 1
      if grep -Eq "untranslated|fuzzy" "$tmp.stat"; then echo "$po is incomplete: $(cat "$tmp.stat")" >&2; exit 1; fi
      [ -f "${po%.po}.mo" ] || { echo "missing ${po%.po}.mo - run bin/i18n.sh compile" >&2; exit 1; }
    done
    json_tmp="$(mktemp -d)"; trap 'rm -rf "$json_tmp"; rm -f "$tmp" "$tmp.stat"' EXIT; make_json "$json_tmp"
    for json in "$json_tmp"/*.json; do
      name="$(basename "$json")"
      [ -f "$LANG_DIR/$name" ] || { echo "missing languages/$name - run bin/i18n.sh update" >&2; exit 1; }
      cmp -s "$json" "$LANG_DIR/$name" || { echo "languages/$name is stale - run bin/i18n.sh update" >&2; exit 1; }
    done
    echo "translations are complete and up to date"
    ;;
  *) echo "usage: $0 pot|update|compile|json|check"; exit 1 ;;
esac
