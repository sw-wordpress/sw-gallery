# Changelog

All notable changes to sw-gallery are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versions follow [Semantic Versioning](https://semver.org/). The version number lives in the plugin header (`sw-gallery.php`); change it only with
`bin/version.sh set <semver>` (see `docs/release.md`).

## [Unreleased]

## [0.9.0] - 2026-11-17

### Added
- Verified Ed25519 GitHub updates with manual installation only through the native WordPress plugin list; persistent throttling, rollback protection and ZIP validation.
- Protected draft/sign/publish release pipeline, `make release VERSION=1.2.3`, and isolated signing/operator regression checks.
- Admin pages Media Overview (usage counts, upload, "add to gallery"), Settings, System Status and Overview; live preview dialog in the editor
  (sandboxed, shows unsaved layout and settings); tile size control (S/M/L); deep link `#/galleries/<id>`; session-expiry banner that keeps unsaved
  editor state; RTL support and site-timezone date formatting.
- Layouts `justified` and `masonry` (setting `radius` for masonry corners); layout API version 1 for `swg_register_layouts`.
- Media Library integration: "Galleries" column and field, confirmation before permanently deleting an image that galleries use.
- WP-CLI: `wp swg render`, `wp swg repair`.
- Suggested privacy policy text (Settings > Privacy > Policy Guide), English and German.
- Documentation: `README.md`, user guide, architecture overview, originality check, `THIRD-PARTY-NOTICES.md`, `LICENSE` (GPL v2), `CONTRIBUTING.md`.
- Release engineering: reproducible release ZIP (`bin/build-zip.sh`, now with `LICENSE` and `THIRD-PARTY-NOTICES.md`), ZIP verifier and install smoke test, release,
  security-audit and supported-matrix workflows, single source of the version (`bin/version.sh`).

### Changed
- Reordering uses batched SQL (10,000 items: about 21 queries); supported maximum is 10,000 items per gallery.
- Downgrade policy: a stored schema newer than the code refuses writes (503 `swg_schema_newer`) while reads and rendering keep working.
- Uninstall (when opted in) removes galleries of every status, including trash and private, also under WP-CLI.
- The shortcode output is wrapped in `<div class="swg-shortcode is-layout-constrained">` (follows the content width of block themes).
- Admin app shell is a `region` (WordPress owns `main`); the editor uses the full width; dialogs sit above the WordPress admin menu and bar.

### Fixed
- Grid CSS no longer overrides the width/height ratio of images (layout shift).
- Items of a trashed gallery can no longer be changed through the item routes (409 `swg_invalid_state`).
- Dialogs were hidden behind the WordPress admin menu (portal z-index).

### Security
- Settings maps of galleries and items are bounded (32 KB, depth 6, 200 keys per level).
- Inline bootstrap data (`swgAdmin`, `swgBlock`, `swgMediaLibrary`) is encoded with hex-escaped tags, ampersands and quotes.
- Independent security review with route/role matrix, escaping, upload and dependency checks: [docs/security.md](docs/security.md).
- Dependency audit of shipped npm and Composer packages runs daily and on lockfile changes.

## [0.1.0] - 2026-11-14

First development version. Not released; nothing is published yet.

### Added
- Gallery management on top of the WordPress Media Library: galleries as a custom post type, items as relations to attachments (attachments are never
  copied or deleted by gallery operations), ordering, per-item overrides, access policy per role, multisite support.
- Admin app (Vue 3, Quasar, TypeScript): gallery list and editor with live preview, Media Overview, Settings, System Status; German and English.
- REST API `swg/v1` for galleries, items, layouts, media, settings and status.
- Rendering: shortcode `[sw_gallery]`, Gutenberg block `sw-gallery/gallery`, PHP function `swg_render_gallery()`, authorised preview; one renderer and
  access policy for all surfaces; render cache.
- Layouts `grid`, `justified` and `masonry`; accessible progressive-enhancement lightbox; responsive images.
- Extension points `swg_register_layouts` and `swg_register_import_providers`, hooks and events (see `docs/hooks.md`, `docs/extending.md`).
- Media Library integration (usage column, gallery field, deletion guard), WP-CLI commands (`wp swg`).
- Translations: English source strings, German catalogs (PHP and block editor).

[Unreleased]: https://github.com/sw-wordpress/sw-gallery/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/sw-wordpress/sw-gallery/commits/v0.1.0
