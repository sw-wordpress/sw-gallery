# Rendering

One pipeline serves block, shortcode, PHP API, preview and feed (S§5, C§8). Code: `packages/core/src/Application/Render/RenderGallery.php` (WordPress-free), adapter entry `SWGallery\WordPress\Rendering\GalleryRenderer` (`Services::gallery_renderer()`).

## Pipeline

1. Load gallery and settings; apply validated per-embed overrides (layout id, settings; invalid overrides are ignored and reported to editors).
2. Access: `GalleryAccessPolicyInterface::embedAccess` only (D4). `PasswordForm` makes the adapter print core's password form, `Diagnostic` prints a note for users who can edit, `Nothing` prints nothing. No item is loaded before access is granted.
3. One page of usable items: `RenderItemSourceInterface` (`WpdbGalleryItemRepository::findRenderPage`): JOIN with the posts table, sort in SQL (D3, ties by item id), `LIMIT/OFFSET`, two queries. Missing, trashed and non-image attachments are skipped and not counted; relations are never deleted. A page past the end is clamped to the last page.
4. Texts: item override over attachment value (`null` inherits, `''` stays blank); attachments are never written.
5. Images: `ThumbnailResolver` (registered sizes only, F-20). Thumbnail = smallest same-ratio size covering the layout's display width (`ImageHintsProviderInterface`), `srcset` from the same-ratio sizes up to 2048 px; lightbox = largest size up to 2560 px (the `-scaled` file counts as full, `original_image` is never used). Width and height are always present; unusable attachments are skipped.
6. Loading (M3-06): page 1 only: first row (the layout's maximum column count, at most 6) `loading="eager"`, the first image of the first embed on the request also `fetchpriority="high"`, everything else `lazy`; `decoding="async"` always. `eager=false` per embed disables it.
7. Layout via `LayoutRegistry`; an unknown id falls back to `grid` (M3-12) with an editor-only diagnostic.

`RenderResult` returns `html`, `access`, `diagnostics` (stable codes, translated by `DiagnosticMarkup`), `assets` (`RenderAsset::Lightbox`, enqueued by the caller, never by the pipeline) and paging totals. Diagnostics exist only for users who can edit the gallery and never for `Feed`.

## Markup

Interactive markup follows the contract in `assets/README.md` (`Rendering/View/ImageMarkup`, captions through the `wp_kses` allowlist in `CaptionPolicy`, labels in `LightboxLabels`). The `Feed` entry point uses `View/FeedMarkup`: a plain `<ul>` of linked `<img>` with alt and an optional plain-text caption, no classes or data attributes, no script or style dependency.

## Tools

`wp swg render <galleryId> [--entry=shortcode|feed] [--page=<n>]` prints the HTML for the CLI user (`--user=<login>`).

## Layout CSS, pagination, cache and invalidation

**Layout CSS (M3-08).** `assets/frontend/src/layouts.css`, manifest key `frontend/src/layouts.css`; breakpoints, public custom properties and theming contract: `assets/README.md`. `GridLayout` adds the `aspect` setting (`auto` | `1:1` | `4:3` | `3:2` | `16:9`), emitted as `--swg-aspect` only when not `auto`. Callers enqueue the stylesheet together with every interactive render.

**Pagination (M3-11, D5).** `RenderResult.totalPages > 1` appends `<nav class="swg-pagination">` for shortcode, block and PHP API: real links from the current request URL with the argument `swg_page_<galleryId>` (other arguments kept, page 1 drops it, `esc_url`). The page comes from the `page` option, else from that argument; page size from `per_page` (1..200), else the plugin setting `itemsPerPage`. Embeds of one gallery on a page share the argument; different galleries are independent. Feed and preview never read the request and show "Page n of m" text. A page past the end renders the last page.

**Fragment cache (M3-13).** `Rendering/RenderCache`, object cache group `swg_render`, TTL 12 h, also without a persistent cache (then per request). Only the `public` visibility class is cached: gallery `publish`, no password, user cannot edit it, entry point not `Preview`; everything else is rendered fresh and never stored (so diagnostics, password forms, drafts and private galleries cannot leak). Key: gallery id, revision, entry point, page, page size, layout and settings overrides, eager flag, first-embed priority flag, plugin settings, plugin version, locale, text direction, site URL, media epoch. The request URL is not in the key; pagination links are appended after the cache. The per-request instance id is stored as a placeholder inside `data-swg-instance="..."` and filled on every hit, so ids stay unique and a hit equals a fresh render byte for byte. Out-of-range pages are stored under the last page only.

**Invalidation (M3-14, F-18).** Gallery, item and settings changes bump the gallery revision (part of the key). The *media epoch* (option `swg_media_epoch`, no autoload, random token) changes on `edit_attachment`, `attachment_updated`, `delete_attachment`, trash/untrash/delete of attachments, `wp_update_attachment_metadata` (regenerated sub-sizes) and add/update/delete of `_wp_attachment_image_alt` and `_wp_attached_file`; per request one write at once plus at most one merged write at `shutdown`. A plugin update changes the version in the key. Full-page caches and CDNs are outside the object cache: they purge on `swg_gallery_changed` (`docs/hooks.md`). Paging uses the query argument `swg_page_<id>`, so a page cache must vary on it (the default for most) and must not cache password-protected output; the plugin never caches that itself.
