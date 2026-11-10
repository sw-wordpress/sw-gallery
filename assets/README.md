# assets

Public frontend assets (npm project, vanilla TypeScript, no runtime dependencies). `assets/admin/` is the generated output of `apps/admin` and is not touched here.

| Path                   | Content                                                                              |
| ---------------------- | ------------------------------------------------------------------------------------ |
| `lightbox/src`         | Accessible progressive-enhancement lightbox (`main.ts` entry, `lightbox.css`)        |
| `lightbox/tests`       | Vitest + jsdom tests                                                                 |
| `lightbox/fixtures`    | `gallery.html`, static sample of the markup contract for browser tests               |
| `frontend/src`         | Reserved for further entries (created together with real code, e.g. masonry packing) |
| `build/` (git-ignored) | Generated output with `.vite/manifest.json`                                          |

Commands (Node from `.nvmrc`): `npm ci`, `npm run typecheck | lint | format:check | test:coverage | build`. `test:coverage` fails unless line coverage is strictly > 80 %; `build` also checks the manifest, that the script is classic, that no sourcemap exists and that the JS stays below 12 KB gzip.

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
    "style.css": { "file": "lightbox-<hash>.css", "src": "style.css" }
}
```

- Script: `manifest["lightbox/src/main.ts"].file`, a **classic** script (IIFE, no `type="module"`), loadable in the footer or `defer`. It sets only `window.swgLightbox = { init(root?) }` and initializes on `DOMContentLoaded` (immediately if the DOM is ready).
- Style: `manifest["style.css"].file` (the lightbox stylesheet, ~1.4 KB gzip). Paths are relative to `assets/build/`.
- Enqueue only on pages that render a gallery. After inserting galleries dynamically call `window.swgLightbox.init(containerOrRoot)`; `init` is idempotent.
- Theming via CSS custom properties on an ancestor or `:root`: `--swg-lightbox-bg`, `--swg-lightbox-fg`, `--swg-lightbox-button-bg`, `--swg-lightbox-focus`.

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

- Without JS the links open the image. Only `a.swg-link` inside `[data-swg-gallery]` is enhanced; items are read when the dialog opens.
- `href` must be `http(s):` or `data:image/`; other or empty values are skipped (link keeps default behaviour).
- `data-swg-labels` is JSON (HTML-escaped attribute). Keys: `dialog`, `close`, `previous`, `next`, `counter` (placeholders `{current}`, `{total}`), `error` (added to the draft contract; English default if missing). Invalid JSON or empty values fall back to English defaults. All strings are set via `textContent`/attributes.
- Alt text is taken from the thumbnail `<img alt>`; the caption from `.swg-caption` inside the same `.swg-item`, read with `textContent` (inline markup is flattened to text).
- Text direction comes from CSS `direction` or the nearest `dir` attribute of the container; arrows, swipe and button placement mirror in RTL.
- Optional attributes `data-swg-full-*` are omitted by the PHP side when unknown.

## Behaviour summary

Dialog (`role="dialog"`, `aria-modal`, no native `<dialog>`), siblings of the portal made `inert`, focus into the dialog / trapped / returned to the opening link, scroll lock without layout shift, Esc, arrows (mirrored in RTL), Home/End, backdrop click, modified/middle clicks left to the browser, one image: no navigation. Touch swipe via Pointer Events (touch/pen, 50 px threshold, axis lock, `touch-action: pan-y pinch-zoom`). Neighbour preloading, loading and error state, `prefers-reduced-motion` honoured (CSS media query plus `swg-lightbox--reduced`).
