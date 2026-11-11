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
