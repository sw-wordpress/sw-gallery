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
