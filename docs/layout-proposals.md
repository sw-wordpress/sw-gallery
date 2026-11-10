## Frontend layout proposals

Ideas for layouts beyond `grid`/`masonry`/`justified`; candidates for R1.1-03 and later. **Nothing here is scheduled or approved.**
Every layout is a `GalleryLayoutInterface` implementation registered through the layout registry (M4-07) and must keep the renderer rules (S§5, C§8).

### Shared technical base (build once, before any of these)

- [ ] L-00a `LayoutPlan` in core: a layout turns the page's items (aspect ratio, emphasis, focal point) plus a seed into a list of shapes in **relative units** (rectangles `x,y,w,h` in %, or polygons). Pure PHP, no WordPress, unit-testable with fixed seeds, cached with the fragment cache (M3-13).
- [ ] L-00b Deterministic seed = gallery ID + `_swg_revision` (+ optional stored "shuffle" value): the arrangement is stable for visitors and caches, and changes only when the gallery is edited or the editor presses "shuffle".
- [ ] L-00c Views emit the plan as CSS custom properties per item (`--swg-x`, `--swg-y`, `--swg-w`, `--swg-h`), positioned by one static stylesheet. No layout JavaScript, so no layout shift and it works without JS. Note: inline `style` attributes conflict with strict CSP; alternative is one generated `<style>` block per gallery instance.
- [ ] L-00d Breakpoints via CSS container queries; the plan is computed for 2–3 container widths (narrow/medium/wide) and the CSS picks the matching variable set. Every wild layout degrades to `grid` on narrow containers.
- [ ] L-00e Item settings (existing `settings` JSON, S§3.2): `emphasis` (1–3, how large a picture may get) and `focal` (x/y in %, mapped to `object-position` so crops keep the subject). Editor UI in the image details panel.
- [ ] L-00f Rules for all: DOM order = manual order (keyboard and screen reader order stay meaningful), items stay plain links for the lightbox, `clip-path` shapes need an inner focus indicator (outlines get clipped), `prefers-reduced-motion` disables every motion effect, paging applies per plan (one plan per page).

### Mosaics

- [ ] L-01 **Shattered mosaic (the wildest)** — images as irregular glass shards filling a rectangle without gaps. Technique: seeded points → Voronoi cells (half-plane clipping is enough for ≤ 60 items per page; a few Lloyd relaxation steps even out cell sizes, weighted by `emphasis`) → each cell becomes `clip-path: polygon(…)` in % on an absolutely positioned tile sized to the cell's bounding box; polygons are inset slightly for the "grout" lines. Hit-testing follows the clip-path, so clicks and hover work per shard. Hover/focus: shard lifts (`scale`, drop shadow via `filter` on a wrapper). Risks: faces get cut by sharp corners (needs `focal`), unreadable below ~500 px width (fallback to grid), captions only in the lightbox.
- [ ] L-02 **Treemap mosaic** — gap-free rectangle where the area of each picture follows its `emphasis`. Technique: squarified treemap algorithm in core, output as % rectangles; `object-fit: cover` + `focal`. The calm, robust sibling of L-01 and the recommended implementation of the planned "mosaic" layout.
- [ ] L-03 **Packed tile mosaic** — tiles of 1×1, 2×1, 1×2, 2×2, 3×2 cells chosen from orientation and `emphasis`. Technique: server-side bin packing on a column grid with explicit `grid-column`/`grid-row` per breakpoint (not `grid-auto-flow: dense`, which reorders visually against the DOM); the packer resizes the last tiles so no holes remain.
- [ ] L-04 **Jigsaw** — uniform grid whose tiles interlock like puzzle pieces. Technique: one inline SVG with `<clipPath clipPathUnits="objectBoundingBox">` per edge combination (tab in/out per side, chosen by seed so neighbours match); tiles overlap by the tab size via negative margins. CSS/SVG only. Hover: piece lifts out.
- [ ] L-05 **Picture-in-pictures (photo mosaic effect)** — the whole gallery reads as the cover image. Technique: dense small-tile grid with the cover image stretched over it as a `mix-blend-mode: soft-light` overlay (`pointer-events: none`); hovered/focused tile shows its true colours above the overlay. Pure CSS, no colour analysis. A true photomosaic (tiles sorted by dominant colour) would need per-image colour extraction and several hundred images — out of scope (S§13).
- [ ] L-06 **Shape fill** — tiles form a heart, circle, logo silhouette or letters. Technique: shipped presets as small cell bitmaps (`resources/presets`); the plan places one item per "on" cell, remaining items go to the next page (never hidden behind a mask). Custom shapes via an uploaded SVG are a later step (sanitising required).

### Geometric and playful

- [ ] L-07 **Honeycomb** — hexagonal cells. Technique: CSS grid, every second row offset by half a cell, `clip-path: polygon()` hexagon, negative row margin to interlock. CSS only.
- [ ] L-08 **Bubbles** — circles of different sizes packed tightly. Technique: circle packing (front-chain algorithm) in core, radius from `emphasis`, output as % positions inside a fixed-ratio container, `border-radius: 50%`. Optional gentle float animation (off with reduced motion).
- [ ] L-09 **Golden spiral** — first picture largest, each next one takes the golden-ratio remainder, spiralling inwards. Technique: recursive rectangle subdivision in core; 7–8 pictures per spiral block, blocks repeat down the page with alternating direction.
- [ ] L-10 **Scattered prints** — photos lying on a table, slightly rotated and overlapping, white border. Technique: grid placement plus seeded `--swg-rot` (±6°) and offsets; hover/focus straightens the print and raises `z-index`. CSS only; touch devices get reduced overlap.
- [ ] L-11 **Card stack** — pictures pile up while scrolling, each new one sliding over the previous. Technique: `position: sticky` with increasing `top` offsets and small seeded rotation; pure CSS, the page scroll drives it.

### Editorial and narrative

- [ ] L-12 **Magazine patterns** — alternating hero, triptych, portrait pair and full-bleed rows like a photo book. Technique: rule engine in core consumes items in order and picks a row template from the next items' orientation sequence (e.g. landscape + 2 portraits → template "hero left"); templates are plain CSS grid areas. Order preserved, very robust. Probably the best-looking practical layout.
- [ ] L-13 **Timeline river** — pictures grouped by capture date with date headings. Technique: group by EXIF capture time from attachment metadata (fallback upload date), each group rendered with the justified engine; requires the date sort mode (D3). Missing or wrong camera dates must not break ordering.
- [ ] L-14 **Spotlight** (planned "focus" layout) — one large stage with a thumbnail strip. Technique: without JS the thumbnails are in-page links and the stage uses `:target`; JS upgrades to instant switching and keyboard arrows.
- [ ] L-15 **Film strip** (planned "carousel" layout) — horizontal strip. Technique: native CSS scroll snap, usable without JS; JS adds buttons and a position indicator. No autoplay by default (accessibility).

### Motion and depth (progressive enhancement only)

- [ ] L-16 **Drifting columns** — masonry columns moving at slightly different speeds while scrolling. Technique: CSS scroll-driven animations (`animation-timeline: view()`) inside `@supports`; without support it is the normal masonry.
- [ ] L-17 **Perspective wall** — film strip bent in 3D, centre picture facing the viewer. Technique: CSS `perspective` + `rotateY` driven by scroll-driven animations on L-15; falls back to the flat strip.
- [ ] L-18 **Light table** — free pan-and-zoom canvas with all pictures spread out, zoom into any one. Technique: JS-heavy (pointer events, CSS transforms, level-of-detail image swapping); no-JS fallback is the grid. Largest effort and the hardest to make accessible; only as a late experiment.

### Suggested order

1. L-00 base + L-12 magazine + L-02 treemap (covers the planned "mosaic").
2. L-14 spotlight + L-15 film strip (the planned "focus" and "carousel").
3. L-01 shattered mosaic as the showpiece, then L-07, L-10 and L-04, which are cheap once the base exists.
4. L-05, L-06, L-08, L-09, L-11, L-13, L-16, L-17 on demand; L-18 last.
