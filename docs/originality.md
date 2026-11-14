# Originality check (M5-16, SPECIFICATION §1)

Date: 2026-11-14, version 0.1.0. Scope and limits first: **only plugins installed next to this one were compared (61 directories under
`wp-content/plugins`, listed below in "Compared against"). No web research was done**, so collisions with plugins that are not installed here
(for example those on wordpress.org) are not excluded. A search of the wordpress.org directory for the names below before the first public
release is recommended and is the one open item.

## Names

| Identifier | Value |
|---|---|
| Plugin name, slug, text domain | SW Gallery, `sw-gallery` |
| PHP namespaces | `SWGallery\Core`, `SWGallery\WordPress` |
| Post type, meta, table, options | `swg_gallery`, `_swg_*`, `{prefix}swg_gallery_items`, `swg_*` |
| Block | `sw-gallery/gallery` |
| Shortcode, PHP function | `[sw_gallery]`, `swg_render_gallery()` |
| Hooks | `swg_*` |
| REST namespace | `swg/v1` |
| CSS / data attributes / globals | `swg-*`, `data-swg-*`, `--swg-*`, `window.swgAdmin`, `swgLightbox`, `swgMasonry` |

Method: `grep -rIlE` over `*.php`, `*.js`, `*.css`, `*.json` of every other plugin directory (excluding `node_modules`) for `sw_gallery`, `swg_`,
`sw-gallery`, `swg/v1`, `'swg-`, `"swg-`, `.swg-`, `SWGallery`. **Result: no match** (the sibling `sw-*` plugins use their own prefixes).
Further checks of what the other plugins register:

- Shortcodes containing `gallery`, `slider` or `flipbook`: `wpmf_gallery`, `vc_wpmf_gallery`, `wpmf_fusion_gallery` (wp-media-folder), `rev_slider`
  (revslider). The core shortcode `gallery` is referenced by Elementor, Revolution Slider, WPML and wp-media-folder. None equals `sw_gallery`.
- Blocks with `gallery` in the name: `woocommerce/product-gallery*`, `woocommerce/product-image-gallery`. None equals `sw-gallery/gallery`
  (WordPress core's `core/gallery` is a different namespace).
- REST routes with `swg` in the namespace: none.

Compared against (all directories present): 3d-flipbook-dflip-lite, S3-Uploads, advanced-cron-manager, akismet, basepress, basepress-print, churchtools-*,
cmb2, cms-tree-page-view, contact-form-7, download-plugin, dtw-singlemedia-updater, elementor, envato-market, getwid, google-calendar-events, honeypot,
ilab-media-tools-premium, js_composer, kp-zip-downloader, loco-translate, manual-framework, meilisearch-wordpress, mpl-publisher, one-click-demo-import,
plugin-manager-access, print-o-matic, redux-framework, revslider, sb-churchtools-grouphomepage, simple-calendar-*, simple-image-sizes,
sitepress-multilingual-cms, sw-activate, sw-backupit, sw-cloudcalendar, sw-forms, sw-mailgate, sw-newspaper, sw-newspaper2, sw-securelogin, sw-sliderman,
taxonomy-terms-order, updraftplus, woocommerce, wp-armour-extended, wp-mail-smtp, wp-media-folder, wp-media-folder-addon, wp-s3-offload, wp-s3-smart-upload,
wp-sentry-integration, wpcustom-category-image, wpd-migration, wpforms-lite.

## UI texts

All user-facing texts are authored in this repository: the PHP catalog (`languages/sw-gallery.pot`, German `sw-gallery-de_DE.po`), the admin app
catalogs (`apps/admin/src/i18n/*.en.ts`, `*.de.ts`) and the block strings (`assets/blocks/src`, `block.json`); the repository history has one
author. Method: every English string of at least 30 characters (297 strings from the `.pot` and the admin catalogs) was searched as a literal
substring in every other local plugin directory (PHP, JS, catalogs, minified code). **One match**: "The image could not be loaded." also occurs in
the minified PhotoSwipe of WooCommerce. It is a generic one-sentence error message (the lightbox's own label, `assets/lightbox/src/labels.ts`,
`LightboxLabels.php`) and not a creative text; no change made. Shorter strings and UI vocabulary (gallery, grid, masonry, lightbox, layout) are
descriptive terms (S§1).

## Code and assets

Code is written for this repository (frontend lightbox, masonry, layout CSS and the Vue app have no copied code). Third-party components that ship in
the release ZIP are listed with license and source in [../THIRD-PARTY-NOTICES.md](../THIRD-PARTY-NOTICES.md): Vue, Quasar, vue-i18n, Pinia, Vue Router
(MIT), the Material Icons Round font (Apache-2.0), Inter and JetBrains Mono (OFL-1.1, self-hosted, no remote font requests), the Composer autoloader (MIT).
`git ls-files` contains no image or font files at all (fonts come in at build time from npm packages), so no logos or pictures of other products are in the repository (the menu icon is WordPress' `dashicons-format-gallery`, the app icons come from
the Material Icons font). The visual language of the admin app is shared on purpose with the sibling `sw-*` plugins of the same author (decision D14).
The layout ideas in `layout-proposals.md` are descriptive and not part of 1.0.

## Result

No collision found with the installed plugins; texts and assets are original or properly licensed. Open: a name search on wordpress.org (not possible
offline) before the first public release.
