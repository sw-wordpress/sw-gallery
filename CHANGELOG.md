# Changelog

All notable changes to sw-gallery are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versions follow [Semantic Versioning](https://semver.org/). The version number lives in the plugin header (`sw-gallery.php`); change it only with
`bin/version.sh set <semver>` (see `docs/release.md`).

## [Unreleased]

### Added
- Release engineering: reproducible release ZIP (`bin/build-zip.sh`), ZIP verifier and install smoke test, release, security-audit and
  supported-matrix workflows, single source of the version (`bin/version.sh`).

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
