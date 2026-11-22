# Layouts and presentation

Open **Edit images → Layout & settings** and choose **Layout**. The settings shown come from that layout's schema; click or focus the **?** help buttons for explanations. **Reset to default** restores a field's default. Save before testing on an embedded page.

## Choose a layout

The current repository registers these layouts. Some extend the older release documentation; consult the [Changelog](Changelog.md) and your installed version rather than assuming every release contains them.

| Layout | Presentation and relevant controls |
| --- | --- |
| **Grid** (`grid`) | Regular columns; columns 1–12 (default 3), gap 0–64 px (default 8), aspect ratio auto, 1:1, 4:3, 3:2 or 16:9. Auto preserves natural ratios; fixed ratios crop. |
| **Justified** (`justified`) | Rows with configurable row height (80–600 px), gap and last-row behavior: natural height or stretched to full width. |
| **Masonry** (`masonry`) | Columns with natural image heights; responsive columns, gap and corner radius (0–64 px, default 0). |
| **Carousel** (`carousel`) | Horizontal slides; slide height, corner radius, previous/next buttons and snap position. |
| **Focus** (`focus`) | A selected image on a stage with thumbnails below or beside it; stage ratio, thumbnail size and corner radius. Stage images are not cropped. Narrow containers put thumbnails below. |
| **Honeycomb** (`honeycomb`) | Hexagon or diamond cells; cells per row and gap. Below 480 px container width it becomes a plain two-column grid. |
| **Jigsaw** (`jigsaw`) | Puzzle pieces; pieces per row, gap, piece shape, tab size and variation. Below 480 px it becomes a plain two-column grid. |
| **Scattered** (`scattered`) | Tilted, overlapping prints; columns, print shape, overlap, tilt, border and variation. Small containers use two columns with reduced tilt. |
| **Mosaic** (`mosaic`) | Cropped rectangular tiles; corner radius, wide-mosaic shape and per-image size weights. |
| **Bubbles** (`bubbles`) | Circular images; gap, wide-field shape, gentle floating and per-image bubble sizes. Reduced-motion preferences disable floating. |
| **Shattered** (`shattered`) | Cropped polygonal shards; grout width, variation, wide-mosaic shape and per-image size weights. |
| **Magazine** (`magazine`) | Rows of varying image sizes; row height, corner radius and Balanced/Calm/Bold rhythm. Image order stays unchanged. |

Mosaic, Bubbles and Shattered adapt their overall shape in narrower containers and use a simplified grid/circle arrangement below 480 px. Variation changes an arrangement without changing images. Per-image size controls are relative weights, not an exact pixel-size promise.

![Published Bubble Layout demo with landscape and travel photographs arranged as circular images of varying sizes](/screenshots/sw-gallery-frontend.png)

*Demo: a real embedded Bubbles gallery on the WordPress test site, titled “Bubble Layout”, with 24 sample photographs. The surrounding header belongs to the demo theme.*

## Responsive fields and defaults

Responsive fields provide **All sizes**, **Small**, **Medium**, **Large** and **Extra large** values. An unset size inherits the previous smaller size. Not every layout has every field; do not expect column settings to apply to a layout that does not use columns. When a saved layout is unavailable, the website falls back to Grid until you choose an available one.

**Images per page** is a gallery setting from 1–200; leave it empty to use the site default (24 initially). A shortcode's `per_page` overrides the gallery value. See [embedding and paging](embedding.md#paging-and-the-lightbox).

## Keep the subject visible

For a cropping layout, open the image details and adjust **Focal point of the image** by clicking or dragging on the picture. Arrow keys move the point; Shift uses larger steps. The square crop shows a sample; it is not a preview of every layout shape. The default is the center (50% horizontal/vertical); **Centre again** restores it.

The point is an image setting within this gallery and is used by layouts that crop (including fixed-aspect Grid, Honeycomb, Jigsaw, Scattered, Mosaic, Bubbles, Shattered and Magazine). It does not rewrite the source image. If you switch layouts, save the layout change before editing that layout's image settings.

## Hover texts

Under **Hover texts**, use the site default or choose **Show the title on hover**, **Show the caption on hover**, and an **Animation**: slide from bottom/top, full-surface overlay or fade. The texts appear on pointer hover or keyboard focus; on touch screens they are always visible. Site defaults initially hide both texts and use fade as the animation. Hover text is not a replacement for alternative text.
