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
