# Translations (i18n)

Languages: **English** (source) and **Deutsch (de_DE)** (minimum, CODE-STYLE §9); more locales may be added.
Regional WordPress locales (de_AT, de_CH, de_DE_formal, …) use the shipped language of the same family.

| Part | Mechanism | Files |
|---|---|---|
| PHP (REST messages, notices, shortcode/block output, WP-CLI) | gettext, text domain `sw-gallery`, loaded from `languages/` | `languages/sw-gallery.pot`, `sw-gallery-<locale>.po/.mo` |
| Messages of the framework independent core | literal `__()` calls in a generated `Support/CoreMessages.php` (reaches the .pot), translated at presentation time | same catalogs |
| Admin UI (Vue/Quasar) | vue-i18n catalogs bundled in the build, language follows the WordPress user locale (Quasar labels too) | `apps/admin/src/i18n/{en,de}.ts` |
| Gutenberg block (JS + `block.json`) | `__( '…', 'sw-gallery' )` from `@wordpress/i18n` with the **literal** text domain; `wp_set_script_translations()`; JSON catalog per locale | `languages/sw-gallery-<locale>-<md5>.json` (md5 of `assets/blocks/build/index.js`) |
| Frontend lightbox strings | passed by the server renderer (`data-swg-labels`) | PHP catalogs |

## Definition of done
A change to a user-facing string updates all languages.
```bash
bin/i18n.sh update     # regenerate .pot, merge into every .po, compile .mo
bin/i18n.sh check      # fails on outdated .pot, untranslated/fuzzy entries, missing compiled files, stale or missing block JSON
bin/i18n.sh json       # only the block's JSON catalogs (update runs it)
```
* `tests/Unit/I18nTest.php` runs `bin/i18n.sh check`; the Vitest parity test (`apps/admin/tests/i18n-parity.test.ts`) fails on missing keys, differing
  `{placeholders}` or plural forms.
* New string: `__( '…', 'sw-gallery' )` (add a `/* translators: */` comment for placeholders) or a key in every UI catalog; then run the commands above.
* Adding a language: `msginit` a new .po, add the locale to `LOCALES` in `bin/i18n.sh`, add the UI catalog and register it in `apps/admin/src/i18n/`.
* `bin/i18n-core-messages.php` (run by `pot`/`update`) generates `packages/wordpress/src/Support/CoreMessages.php` from the typed exceptions of the core
  (`parent::__construct('…')` literals and `$message = '…'` defaults); commit the result. Programmer-error exceptions (`\InvalidArgumentException`) are not translated.
* GNU gettext is needed on the **development** machine only; the release ZIP ships compiled `.mo` files.
* Block strings: `assets/blocks/scripts/i18n.mjs` (plain Node, no packages; run by `bin/i18n.sh`) reads literal `__()`/`_x()` calls from `assets/blocks/src` and the `block.json` metadata into the same `.pot`, and writes the Jed JSON from the `.po`. Other i18n calls, non-literal texts or a non-literal domain fail the run instead of being skipped. Node is needed on the development machine (and in CI, where it is preinstalled).
