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
## Shortcode and PHP API

Both call `Services::embed_renderer()` (the one renderer plus the asset collector) and return their markup, never echo.

- `[sw_gallery id="123"]`: `id` is a required positive integer. Allowlisted overrides: `layout` (registered id), `columns` (1..12), `gap` (0..64), `page` (>= 1). Unknown attributes are ignored, invalid values dropped silently and never echoed. A missing/invalid `id` prints nothing for visitors and a short note for users with `edit_posts`. In feeds the `Feed` entry point is used. The current page comes from the request argument `swg_page_<galleryId>` (digits only, wins over `page`); the pagination links are built by the renderer.
- `swg_render_gallery( int $gallery_id, array $args = array() ): string` (`Api/functions.php`, `@since 0.1.0`): same overrides plus `eager` (bool); entry point `PhpApi`; returns escaped HTML, `''` when nothing may be shown. Print it as is: `echo swg_render_gallery( 123, array( 'columns' => 4 ) );`.

Sanitising lives in `Frontend/EmbedArguments`; access is the renderer's (D4), so draft/private/password galleries behave exactly like the other surfaces.

## Frontend assets and the collector

`Frontend/AssetCollector` (`Services::asset_collector()`) records every printed gallery. Any surface that prints a gallery must report it: either call `Services::embed_renderer()->render( $id, $entry, $options ): string` (renders and records) or `Services::asset_collector()->record( $id, $renderResult )` after `GalleryRenderer::render()`. Results without assets (nothing rendered, password form, feed) are not recorded. The admin bar and the asset loader read the collector.

`Frontend/FrontendAssets` reads the validated `assets/build/.vite/manifest.json` (`Frontend/FrontendManifest`: relative names, `js`/`css` allowlist, realpath containment, no symlinks, 256 KB cap; a bad entry is dropped, a bad manifest yields nothing). Optional key `frontend/src/layouts.css` is the layout stylesheet (skipped when absent). Rules:

- Handles are registered on `wp_enqueue_scripts` (priority 5) with `SWGALLERY_VERSION`; never on admin screens or feeds.
- When a queried post contains the shortcode or the block `sw-gallery/gallery`, the stylesheets are enqueued right away (printed in `<head>`).
- Otherwise `wp_print_footer_scripts` (priority 1) enqueues from the collector: stylesheets then print late in the footer. Accepted trade-off (F-17): a short flash of an unstyled gallery for embeds in widgets, templates or the PHP API. Pages without a rendered gallery load nothing.
- The lightbox script is classic, `defer`, in the footer; script and lightbox stylesheet need the plugin setting `lightbox`.
- A missing or invalid build enqueues nothing; editors get a footer note without paths.

## Preview

`Rest/PreviewController` (`POST /galleries/{id}/preview`, see `docs/rest-api.md`) renders with `EntryPoint::Preview`. The pipeline asks `GalleryAccessPolicyInterface::previewAccess` instead of `embedAccess` (FU-13): users who can edit the gallery get `Render` for every non-trashed status (draft, pending, future, private, publish) and ignore a password; everybody else and the trash get `Nothing`. Unsaved `layout`, `sortMode` and `settings` are applied in memory through `Frontend/PreviewGalleries` (a read-only repository decorator); nothing is persisted. A render cache must never read or write `Preview` output.
## Block

`sw-gallery/gallery` (`Block/BlockRegistrar`, metadata in `assets/blocks/block.json`) is dynamic: `save` stores nothing, `render_callback` validates the attributes (`Block/BlockAttributes`) and calls `Services::gallery_renderer()->html( $galleryId, EntryPoint::Block, $options )`, wrapped in `get_block_wrapper_attributes()` (align wide/full, margin, class, anchor). Access is the renderer's single decision; nothing renders for visitors without a visible gallery.

| Attribute | Rule | Render option |
|---|---|---|
| `galleryId` | integer 1..2147483647, else 0 (nothing rendered; editors get a hint only in the REST editor preview) | gallery id |
| `layout` | layout id pattern `^[a-z][a-z0-9_-]{0,63}$` | `layout` |
| `columns` / `gap` | integers 1..12 / 0..64 | `settings.columns` / `settings.gap` |
| `eager` | boolean | `eager` |
| `anchor` | id subset `^[A-Za-z][A-Za-z0-9_:.-]{0,99}$` (dynamic blocks print the anchor themselves) | wrapper `id` |

Anything else (media id lists, unknown keys, out-of-range or hostile values) is dropped, never echoed. Editor script and style (`swg-block-editor`, built to `assets/blocks/build/index.js|css`, stable names) are registered with explicit dependencies and `SWGALLERY_VERSION`; WordPress loads them only in the block editor. Without a build the block still renders on the frontend. Frontend assets are not enqueued by the block: the caller must feed `RenderResult::$assets` to the asset collector (the callback currently uses `html()`, which drops them).

## Shortcode wrapper, editor styles, failing layouts, Media Library (stream I)

- **Shortcode wrapper (FU-23).** Only the shortcode wraps its output: `<div class="swg-shortcode is-layout-constrained">...</div>` (not in feeds, not when nothing is rendered; the invalid-id note is unwrapped). Why: classic shortcode output sits in post content as a plain block and was full width in block themes. Core's own layout rule constrains direct children of `.is-layout-constrained` to the theme's content size (`--wp--style--global--content-size`), so the gallery follows the theme's content column; classic themes define no such class, so the wrapper is inert there. It adds no plugin CSS and no inline style. To change the width, set core's custom property on the wrapper (`.swg-shortcode { --wp--style--global--content-size: 100%; }`) or wrap the shortcode yourself. The block keeps its own wrapper (`get_block_wrapper_attributes`, alignment support), `swg_render_gallery()` and the renderer return the bare markup: this wrapper is the one documented difference between the surfaces (`SurfacesTest` strips it before comparing).
- **Editor preview styles (FU-20).** `BlockRegistrar::enqueue_editor_layout_styles()` (`enqueue_block_assets`, admin and `edit_posts` only) enqueues the layout stylesheet from the validated manifest (`FrontendManifest`, key `frontend/src/layouts.css`) as handle `swg-editor-layouts` with `SWGALLERY_VERSION`, so the server-side preview is styled like the frontend; nothing is enqueued without a build.
- **Failing layouts.** A layout that throws in `render()`, `imageHints()` or while the per-embed overrides are validated against its schema is contained in `RenderGallery`: log `swg_layout_failed` (layout id and exception class), the `grid` layout renders the page with the stored settings, editors get the diagnostic `layout_failed`, visitors only see the grid. A failing `grid` itself is not hidden.
- **Media Library (M2-38).** `Admin/MediaLibraryRegistrar`: read-only field "Used in galleries" in the attachment details (count + links to galleries the user may edit, max 10, then "and n more"; users who may edit none see only the count), list view column "Galleries" (one grouped query for the visible page), and `assets/media-library/media-library.js` (screens `upload` and `attachment` only, `ver` = plugin version, strings via `window.swgMediaLibrary`): `window.confirm` with the number of galleries before "Delete Permanently" (row action, edit screen, bulk action) of a used image. The relations are still removed by the lifecycle hook when the attachment is really deleted. The media modal (grid view) shows the details field but has no delete warning (core's modal button is not hookable without Backbone code; not implemented).

## Justified and masonry

- Both reuse the lightbox markup contract. `justified` is CSS only (per-item `--swg-ar`, flex rows); `masonry` is a plain grid plus `assets/frontend/src/masonry.ts` (decision D10, see `assets/README.md`). Image hints: justified assumes thumbnails of about `rowHeight * 2` px (`min(100vw, ...)`), masonry like the grid (`100vw / columns`).
- A layout may implement `LayoutAssetsProviderInterface` to report extra `RenderAsset`s (masonry: `RenderAsset::Masonry`); they are cached with the result and enqueued by `FrontendAssets` (manifest key `frontend/src/masonry.ts`).


## Mosaic and LayoutPlan

- **LayoutPlan (core, `Domain/Layout/Plan`).** `TreemapPlanner` turns the page's `PlanItem`s (aspect ratio, `emphasis` 1..3, focal) plus a seed into a `LayoutPlan`: the container ratio and one `Shape` (x, y, w, h in percent, three decimals) per item in input order. `variants()` plans three containers: `n` 4:5, `m` 1:1, `w` 16:10 (or the `ratio` setting). No WordPress, polygon `points` are reserved and unused.
- **Algorithm.** Order-preserving strip treemap with stacked pairs: the sequence is cut into full-width rows (at most 10 pictures); a row holds cells of one picture or two stacked consecutive pictures. Areas follow the weights 1 : 1.8 : 3, so the tiles tile the container exactly (edges are rounded, never the sizes). Rows and cells are chosen by dynamic programming (O(n)) on the weighted squared log aspect deviation from a square, a crop term against the picture ratio and a steep penalty outside 1:2.5..2.5:1. Reading order (rows top to bottom, cells inline-start to end, a stack top to bottom) is the input order. Measured on 200 seeded galleries of 1..60 pictures: worst tile 3.4:1 (only galleries of 3 pictures with weights such as 1:3:1 exceed 3:1), mean deviation from a square about 1.4:1, 98 % of the tiles inside 1:2.5..2.5:1.
- **Determinism.** Seed = `PlanSeed::from(galleryId, revision)` (the revision is the stored `_swg_revision`, passed additively as `LayoutRenderRequest::$revision` by `RenderGallery`); it only multiplies candidate row costs by 1..1.1, so near-ties vary per gallery and the arrangement changes only when the gallery is edited. One plan per page.
- **View and CSS.** `MosaicLayout` writes `--swg-{n|m|w}-{x|y|w|h}` per item and `--swg-{n|m|w}-ar` on the container as validated numbers; `assets/frontend/src/mosaic.css` positions the items absolutely (list widened by the gap, tiles padded by half of it). The variant follows the container width via `@container` (< 480 px: plain 2 column grid, which is also the whole layout in browsers without container queries; 480 / 720 / 1024 px: n / m / w). RTL mirrors through a logical margin (the build turns inset-inline-* into :lang() rules). Captions stay in the lightbox (hidden in plan mode, shown in the fallback grid).
- **Item settings.** `x-item-settings`: `emphasis` (1 Normal, 2 Large, 3 Extra large) and `focal` (`x`, `y` in percent, default 50/50, becomes `object-position`). They are read through `ItemSettingsRenderSource` (one extra query per page) into `RenderItem::$settings`; the editor shows them in the image details sheet when the saved layout declares `x-item-settings`.
