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
