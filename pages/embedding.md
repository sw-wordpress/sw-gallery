# Embed a gallery

Galleries are embedded in posts/pages, not published at a gallery-specific URL. Both the containing post/page and the gallery must be accessible to the visitor. Publishing one does not publish the other.

## WordPress block editor

1. Edit the page or post and insert **SW Gallery**.
2. Choose the gallery in the selector.
3. Optionally override layout, columns, gap or eager loading in the block sidebar. Alignment and spacing are in the block toolbar.
4. Update/publish the page and test it as a logged-out visitor.

Change gallery images in the dedicated gallery editor, not by trying to reorder the block preview. Eager loading is for images that should load early; do not enable it indiscriminately for long galleries.

## Shortcode

Use **Copy shortcode** in the gallery list and paste into a **Shortcode** block:

```text
[sw_gallery id="123"]
```

Replace `123` with the actual gallery ID, not an image attachment ID. For example:

```text
[sw_gallery id="123" layout="masonry" columns="4" gap="12" per_page="24"]
```

| Attribute | Accepted value / behavior |
| --- | --- |
| `id` | Required valid gallery ID. |
| `layout` | Registered ID such as `grid`, `justified` or `masonry`; see [layouts](layouts.md). |
| `columns` | Integer 1–12, where that layout uses columns. |
| `gap` | Integer 0–64 px, where that layout uses gap. |
| `per_page` | Integer 1–200; overrides gallery page size, then site default. |
| `page` | Initial page, integer 1–100000; a visitor's pagination URL takes precedence. |

Unknown attributes are ignored and invalid overrides are silently dropped. A missing/invalid ID gives visitors no gallery; editors may see a diagnostic note. Do not use unsupported shortcode attributes for focal points, hover or layout-specific image weights: set those in the gallery editor.

## Paging and the lightbox

Large galleries are split into pages using real links, including without JavaScript. Site default: 24 images per page. Set a gallery-specific **Images per page** or use the shortcode override. Page navigation uses a gallery-specific URL parameter, `swg_page_<id>`.

With **Open images in a lightbox** on (the default), clicking a thumbnail opens the viewer. Use arrow keys to move, Home/End to reach the first/last image and Escape or the close button to leave. Without the enhancement, image links and page links remain usable. Feeds use a plain image list.

## Technical integrations

Theme/plugin developers can use `swg_render_gallery()`; it follows the same visibility checks. PHP API details, eager loading and renderer behavior: [rendering](https://github.com/sw-wordpress/sw-gallery/blob/main/docs/rendering.md). Custom layouts and hooks: [extending](https://github.com/sw-wordpress/sw-gallery/blob/main/docs/extending.md). These are not required for normal editor use.
