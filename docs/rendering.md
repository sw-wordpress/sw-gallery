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
