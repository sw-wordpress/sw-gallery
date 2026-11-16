# DONE

## 2026-11-09 — M1 slice 1
- Tooling: Composer, PHPUnit, PHPCS (PSR-12 core/tests, WPCS adapter), PHPStan level 8, `.editorconfig`, `.gitignore`, `sonar-project.properties`.
- Core: `GalleryItem`, `ItemOverrides` (null = inherit), `Revision`, `AssignResult`, typed exceptions with stable error codes, repository/revision/media contracts,
  `GalleryItemService` (assign/reorder/remove; revision claimed before writing; honest partial results).
- Adapter: plugin bootstrap, `swg_gallery` CPT + meta, versioned `swg_gallery_items` schema (dbDelta), `WpdbGalleryItemRepository`, atomic `PostMetaRevisionStore`,
  `WpMediaRepository`, lifecycle (deactivate keeps data; uninstall preserves unless opted in).
- Tests: 16 unit tests for core.

## 2026-11-10 — M1 Wave 0 (contracts)
- Core: `Gallery`, `NewGallery`, `GallerySettings` (unknown keys preserved), `GalleryStatus`, `SortMode` (D3), `GalleryQuery` (bounded), `GalleryPage`; `GalleryRepositoryInterface`.
- Access: `Actor`, `RenderAccess`, `StatusAccessRules` (decision table D4), `GalleryAccessPolicyInterface`.
- Layout: `GalleryLayoutInterface`, `LayoutRegistry` with `grid` fallback flag, `ThumbnailResolverInterface`, render value objects, `MediaReference`.
- Import: provider-neutral contracts and value objects only (no tables, no connectors); honest `ImportResult` counts.
- Test support: `InMemoryGalleries`, reusable `GalleryRepositoryContract`. Adapter: `Bootstrap` registrar skeleton.
- Tests: 98 unit tests, core line coverage 100 %; PHPStan level 8, PSR-12, WPCS clean.

## 2026-11-10 — M1 Wave 1 (parallel streams A–D)
- A core use cases: settings schema validator, event dispatcher + logger contract, transactional item and gallery services with revision claim, access enforcement via `Actor`.
- B tooling: i18n (en/de, `bin/i18n.sh`), throw-away test DB (`bin/test-db.sh`), WordPress integration suite (single + multisite), merged PHP coverage gate, CI workflow. Found and fixed a bug: first revision of a gallery always raised a conflict (`PostMetaRevisionStore`).
- C admin app scaffold: Vue 3 + Quasar + strict TS + Vite, scoped CSS, API client skeleton, en/de catalogs; `window.swgAdmin` bootstrap shape documented in `apps/admin/README.md`.
- D import: `ImportProviderRegistry`, `MediaLibraryProvider` (verified under real WordPress), reusable provider contract test.
- State: 191 unit + 48 WordPress tests (single and multisite), merged PHP coverage 94.8 %, admin app 38 tests / 98.9 % lines.

## 2026-11-10 — M2-15 admin page
- `Admin/AdminRegistrar`: menu "Galleries" (capability `edit_posts`), assets only on that screen, plugin version on every asset, `window.swgAdmin` bootstrap per `apps/admin/README.md`, ES-module tag for WordPress 6.4, safe diagnostic when the build is missing.
- `Admin/AssetManifest`: validated Vite manifest (plain relative names, extension check, realpath containment, size cap); broken or hostile manifests yield a diagnostic, never an unexpected file.
- Tests: 23 new WordPress tests (single + multisite); merged PHP coverage 95.2 %.

## 2026-11-10 — Wave 2 stream A2 + build automation
- Adapter infrastructure: `WpTransactionRunner` (nesting-safe, non-transactional fallback), `WpLogger` (ring buffer of 50 errors), `EventBridge` (`swg_<event>` actions, veto filters), item overrides/settings stores, `Migrator` (ordered steps, lock, retry), network-aware activation and batched, per-site opt-in uninstall.
- State: 121 WordPress tests (single + multisite), 191 unit tests, merged PHP coverage 93.6 %.
- Build automation: PostToolUse hook rebuilds `assets/admin` and `assets/build` after edits (`bin/rebuild-on-change.sh`), `bin/watch-build.sh` for manual edits.

## 2026-11-10 — Wave 2 stream A1
- `WpGalleryRepository` (passes the shared repository contract), `WpGalleryAccessPolicy` (D1/D4/D11, table-tested per role), batched `MediaReference` lookup, lifecycle hooks (attachment/gallery deletion keep relations and covers consistent), `wp swg repair` (dry run by default).
- State: 276 WordPress tests (single + multisite), 194 unit tests, merged PHP coverage 95.3 %.

## 2026-11-10 — Wave 2 stream B (E2E) and first real-browser findings
- Docker + Playwright environment (`bin/test-e2e.sh`, `tests/e2e-env`, `e2e/`, `.github/workflows/e2e.yml`): 18 tests (roles, assets only on the plugin screen with `ver=`, CSS isolation, locale, missing build, axe).
- **Defect found by the first browser run and fixed:** `AdminRegistrar::mark_as_module()` marked the inline `window.swgAdmin` script instead of the app script as `type="module"`, so the admin page stayed blank for every role. A unit test with a single tag had missed it; now covered by a combined-tag test and a test of WordPress's real output.
- App fixes from the browser review: oversized Quasar headings, duplicate h1 (the PHP page owns the h1), invalid list roles in the navigation (axe critical), `aria-current` on the active entry.

## 2026-11-11 — Service wiring and grid layout
- `Infrastructure/Services` wires all adapters and the core services (gallery, items, item edit) with transactions, events, access policy and the layout registry.
- `GridLayout` (real, not a placeholder): settings schema (responsive columns, gap), markup of the lightbox contract with contextual escaping; registered as `grid`, which `GalleryService` needs to accept any gallery.
- `ServicesIntegrationTest`: full life cycle (create, assign, reorder, overrides, conflict, trash, restore, delete) incl. permissions, events and settings validation against the real database. 289 WordPress tests, coverage 95.5 %.

## 2026-11-11 — Wave 3a stream A: design alignment (M2-39, M2-21)
- Admin app now follows the sw-* family: identical palette (light/dark), self-hosted Inter and JetBrains Mono, rail shell with brand block, collapse and dark-mode toggle, capability-driven navigation, `NoAccess`, and the UI building blocks (PageHeader, Card, StatusChip, StatTile, EmptyState, ErrorState, DataState, ConfirmDialog, FormSheet, SideSheet, PaginationBar, SegmentedControl).
- Review fixes by the orchestrator: strict template checking restored (scoped attribute augmentation, one real type error fixed); Quasar portal containers no longer stretch the WordPress page (E2E regression test, verified to fail without the fix).
- State: admin app 87 unit tests / 97.5 % lines; 32 E2E tests.

## 2026-11-11 — Wave 3a stream B: REST foundation and gallery routes (M2-01..M2-04)
- `swg/v1` controllers: list/create, get/patch/delete (trash or force), duplicate, restore; error mapper for every core exception (401/403/404/409/422/400, generic 500), schema-validated input, pagination headers, `expectedRevision`, publish rule, item counts from one grouped query.
- Tests: role matrix over all routes, existence-leak policy, anonymous refused on every route, 409 races, error mapper. 411 WordPress tests (single + multisite), merged PHP coverage 96.4 %.

## 2026-11-11 — Wave 3b (A, B1, B2, D)
- A gallery list on real data: search, status filter, grid/table, paging with URL sync, create, edit details, duplicate, trash, restore, delete (typed confirmation), copy shortcode; 154 unit tests / 97.9 % lines; 58 E2E tests incl. axe, role matrix and screenshots.
- B1 REST items and order (assign, list, patch overrides, remove, bulk remove, reorder); B2 REST layouts, media (search, upload), settings (confirmation rule) and status.
- D render pipeline: `RenderGallery` use case (one access decision for every entry point), sorted bounded item query, thumbnails/srcset, loading strategy, views and feed markup, `GalleryRenderer`, `wp swg render`; query budget asserted (13 queries for 12 images, 11 for 100).
- State: 657 WordPress tests (single + multisite), 240 unit tests, merged PHP coverage 97.1 %.
- The terminal was closed mid-wave; all work survived in git worktrees and three agents were resumed and finished.

## 2026-11-13 — Wave 4 (E, F1, F2, F3) and first full website test
- E gallery editor: staged editing with undo/redo, items grid with virtual scrolling, drag and keyboard reorder, native `wp.media` picker and XHR upload with real progress, image details (inherit vs blank), generated layout/settings form, conflict dialog with rebase, unsaved-changes guard.
- F1 shortcode `[sw_gallery]`, `swg_render_gallery()`, versioned asset enqueue only where rendered, admin-bar "Edit gallery", authorised preview route; F2 layout CSS (responsive grid, `aspect`, theming properties), link pagination, render cache with media epoch and `swg_gallery_changed`; F3 Gutenberg block with minimal editor glue and its own translation pipeline.
- Found and fixed by review and E2E: lazy chunks evaluated the admin entry twice (`?ver=` vs relative imports), Prettier scanning the generated admin output in `assets/`, the preview renderer using an outdated constructor after the cache change, a precedence contradiction between shortcode `page` and the request argument.
- `SurfacesTest` (identical output and visibility on all surfaces, collector hand-off) and `frontend.spec.ts` (published post with shortcode and block in a real browser: grid, versioned CSS/JS, lightbox, no-JS links, link pagination, draft invisible, admin bar).
- State: 1041 WordPress tests (single + multisite), 248 unit tests, merged PHP coverage 97.2 %; admin app 263 tests / 95.6 %, frontend assets 76 / 100 %, block 31 / 99.3 %; 93 E2E tests.

## 2026-11-13 — Wave 5 (G1, G2, H, I)
- G1 admin pages: Media Overview (grid/table, search/sort/paging with URL sync, usage counts, upload, details sheet, "add to gallery"), Settings (typed confirmation for delete-data), System Status (no paths, copyable summary), Overview; a11y pass with axe sweep over every route (light/dark), mobile nav, chunk-load error with retry; FU-07 not reproducible (regression tests).
- G2 editor live preview: sandboxed `srcdoc` iframe (`sandbox="allow-scripts"`, CSP, nonce, validated same-origin assets, validated height messages), debounced staged settings, viewport switcher, paging, diagnostics, error states.
- H layouts `justified` (CSS only) and `masonry` (grid + 740 B script, DOM order untouched); fixed a grid CSS bug that overrode the width/height ratio (layout shift); `LayoutAssetsProviderInterface`.
- I extension points `swg_register_layouts` (API v1) and `swg_register_import_providers` with guarded registries, containment of failing layouts (grid fallback + `layout_failed` diagnostic), `swg_gallery_changed` completeness, Media Library column/field/delete warning, validation messages translated, item SQL moved into the repository, `docs/extending.md`, `docs/integrations.md`, `docs/development.md`.
- Merge: three conflicts only in docs/languages; two tests assumed `grid` as the only built-in (fixed); Media Library script got a Vitest and a lint scope.

## 2026-11-15 — Layouts: mosaic, carousel, focus (R1.1-03)
- Shared base L-00: `LayoutPlan`/`TreemapPlanner` in core (order-preserving strip treemap, exact tiling, deterministic seed = gallery id + revision, three container variants), per-item settings `emphasis`/`focal` now reach the renderer (`ItemSettingsRenderSource`) and the editor (per-image layout settings form in the details sheet).
- `mosaic` (gap-free, area follows emphasis, container-query variants, grid below 480 px), `carousel` (native scroll snap, buttons and position text by script, no autoplay) and `focus` (stage + thumbnails, `:target` without JS, script for instant switching).
- Layout script wiring is table driven (`Frontend/LayoutScripts`); `RenderCache` rewrites element ids derived from the instance id; the layouts CSS build target is raised (no `inset-inline-*`).
- State: 1469 WordPress tests (single + multisite), 333 unit tests, PHP coverage 97.5 %; admin 490 tests / 96.3 %, assets 154 / 100 %; E2E 255 passed on the first full run plus one test fixed (hash regex).



## 2026-11-15 — Seven more layouts
- `magazine` (L-12: 18 row templates, cost-minimising DP over the whole page incl. the closing row, nested lists), `shattered` (L-01: weighted Voronoi/power diagram shards with grout and `variation`), `honeycomb` (L-07 hexagon/diamond), `jigsaw` (L-04: seeded interlocking tabs through one SVG of clip paths), `scattered` (L-10: tilted overlapping prints, every print >= 60 % visible by construction) and `bubbles` (L-08: circle packing, optional float).
- Found while merging: in RTL the scattered offsets and tilt must mirror with the grid (otherwise the visibility guarantee breaks for some seeds): fixed with `--swg-m`.
- State: 475 unit tests, 1554 WordPress tests (single + multisite), PHP coverage 97.8 %; assets 207 tests / 100 %; E2E 320 passed on the full run (+ the RTL fix verified three times without retries).

## 2026-11-15 — Compact gallery editor workspace
- Editor fills the available viewport with compact navigation/header/tools, a full-height image area and an independently scrolling settings panel; settings stack below the images on narrow screens.
- Navigation expansion in the editor preserves the preference on other pages. Existing gallery operations, translated labels and keyboard controls are retained.
- Regression coverage: navigation preference isolation and browser checks for viewport geometry, settings scroll and mobile overflow.
- Validation: 491 admin tests (96.30% lines), build/typecheck/lint, 28 browser scenarios (two network-related retries), i18n parity; PHP style/analysis and 475 unit/integration tests passed; WordPress suite: 1,554 tests, 10 skipped, merged PHP line coverage 97.78%.

## 2026-11-15 — Editor upload controls
- Replaced the permanent upload strip with a compact toolbar button and a window-wide overlay while dragging external files; progress remains visible after queuing.
- Regression coverage: window drops, nested drag targets, cancellation/navigation cleanup, native media modal, permission denial and file chooser.
- Checks: 494 admin tests, 96.38 % lines; typecheck/lint/format/build/i18n and Docker upload browser test passed. PHP: 475 tests, PHPStan and style gates passed (existing warnings); existing coverage report passes at 97.78 %. A fresh full PHP coverage run ended with signal 143 before producing a report; PHP sources are unchanged.

## 2026-11-15 — Compact editor settings sidebar
- Responsive numbers: five device inputs, no override switches; empty removes the override and the placeholder shows the inherited value. Slider/value rows, full-width selects/text inputs and inline reset icons reduce vertical space.
- Layout schema requests use `_locale=user`; existing German PHP descriptions/options now match the SPA language. Default enum and boolean labels are localized.
- Checks: 497 admin tests plus four focused setting-field tests; admin line coverage 96.31 %. Typecheck/lint/format/build/i18n and Docker tests for responsive saves and German field geometry/translations passed. PHP: 475 tests, PHPStan/style gates passed (existing warnings); unchanged PHP coverage report passes at 97.78 %.

## 2026-11-15 — PR #2 CI repairs
- Pin E2E and release browser jobs to Ubuntu 24.04, supported by the locked Playwright.
- Share the production Composer audit guard between static CI and scheduled audits: an explicitly empty production list passes; invalid/missing locks and failed real audits still fail. Regression tests cover those paths.
- Calculate mosaic stack midpoints on the integer precision grid, avoiding PHP 8.4 floating-point midpoint drift without changing the render snapshot. A focused regression passes under PHP 8.3 and in a disposable PHP 8.4 container.
- Make the ZIP verification fixture compatible with PHP 8.2 (no aliases of internal classes); retain rejection of broken autoloaders.
- Use PCOV for the full CI line-coverage run (whole checkout, unchanged >80 % gate); the previous Xdebug run was still in the planner stress tests when cancelled after 18 minutes.
- Local checks: 484 PHP tests, PHPStan/style gates (existing warnings), complete dependency audit, and shell syntax passed. Local PHP line coverage is 97.66 % (all production sources included; the two costly CirclePacker stress tests ran without instrumentation). The full PCOV run and remote review outcome are recorded in PR #2.

## 2026-11-16 — PR #2 isolated repair
- Upload chooser: unique Vue ID and associated label using existing English/German button text; regression covers both locales and simultaneous instances.
- CI screenshot/trace and local browser RED confirmed that compact Grid settings fit at 875 px. Shared browser assertions now also check real overflow at 600 px, independent wheel scrolling and mobile stacking; no layout padding or sizing changes.
- Sonar scanner waits for the server quality gate. Verification and outstanding current-head gates: `build/checks/PR2-REPAIR.md`; no commits or publication from this checkout.
