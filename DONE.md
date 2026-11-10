# DONE

## 2026-11-09 — M1 slice 1
- Tooling: Composer, PHPUnit, PHPCS (PSR-12 core/tests, WPCS adapter), PHPStan level 8, `.editorconfig`, `.gitignore`, `sonar-project.properties`.
- Core: `GalleryItem`, `ItemOverrides` (null = inherit), `Revision`, `AssignResult`, typed exceptions with stable error codes, repository/revision/media contracts,
  `GalleryItemService` (assign/reorder/remove; revision claimed before writing; honest partial results).
- Adapter: plugin bootstrap, `swg_gallery` CPT + meta, versioned `swg_gallery_items` schema (dbDelta), `WpdbGalleryItemRepository`, atomic `PostMetaRevisionStore`,
  `WpMediaRepository`, lifecycle (deactivate keeps data; uninstall preserves unless opted in).
- Tests: 16 unit tests for core.
