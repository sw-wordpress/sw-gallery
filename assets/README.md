# assets

Public frontend assets (npm project, vanilla TypeScript, no runtime dependencies). `assets/admin/` is the generated output of `apps/admin` and is not touched here.

| Path                   | Content                                                                                                                                            |
| ---------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| `lightbox/src`         | Accessible progressive-enhancement lightbox (`main.ts` entry, `lightbox.css`)                                                                      |
| `lightbox/tests`       | Vitest + jsdom tests                                                                                                                               |
| `lightbox/fixtures`    | `gallery.html`, static sample of the markup contract for browser tests                                                                             |
| `frontend/src`         | `layouts.css`: stylesheet of grid, justified, masonry, captions, pagination (no JS); `masonry.ts`: masonry packing script                          |
| `frontend/tests`       | Vitest checks of the stylesheet (namespacing, logical properties, breakpoints)                                                                     |
| `frontend/fixtures`    | `grid.html`, `justified.html` (CSS only, no build needed), `masonry.html` (needs `npx vite --open /frontend/fixtures/masonry.html` for the script) |
| `build/` (git-ignored) | Generated output with `.vite/manifest.json`                                                                                                        |

Commands (Node from `.nvmrc`): `npm ci`, `npm run typecheck | lint | format:check | test:coverage | build`. `test:coverage` fails unless line coverage is strictly > 80 %; `build` (`scripts/build-all.mjs`: one Vite run per entry, manifests merged) also checks the manifest, that the script is classic, that no sourcemap exists and the budgets (lightbox JS 12 KB gzip, masonry JS 2 KB gzip, each CSS 10 KB gzip). `build:only` builds without the checks.

## Build output and manifest (read by the PHP enqueue code)

Release build without sourcemaps, target from the shared browserslist (evergreen, Safari >= 16). Files are content-hashed; the PHP side still passes the plugin version as `ver` (CODE-STYLE cache busting).

`build/.vite/manifest.json`:

```json
{
    "lightbox/src/main.ts": {
        "file": "lightbox-<hash>.js",
        "name": "lightbox",
        "src": "lightbox/src/main.ts",
        "isEntry": true
    },
    "style.css": { "file": "lightbox-<hash>.css", "src": "style.css" },
    "frontend/src/masonry.ts": {
        "file": "masonry-<hash>.js",
        "name": "masonry",
        "src": "frontend/src/masonry.ts",
        "isEntry": true
    },
    "frontend/src/layouts.css": {
        "file": "layouts-<hash>.css",
        "name": "layouts",
        "src": "frontend/src/layouts.css",
        "isEntry": true
    }
}
```

- Script: `manifest["lightbox/src/main.ts"].file`, a **classic** script (IIFE, no `type="module"`), loadable in the footer or `defer`. It sets only `window.swgLightbox = { init(root?) }` and initializes on `DOMContentLoaded` (immediately if the DOM is ready).
- Style: `manifest["style.css"].file` (the lightbox stylesheet, ~1.4 KB gzip). Paths are relative to `assets/build/`.
- Masonry script: `manifest["frontend/src/masonry.ts"].file` (~0.7 KB gzip), a classic IIFE like the lightbox; sets `window.swgMasonry = { init(root?), destroy(container) }`. Enqueued only when a masonry gallery rendered (`RenderAsset::Masonry`), independent of the lightbox setting. Without it masonry is a plain grid.
- Layout style: `manifest["frontend/src/layouts.css"].file` (~0.9 KB gzip), enqueue it together with the gallery markup; the lightbox script and style only when the lightbox is on.
- Enqueue only on pages that render a gallery. After inserting galleries dynamically call `window.swgLightbox.init(containerOrRoot)`; `init` is idempotent.
- Theming via CSS custom properties on an ancestor or `:root`: `--swg-lightbox-bg`, `--swg-lightbox-fg`, `--swg-lightbox-button-bg`, `--swg-lightbox-focus`.

## Layout CSS and theming contract

`frontend/src/layouts.css` is static and driven by custom properties; no layout JavaScript. Every selector starts with `.swg-`, only logical properties are used (RTL), colours inherit from the theme (light/dark work without integration), `:focus-visible` is styled and hover motion only runs under `prefers-reduced-motion: no-preference`.

Breakpoints (min-width): sm 576 px, md 768 px, lg 1024 px, xl 1280 px; the image-size hints of `GridLayout` use the same values and a 1200 px reference width (a Vitest check compares CSS and PHP).

Public custom properties (set them on `.swg-gallery`, an ancestor or `:root`):

| Property                                                                 | Default                          | Set by                                                                                                                      |
| ------------------------------------------------------------------------ | -------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| `--swg-columns`, `--swg-columns-sm`, `-md`, `-lg`, `-xl`                 | 3, each inherits the smaller one | layout (`columns` setting)                                                                                                  |
| `--swg-gap`                                                              | 8px                              | layout (`gap` setting)                                                                                                      |
| `--swg-aspect`                                                           | `auto` (natural ratio)           | layout (`aspect` setting, not emitted for `auto`)                                                                           |
| `--swg-radius`                                                           | 4px                              | theme                                                                                                                       |
| `--swg-thumb-radius`                                                     | `--swg-radius`                   | layout (`radius` setting of masonry, default 0)                                                                             |
| `--swg-{n,m,w}-{x,y,w,h}`, `--swg-{n,m,w}-ar`, `--swg-fx`, `--swg-fy`    | 0, 0, 100, 100, ratios, 50%      | mosaic layout: plan rectangles per item and container variant (percent), container ratios, focal point                      |
| `--swg-hs0..4`, `--swg-cp0..4`, `--swg-tab`, `--swg-jr`, `--swg-jaspect` | unset                            | honeycomb: item sits in an odd row at breakpoint N; jigsaw: clip reference per breakpoint, tab depth (percent), piece ratio |
| `--swg-caption-color`, `--swg-caption-size`                              | inherit, 0.875em                 | theme                                                                                                                       |
| `--swg-focus-ring`                                                       | 3px solid Highlight              | theme                                                                                                                       |
| `--swg-hover-transform`                                                  | scale(1.03)                      | theme                                                                                                                       |
| `--swg-pagination-gap`                                                   | 0.25rem                          | theme                                                                                                                       |

`--swg-col-<n>` are internal. Pagination classes: `.swg-pagination`, `__list`, `__item`, `__link`, `__current`, `__disabled`, `__gap`, `__status`.

## Markup contract (emitted by the PHP views)

```html
<div
    class="swg-gallery"
    data-swg-gallery
    data-swg-instance="swg-12-1"
    data-swg-labels='{"dialog":"Image viewer","close":"Close","previous":"Previous image","next":"Next image","counter":"{current} of {total}","error":"The image could not be loaded."}'
>
    <ul class="swg-items" role="list">
        <li class="swg-item">
            <a
                class="swg-link"
                href="https://…/full.jpg"
                data-swg-full-width="2000"
                data-swg-full-height="1333"
                data-swg-full-srcset="… 800w, … 2000w"
                data-swg-full-sizes="100vw"
            >
                <img
                    src="…thumb.jpg"
                    alt="Alt text"
                    width="300"
                    height="200"
                    loading="lazy"
                    decoding="async"
                />
            </a>
            <p class="swg-caption">Caption text</p>
            <!-- optional -->
        </li>
    </ul>
</div>
```

Rules:

- `a.swg-link` carries an `aria-label` (item title, else "Image N of M") only when the image has no alt text.
- Without JS the links open the image. Only `a.swg-link` inside `[data-swg-gallery]` is enhanced; items are read when the dialog opens.
- `href` must be `http(s):` or `data:image/`; other or empty values are skipped (link keeps default behaviour).
- `data-swg-labels` is JSON (HTML-escaped attribute). Keys: `dialog`, `close`, `previous`, `next`, `counter` (placeholders `{current}`, `{total}`), `error` (added to the draft contract; English default if missing). Invalid JSON or empty values fall back to English defaults. All strings are set via `textContent`/attributes.
- Alt text is taken from the thumbnail `<img alt>`; the caption from `.swg-caption` inside the same `.swg-item`, read with `textContent` (inline markup is flattened to text).
- Text direction comes from CSS `direction` or the nearest `dir` attribute of the container; arrows, swipe and button placement mirror in RTL.
- Optional attributes `data-swg-full-*` are omitted by the PHP side when unknown.

## Behaviour summary

Dialog (`role="dialog"`, `aria-modal`, no native `<dialog>`), siblings of the portal made `inert`, focus into the dialog / trapped / returned to the opening link, scroll lock without layout shift, Esc, arrows (mirrored in RTL), Home/End, backdrop click, modified/middle clicks left to the browser, one image: no navigation. Touch swipe via Pointer Events (touch/pen, 50 px threshold, axis lock, `touch-action: pan-y pinch-zoom`). Neighbour preloading, loading and error state, `prefers-reduced-motion` honoured (CSS media query plus `swg-lightbox--reduced`).

## Justified and masonry

- `justified` (CSS only): `li` flex items, `--swg-ar` (ratio, set per item by PHP from validated numbers) drives `flex-grow` and `flex-basis = ratio * row height`; a zero-width filler keeps the last row at its natural height unless `.swg-last-justify`. Settings become `--swg-row-height[-sm|md|lg|xl]`, `--swg-gap`. Rows break greedily, so a very narrow item can end up alone in a row on narrow containers and then fills the width.
- `masonry`: grid in editor order; `masonry.ts` adds `.swg-masonry-active` (1px rows) and `grid-row-end: span N` per item from the measured height plus gap. DOM order is never changed; a `ResizeObserver` re-packs. Ratios come from the `width`/`height` attributes, so nothing shifts while images load.
- `aspect-ratio` is only set by CSS when the layout emitted `--swg-aspect` (an author `auto` would disable the attribute-derived ratio).

## Carousel and focus

- `frontend/src/carousel.ts` / `focus.ts`: classic IIFE scripts (`window.swgCarousel` / `window.swgFocus` = `{ init(root?), destroy(container) }`, idempotent), registered like masonry (manifest keys `frontend/src/carousel.ts`, `frontend/src/focus.ts`; budget 2 KB gzip each, currently about 1.6 and 1.5 KB). Styles: `carousel.css`, `focus.css`, imported by `layouts.css`.
- Extra `data-swg-labels` keys (ignored by the lightbox): `carousel`, `stage`, `thumbnails`. Properties: `--swg-carousel-height[-sm|md|lg|xl]`, `--swg-stage-ratio`, `--swg-thumb-size`, `--swg-carousel-button-bg|fg`, `--swg-stage-bg`, `--swg-current-ring`.
- Fixtures: `frontend/fixtures/carousel.html`, `focus.html` (CSS works from the file; `npx vite --open /frontend/fixtures/<name>.html` adds the script).
