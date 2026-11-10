# TODO

Plan derived from `SPECIFICATION.md` (S§) and `CODE-STYLE.md` (C§); finished work is in `DONE.md`.
Order is binding: M1 → M5 (S§14.4). No P1/P2 work before P0 (M1–M5) is complete (S§2.2).
Every task ships with tests, i18n (en/de), docs and passes the Definition of Done (S§12). Tasks marked **[D n]** are blocked by an open decision.

## Open decisions (answer before the blocked task starts)

| # | Question | Recommendation | Blocks |
|---|---|---|---|
| D1 | Capability model: reuse `post` capabilities (current code) or dedicated `swg_gallery` capability type added to roles? | Keep `post` caps for 1.0 (no role-table writes, clean uninstall); authors manage own galleries, `edit_others_posts` for foreign ones. | M1-12, M2-01 |
| D2 | Media picker: native `wp.media` modal or own Quasar picker on `GET /media`? S§6 says "media-modal picker", S§8 defines `/media`. | Native `wp.media` in the editor (Media Library compatibility); `/media` serves Media Overview and search. | M2-27 |
| D3 | `_swg_sort_mode` values are not specified (S§3.1). | `manual` (default), `date_asc/desc`, `title_asc/desc`; non-manual modes resolved at query time, positions untouched. | M1-05, M3-03 |
| D4 | Embedding non-public galleries (S§5.2): behaviour for `private`, password, `draft`, `trash`. | private → only with `read_post`; password → core password form; draft/trash → nothing (diagnostic for editors only); only `publish` is fragment-cached. | M3-02 |
| D5 | Frontend paging in 1.0: P0 demands pagination + no-JS, "load-more UI" is 1.1 (S§2). | Link-based pages with a per-instance query arg in 1.0; JS load-more in 1.1. | M3-11 |
| D6 | "Media Overview" page (S§6) has no defined scope. | Permission-filtered attachment browser with "used in n galleries"; read-only apart from "add to gallery". | M2-33 |
| D7 | "Admin side panels" registry (S§7) is unspecified for a compiled Vue SPA. | 1.0: PHP registries for layouts/import providers + documented hooks; side-panel registry deferred to 1.1 and documented as not implemented. | M4-12 |
| D8 | GitHub self-update / signed releases: adopt `../sw-backupit/docs/github-updates.md`? | Adopt unchanged (only if D9 = GitHub). | M5-17 |
| D9 | Distribution channel: GitHub only, or also wordpress.org? wordpress.org forbids an own updater and needs `readme.txt` + Plugin Check. | GitHub only for 1.0; keep the code wordpress.org-compatible (updater isolated in one removable module). | M5-09, M5-17 |
| D10 | Masonry order: CSS columns keep no-JS but run the manual order **down** each column, so visual and keyboard order differ from the editor order. | Row-ordered server markup with CSS fallback (grid-like without JS) + small JS enhancement that packs columns while keeping DOM order. | M3-09 |
| D11 | May a user add attachments owned by others to an own gallery? | Yes if the attachment is readable in the Media Library (as with core image blocks); editing global metadata still needs `edit_post` on the attachment. | M1-12, M2-09 |

## Review findings (specification/style gaps turned into tasks)

- JS/TS coverage gate and Sonar only cover `apps/admin`; `assets/{blocks,frontend,lightbox}` TypeScript would be unmeasured (S§1.2.4, C§10) → M3-18, M4-05.
- Compiled output location for `assets/{blocks,frontend,lightbox}` is undefined (S§4 only names `assets/admin`) → M3-17.
- `_swg_revision` must rise on every persisted change (S§6), currently only item operations do → M1-11.
- `GalleryItemService` claims the revision, then writes item by item: a failing write leaves partial state (S§8, C§4) → M1-09.
- Registered meta is incomplete (`_swg_settings`, `_swg_sort_mode`, `_swg_schema_version` missing, S§3.1) → M1-05.
- `Lifecycle::uninstall()` is unbounded (`posts_per_page -1`), not multisite-aware and misses settings/transients → M1-19.
- Core event dispatcher and logger required by `docs/hooks.md` / C§9 do not exist → M1-07, M1-08.
- Plugin settings content is not enumerated (S§8 `/settings`) → M2-10.
- Taxonomies in S§3.1 belong to categorization (1.1), not 1.0 → R1.1-04.
- "Repair operation" (S§3.2, C§4) has no REST route → WP-CLI command M1-17, status diagnostics M2-11.
- `docs/hooks.md` cites C§3.2 for the failure policy; correct section is C§9 → M1-08.
- Plugin header `Plugin URI` is a placeholder; version is duplicated in header and constant → M1-22, M5-15.

## Findings / Open Topics

Comments, uncovered topics and ideas beyond the specification. Nothing here is scheduled yet; each line names a suggested target.
Legend: **Gap** = needed for a sound 1.0, should become a task; **Decide** = needs Stefan's answer; **Idea** = optional, do not build without approval (S§10 preamble: no invented features).

Plan and process
- [ ] F-01 **Gap** Walking skeleton first: S§14.4 orders the work by layer, so nothing is user-testable until late M2. Suggest a thin slice right after M1 (create gallery via REST → assign images → `[sw_gallery]` renders a plain grid) to validate the data model and access policy early. Target: between M1 and M2.
- [ ] F-02 **Gap** E2E environment (M2-36) comes late; every admin task before it lacks browser proof. Suggest moving M2-36 directly after M2-15.
- [ ] F-03 **Gap** Preview crosses milestones (M2-12, M2-31 depend on M3-01). Suggest moving both to M3 instead of shipping a 501 route.
- [ ] F-04 **Gap** C§1 refers to "recorded architecture decisions" but no place exists. Suggest `docs/decisions.md` (one short entry per answered D-item).
- [ ] F-05 **Gap** Patterns are copied from `../sw-backupit`, which is not part of this repository; docs link into it (`docs/development.md` → `github-updates.md`). CI and other contributors cannot follow these links. Copies must be self-contained. Target: M1-23, M2-14, M5-11.
- [ ] F-06 **Gap** No compatibility policy for public contracts (hooks, PHP API, REST, layout interface): semver meaning, deprecation period, `@since`. Target: M4-14.
- [ ] F-07 Comment: there are no runtime Composer dependencies today, so Mozart prefixing (M5-09) is only needed if one is added; keep it conditional (S§14.9).

Data model and lifecycle
- [ ] F-08 **Decide** Portability: gallery membership lives in a custom table, so the WordPress exporter (WXR) and most migration tools move galleries **without their images**, and attachment IDs change on import. Bulk export is only planned for 1.2. Options: (a) document the limitation for 1.0, (b) `wp swg export/import` with ID remapping in 1.0. Recommendation: (a) + README warning, (b) with R1.2-02.
- [ ] F-09 **Decide** The CPT supports `revisions`, but WordPress post revisions do not contain items, order or settings; restoring one would produce an inconsistent gallery, and the name collides with `_swg_revision`. Recommendation: drop `revisions` support in 1.0. Target: M1-15.
- [ ] F-10 **Decide** Gallery description format: plain text or limited rich text (CPT has `editor` support). Recommendation: plain text with line breaks; avoids a second HTML sanitising surface. Target: M1-01.
- [ ] F-11 **Gap** Cover rules are undefined: must the cover be a member of the gallery, and what happens when it is removed or deleted? Recommendation: any readable image attachment; fallback to the first item when missing. Target: M1-01, M1-16.
- [ ] F-12 **Gap** Supported media types are not listed: WebP/AVIF, animated GIF, SVG (no dimensions → CLS, security), HEIC, `-scaled` originals. Recommendation: raster image MIME types WordPress can produce sub-sizes for; SVG excluded in 1.0. Target: M1-06, M3-05.
- [ ] F-13 **Gap** No tested upper bound per gallery. Recommendation: document and test a ceiling (e.g. 10 000 items); reorder currently rewrites every position, consider gapped positions or one `CASE` update. Target: M5-01.
- [ ] F-14 **Gap** Settings forward compatibility: behaviour when stored settings come from a newer plugin version (downgrade) — preserve unknown keys or reset? Recommendation: preserve, render with known keys. Target: M1-05.
- [ ] F-15 **Idea** Recovery after save: a saved bulk removal is permanent (attachments stay, order and overrides are lost). A "recently removed" list or short item history would make it reversible. Target: 1.1.
- [ ] F-16 **Idea** Multilingual sites (WPML/Polylang duplicate attachments per language): at least verify nothing breaks; translation of galleries post-1.0.

Rendering and frontend
- [ ] F-17 **Gap** Late asset loading: shortcodes, widgets and page builders render after `<head>`, so styles enqueued at render time are printed in the footer (flash of unstyled gallery) on classic themes. Needs a strategy (detect in `wp_enqueue_scripts` where possible, footer fallback, small critical CSS). Target: M3-17.
- [ ] F-18 **Gap** Full-page caches and CDNs: the fragment cache invalidates itself, page caches do not, and per-instance paging/password output must not be cached wrongly. Provide a documented purge hook (`swg_gallery_changed` with affected gallery ID) and a note in the docs. Target: M3-14.
- [ ] F-19 **Gap** Output outside normal pages: RSS feeds, REST `content.rendered`, excerpts, e-mail digests have no CSS/JS. Define a simplified markup for feeds. Target: M3-07.
- [ ] F-20 **Gap** Image sizes: justified/masonry need uncropped sizes. Recommendation: use core sizes only, crop with CSS, register no own image sizes (no regeneration burden, S§13). Target: M3-05.
- [ ] F-21 **Gap** RTL: layouts, lightbox arrows/swipe direction and the Quasar admin (RTL mode) are not mentioned anywhere. Target: M3-15, M2-18.
- [ ] F-22 **Gap** Supported browsers are undefined although S§6 has a "browser support" paragraph. Recommendation: `browserslist` "last 2 versions of evergreen browsers", shared by admin and frontend builds. Target: M2-14, M3-17.
- [ ] F-23 **Gap** Theme integration: CSS custom properties for spacing/radius/colours, block `supports` (align wide/full, spacing), `swg-` class contract treated as public API. Target: M3-08, M4-03.
- [ ] F-24 **Idea** Mobile back button closes the lightbox (history entry); fits together with deep links. Target: R1.1-05.
- [ ] F-25 **Decide** Galleries have no own URL (`publicly_queryable` false). Needed later for deep links, sharing and SEO (image sitemap, `ImageGallery` structured data). Recommendation: keep embed-only in 1.0, decide before R1.1-05.

Admin and editor
- [ ] F-26 **Gap** Long editing sessions: the REST nonce expires and the login can time out, so "save" fails with 403 and unsaved changes are at risk. Refresh the nonce via Heartbeat and integrate the core auth-check dialog. Target: M2-17.
- [ ] F-27 **Idea** Core post lock ("X is currently editing") via Heartbeat as an early warning in addition to the 409 handling. Target: M2-32.
- [ ] F-28 **Gap** Native Media Library integration (S§1.1 "must remain compatible"): show "used in n galleries" on attachments and warn before deleting a used attachment; today the relation disappears silently. Target: M1-16, M2-33.
- [ ] F-29 **Gap** Entry points into the editor: "Edit gallery" link in the admin bar on pages showing a gallery and from the block; stable deep-linkable admin routes. Target: M2-19, M4-04.
- [ ] F-30 **Gap** Gallery list item counts must come from one grouped query, not one `COUNT` per row. Target: M2-02.
- [ ] F-31 **Idea** "Where is this gallery used" (posts containing the block/shortcode), shown before trashing. Target: 1.1.
- [ ] F-32 **Idea** Convert a core `core/gallery` block or `[gallery ids="…"]` shortcode into an sw-gallery (block transform creating the gallery from the IDs) — cheap adoption path. Target: 1.1 / R1.2-04.
- [ ] F-33 **Idea** First-run empty states that lead to "create first gallery"; no separate wizard. Target: M2-22.
- [ ] F-34 **Idea** Dev-only `bin/` seed script (galleries with n generated images) for acceptance checks, E2E and performance fixtures; never shipped. Target: M2-36, M5-01.

Security, privacy, operations
- [ ] F-35 **Gap** State and test as invariant: with link-based paging (D5) 1.0 has **no unauthenticated `swg/v1` route**; the first public route arrives with load-more (R1.1-06) and needs its own review. Target: M2-13.
- [ ] F-36 **Gap** Logger sink and retention are undefined (C§9): where do structured events go (`error_log` only with `WP_DEBUG_LOG`? own bounded table/option?), how long, and what System Status shows. Recommendation: `error_log` + small bounded ring buffer of recent errors for System Status. Target: M1-08, M2-11.
- [ ] F-37 **Gap** Privacy: no telemetry or external requests in 1.0 — state it in README; add suggested privacy-policy text (`wp_add_privacy_policy_content`); note that EXIF/GPS stays in files WordPress serves. Target: M5-14.
- [ ] F-38 **Gap** If D9 includes wordpress.org: `readme.txt`, Plugin Check in CI, no own updater, name/slug availability. Target: M5.
- [ ] F-39 Comment: explicitly list as non-goal that right-click/download protection is not provided (frequent photographer request, related to S§13 private URLs). Target: M5-14.

## M1 — Foundation (in progress)

Core domain and contracts
- [ ] M1-01 `Gallery` entity + value objects (`GalleryStatus`, `GallerySettings`, cover, owner, description); invariants unit-tested.
- [ ] M1-02 `GalleryRepositoryInterface` (find, search page, create, update, duplicate, trash, restore, delete) + in-memory test double.
- [ ] M1-03 `GalleryAccessPolicyInterface` with actor/context value objects (view, edit, delete, manage items); decision table unit-tested.
- [ ] M1-04 Layout contracts: `GalleryLayoutInterface` (`getId`, `getLabel`, `getSettingsSchema`, `render`), `LayoutRegistryInterface` with `grid` fallback result flag; `ThumbnailResolverInterface`.
- [ ] M1-05 **[D3]** Settings schema: versioned `GallerySettings`/item settings, responsive values, strict schema subset validator in core (no dependency); register all S§3.1 meta with schemas and auth callbacks.
- [ ] M1-06 `MediaRepositoryInterface` extension: batched `MediaReference` lookup (dimensions, MIME, alt, caption, title) without N+1; `WpMediaRepository` implementation.
- [ ] M1-07 Core `EventDispatcherInterface` + events (items assigned/removed/reordered, gallery created/updated/trashed/restored/deleted/duplicated); listener failures isolated.
- [ ] M1-08 Core `LoggerInterface` (structured, safe context, no secrets) + WordPress adapter; `swg_*` hook bridge with `before_*` veto; document each hook in `docs/hooks.md`, fix its C§ reference.
- [ ] M1-09 `TransactionRunnerInterface`; assign/reorder/remove/bulk-remove atomic incl. revision claim; rollback and non-transactional-engine fallback tested.
- [ ] M1-10 Item use cases: bulk remove (bounded), update overrides (`NULL` inherit vs `''` blank), set item settings; typed results.
- [ ] M1-11 Gallery use cases (`CreateGallery`, `UpdateGallery`, `DuplicateGallery`, `Trash/Restore/DeleteGallery`); every persisted change bumps `_swg_revision`, stale writes raise `RevisionConflictException`.
- [ ] M1-12 **[D1, D11]** Access policy implementation on WordPress capabilities incl. object ownership and independent attachment capabilities.
- [ ] M1-13 Import contracts only: `ImportSource`, `RemoteMediaObject`, `ImportResult`, `ImportCapabilities`, `FolderPage`, `RemoteMediaPage`, `MediaImportProviderInterface`, `ImportJobInterface`, provider registry; no tables, no connectors.
- [ ] M1-14 Media Library provider implementing M1-13 (pages local attachments, "import" resolves to the existing attachment, no copy); contract test suite reusable for later providers.

WordPress adapter
- [ ] M1-15 `WpGalleryRepository` on `swg_gallery` (CRUD, duplicate copies membership/overrides only, cover = featured image, status, owner); attachments never touched.
- [ ] M1-16 Lifecycle hooks: `delete_attachment` removes relations + cover references; `before_delete_post` removes gallery relations; trash/untrash keeps relations.
- [ ] M1-17 Consistency repair service + `wp swg repair` (dry-run default, bounded batches, report); no deletions during read requests.
- [ ] M1-18 Service registry/bootstrap with constructor injection; hook registration in dedicated registrars (C§3.2).
- [ ] M1-19 Multisite + uninstall: schema on network activation and `wp_initialize_site`, per-site prefix; uninstall bounded/batched, all sites, removes options/transients only with opt-in.
- [ ] M1-20 Schema migration runner: ordered versioned steps, repeatable, failure leaves version unchanged and retries; upgrade-from-v1 fixture test.

Tooling and tests
- [ ] M1-21 `bin/test-db.sh` + `tests/wp` environment (own Composer, single site + multisite, `composer test:wp`, `test:wp:ms`); integration tests for `WpdbGalleryItemRepository`, `PostMetaRevisionStore`, `Schema`, lifecycle hooks, uninstall preservation.
- [ ] M1-22 PHP coverage: add adapter, plugin file and `uninstall.php` to `<source>`, merge unit + WordPress-suite Clover, gate > 80 %; test that header version equals `SWGALLERY_VERSION`.
- [ ] M1-23 `bin/i18n.sh` (update/check), `bin/i18n-core-messages.php`, `languages/` (pot, de_DE po/mo), `I18nTest`.
- [ ] M1-24 `.github/workflows/tests.yml`: cs, cs:wp, stan, unit, WordPress suites (PHP × WP matrix), coverage gate, `composer audit`, Sonar upload.
- [ ] M1-25 Repository basics: `README.md` (requirements, links), `LICENSE`, initial commit of the scaffold.

## M2 — Management

REST API (`swg/v1`, S§8)
- [ ] M2-01 **[D1]** REST foundation: controller base, `permission_callback` on every route, exception → `WP_Error` mapper (400/401/403/404/409/422), bounded pagination headers, schema-validated input/output.
- [ ] M2-02 `GET/POST /galleries` (search, status/owner filter, paging, item count, cover).
- [ ] M2-03 `GET/PATCH/DELETE /galleries/{id}` (`expectedRevision`, status, cover, owner change needs `edit_others_posts`; DELETE trashes, `force=true` deletes).
- [ ] M2-04 `POST /galleries/{id}/duplicate` and `/restore`.
- [ ] M2-05 `GET/POST /galleries/{id}/items` (paged list with resolved global + local metadata; bulk assign with added/duplicate/missing result).
- [ ] M2-06 `PATCH/DELETE /galleries/{id}/items/{itemId}`, `POST /items/bulk-remove` (bounded batch).
- [ ] M2-07 `PUT /galleries/{id}/order` (complete ID set, atomic); evaluate move command for large lists after M5-01 measurements.
- [ ] M2-08 `GET /layouts` (registered layouts + setting schemas).
- [ ] M2-09 **[D11]** `GET /media` (permission-filtered search, images only, paged) and `POST /media` (native upload handling, `upload_files`, MIME/size errors mapped).
- [ ] M2-10 `GET/PATCH /settings` (`manage_options`): defaults for layout, page size, lightbox, image sizes, delete-data-on-uninstall with explicit confirmation.
- [ ] M2-11 `GET /status`: schema version, cache state, counts, repair findings summary, sanitized diagnostics (no paths/secrets).
- [ ] M2-12 `POST /galleries/{id}/preview` (authorized, accepts unsaved settings, uses the M3 renderer; stub returns 501 until M3-01 — no fake HTML).
- [ ] M2-13 REST test matrix: roles × routes, foreign-owned objects/attachments, nonce/auth boundaries, validation, paging limits, 409 races; `docs/rest-api.md` (short).

Admin app (`apps/admin`, C§7)
- [ ] M2-14 Scaffold from `../sw-backupit/apps/admin`: Vue 3, Quasar, TS strict, Vite, ESLint, Prettier, `vue-tsc`, Vitest + Vue Test Utils, `.nvmrc`, lockfile.
- [ ] M2-15 Admin page registrar: menu, mount point, assets only on sw-gallery screens, validated Vite manifest, plugin version on every asset, safe diagnostic on missing build.
- [ ] M2-16 Quasar style isolation (`swg-` scope, no restyling of WordPress chrome); regression test on a foreign admin screen.
- [ ] M2-17 Typed API layer: REST root/nonce/locale/capabilities from server bootstrap, error translation, cancellation, retry, 409 surface; no `fetch()` in components.
- [ ] M2-18 i18n bridge: vue-i18n en/de, Quasar language pack follows user locale, parity test; date/number formatting per WordPress locale/timezone.
- [ ] M2-19 Router + navigation shell (Overview, Galleries, Media Overview, Settings, System Status; nothing for unshipped features).
- [ ] M2-20 Coverage script `scripts/check-coverage.mjs` (> 80 %), CI job (typecheck, lint, tests, production build, `npm audit`), Sonar lcov.
- [ ] M2-21 Shared state components: loading, empty, error, retry; confirmation dialog naming affected objects.
- [ ] M2-22 Gallery list: grid + table, search, status filter, server paging; cover, title, count, status, modified.
- [ ] M2-23 Gallery list actions: create, duplicate, trash, restore, delete permanently (confirmation), preview, copy shortcode.
- [ ] M2-24 Editor shell: title, description, cover, status, save/publish, unsaved-change warning, revision tracking.
- [ ] M2-25 Editor image grid: server-paged/virtualized items, bulk selection, bulk remove with undo before save.
- [ ] M2-26 Reorder: drag-and-drop (dependency license/maintenance review) plus keyboard/button alternative with screen-reader announcements.
- [ ] M2-27 **[D2]** Media picker (existing Media Library images) with added/duplicate/missing feedback.
- [ ] M2-28 Upload with real per-file progress, partial-failure reporting (n of m), retry.
- [ ] M2-29 Image details panel: global vs gallery-local title/alt/caption (inherit vs blank), dimensions, MIME, filesize, ID.
- [ ] M2-30 Layout/settings panel generated from `/layouts` schemas, responsive values, validation errors.
- [ ] M2-31 Live preview (sandboxed, frontend CSS, debounced) — completed once M3-01 exists.
- [ ] M2-32 Conflict UX for 409: show what changed, reload or re-apply local edits; tested.
- [ ] M2-33 **[D6]** Media Overview page.
- [ ] M2-34 Overview, Settings and System Status pages.
- [ ] M2-35 Accessibility pass of the admin app (focus, labels, contrast, reduced motion; WCAG 2.2 AA goal).

E2E infrastructure
- [ ] M2-36 `tests/e2e-env` (Docker Compose, throw-away WordPress + DB), `e2e/` Playwright project, `bin/test-e2e.sh`, CI job.
- [ ] M2-37 Admin E2E: create gallery, upload/pick, reorder (mouse + keyboard), edit overrides, save/publish, duplicate, trash/restore, role restrictions.

## M3 — Frontend

- [ ] M3-01 Core `RenderGallery` pipeline (S§5 steps 1–8) with `RenderContext` (block, shortcode, PHP API, preview) and render result (HTML, required assets, diagnostics).
- [ ] M3-02 **[D4]** Status + access enforcement in the pipeline; identical for all entry points; bypass tests (shortcode, block, preview, paging).
- [ ] M3-03 **[D3]** Paged item query with batched attachment/meta priming (no N+1), sort modes, skips missing/trashed attachments without deleting relations.
- [ ] M3-04 Override resolution (local > global, `NULL` vs `''`) without mutating attachment metadata.
- [ ] M3-05 `ThumbnailResolver` adapter: size selection, `srcset`/`sizes` per layout, width/height, lightbox target size.
- [ ] M3-06 Loading strategy: first row eager/`fetchpriority`, rest lazy; CLS-safe aspect ratios.
- [ ] M3-07 View layer `resources/views` with contextual escaping; captions restricted by a `wp_kses` policy.
- [ ] M3-08 Layout `grid` (CSS grid, responsive columns/spacing).
- [ ] M3-09 **[D10]** Layout `masonry` (usable without JS).
- [ ] M3-10 Layout `justified` (CSS-only via aspect-ratio flex rows, works without JS).
- [ ] M3-11 **[D5]** Frontend pagination: per-instance paging, several galleries per page independent, bounded page size, no-JS.
- [ ] M3-12 Unknown layout → `grid` + diagnostic visible to editors only.
- [ ] M3-13 Fragment cache: key = gallery revision + settings hash + visibility context + media epoch; never caches non-public output; works with and without persistent object cache.
- [ ] M3-14 Invalidation: gallery/item/settings changes, attachment update/delete/regenerated sizes, plugin update.
- [ ] M3-15 Lightbox (TypeScript, no third-party service): dialog semantics, focus trap/return, Escape/arrows, counter, captions/alt, neighbour preloading, reduced motion.
- [ ] M3-16 Lightbox touch swipe without hijacking scroll/pinch; multiple independent instances; images remain plain links without JS.
- [ ] M3-17 Frontend build: Vite pipeline for `assets/frontend` + `assets/lightbox` into generated `assets/build/` (gitignored) with manifest; enqueue only on pages rendering a gallery, plugin version, no global injection.
- [ ] M3-18 Vitest for `assets/*` TypeScript; JS/TS coverage gate and Sonar include these sources (merged with `apps/admin`, still > 80 %).
- [ ] M3-19 Lightbox/frontend strings server-localized (en/de).
- [ ] M3-20 Renderer tests: snapshot per layout, large gallery fixture (query-count budget), missing attachments, stale cache, multiple instances.
- [ ] M3-21 Frontend E2E + axe: render, paging, lightbox keyboard/focus/touch, JS disabled.

## M4 — WordPress surfaces

- [ ] M4-01 Shortcode `[sw_gallery id="123"]`: strict attribute allowlist/validation, same renderer.
- [ ] M4-02 PHP API `swg_render_gallery( int $id, array $args = array() ): string` (returns, never echoes; PHPDoc contract, `@since`).
- [ ] M4-03 Block `sw-gallery/gallery`: `block.json`, attributes = gallery ID + validated overrides (no media IDs), `render_callback` → same renderer.
- [ ] M4-04 Block editor glue in `assets/blocks` (minimal TS, WordPress components as externals): gallery selector, server-rendered preview, link to the Vue editor; versioned assets.
- [ ] M4-05 Block tests: Vitest for glue (counts towards the JS gate), PHP test that block, shortcode and PHP API produce identical output and respect visibility.
- [ ] M4-06 Block i18n via `wp_set_script_translations()` (JSON catalogs generated by `bin/i18n.sh`).
- [ ] M4-07 Layout registration extension point (`swg_register_layouts`), versioned contract, example in docs.
- [ ] M4-08 Import provider registration extension point (contract only, Media Library provider registered through it).
- [ ] M4-09 Public actions/filters for gallery/item lifecycle and render arguments, each with failure policy in `docs/hooks.md`.
- [ ] M4-10 Listener isolation tests (throwing listener, vetoing `before_*` filter, read-only payloads).
- [ ] M4-11 Optional integrations (sw-image-optimizer, sw-slider, sw-forms, sw-backupit, sw-cloudcalendar): verify no hard dependency; implement only where a concrete contract exists, otherwise document as not implemented.
- [ ] M4-12 **[D7]** Admin side-panel registry.
- [ ] M4-13 Gutenberg E2E: insert block, select gallery, preview, publish, frontend render; shortcode in classic content.
- [ ] M4-14 `docs/extending.md` (layouts, providers, hooks, PHP API) — short, links instead of duplication.

## M5 — Hardening / release

- [ ] M5-01 Performance: fixtures with 1k/5k items; budgets for query count, memory, response size (REST + render); fix regressions; decide M2-07 move command.
- [ ] M5-02 Frontend performance check: LCP image not lazy, no CLS, asset size budget for lightbox/frontend bundles.
- [ ] M5-03 Accessibility: axe in all E2E flows + documented manual review (keyboard, screen reader, reduced motion).
- [ ] M5-04 Security review against C§5 and S§10: authorization matrix, escaping sinks, `v-html` ban (lint rule), unserialize ban, no paths/secrets in REST/logs; regression test per finding.
- [ ] M5-05 Migration tests: upgrade, interrupted/retried migration, downgrade-safe failure, multisite prefixes.
- [ ] M5-06 Uninstall/deactivate tests: default preserves everything, opt-in removes plugin data only, attachments always preserved.
- [ ] M5-07 Full E2E suite per S§11 incl. remove/restore and role restrictions; flake-free in CI.
- [ ] M5-08 Supported matrix: define and test PHP × WordPress versions (min PHP 8.2 / WP 6.4 to current), MariaDB/MySQL; document in README.
- [ ] M5-09 `bin/build-zip.sh`: production Composer deps, Mozart prefixing (`SWGallery\Dependencies\`), compiled admin/blocks/frontend, compiled `.mo`; reproducible output.
- [ ] M5-10 `bin/verify-zip.sh`: no tests, dev deps, `.env`, coverage, secrets, unintended source maps; ZIP installs and activates in the E2E environment.
- [ ] M5-11 Release workflow: build ZIP on semver tags (optional `v`), checks must pass.
- [ ] M5-12 Daily dependency audit workflow (Composer + npm), findings recorded.
- [ ] M5-13 SonarQube quality gate green; open issues/hotspots assessed.
- [ ] M5-14 Docs: README (features, requirements, matrix, privacy note that private galleries do not protect upload URLs), `docs/user-guide.md`, `docs/security.md`, `docs/architecture.md` — concise, no duplication.
- [ ] M5-15 Release metadata: real Plugin URI, changelog, version bump procedure with single source for the version, `1.0.0` tag.
- [ ] M5-16 Originality check (S§1): names, UI texts and assets are independent of other gallery plugins.
- [ ] M5-17 **[D8, D9]** GitHub self-update / signed releases.

## Post-1.0 backlog (not started before M5 is done; split into tasks when scheduled)

### 1.1
- [ ] R1.1-01 Collections: `swg_collection`, `swg_collection_galleries` table + migration, repository, REST `/collections`, admin feature, block.
- [ ] R1.1-02 Design presets (`resources/presets`, admin page).
- [ ] R1.1-03 Layouts carousel, focus, mosaic through the layout registry (candidates: File: "docs/layout-proposals.md").
- [ ] R1.1-04 Categorization: `swg_gallery_category`, `swg_gallery_tag`; frontend filtering.
- [ ] R1.1-05 Lightbox deep links and zoom.
- [ ] R1.1-06 Load-more UI with access-checked public endpoint.

### 1.2
- [ ] R1.2-01 Batch metadata editor.
- [ ] R1.2-02 ZIP import (zip-bomb/resource limits) and bulk export; "Import & Export" navigation entry appears with the first working feature.
- [ ] R1.2-03 Saved/smart queries and taxonomy-based dynamic galleries.
- [ ] R1.2-04 Migration/import adapters for other gallery data.
- [ ] R1.2-05 Local-folder import limited to configured safe roots (path guards).

### External-source milestone (S§9, C§6)
- [ ] EX-01 Security design first: `CredentialStoreInterface` strategy, deployment prerequisites, personal vs site-wide connections.
- [ ] EX-02 Tables `swg_import_sources`, `swg_import_objects` + migrations; source/mapping repositories.
- [ ] EX-03 Job queue (`ImportJobQueueInterface`): checkpoints, backoff with jitter, cancellation, concurrency control, real-cron/WP-CLI worker.
- [ ] EX-04 Generic import workflow: bounded temp download, content validation, sideload, provenance, dedup/refresh policy, cleanup, honest counts.
- [ ] EX-05 SSRF/redirect/host guards and resource caps.
- [ ] EX-06 REST `/sources`, `/sources/{id}/folders`, `/imports`, `/imports/{id}`, `/imports/{id}/cancel` (rate limits, idempotency).
- [ ] EX-07 Admin "External Sources" UI (connections, browse, select, summary, progress, history, disconnect).
- [ ] EX-08 First provider end to end (assess current API, scopes, quotas, verification), mocked OAuth/HTTP in CI.
- [ ] EX-09 Second provider without changing the generic workflow or data model.
- [ ] EX-10 Acceptance tests S§9.7.

### 2.x (only if justified)
- [ ] R2-01 Scheduled sync and conflict policies.
- [ ] R2-02 Private-file delivery.
- [ ] R2-03 Watermarking, download bundles, client selections, video, remote-storage adapters.

