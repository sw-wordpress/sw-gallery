# TODO

Plan derived from `SPECIFICATION.md` (S§) and `CODE-STYLE.md` (C§); finished work is in `DONE.md`, decisions in `docs/decisions.md`.
Order is binding: M1 → M5 (S§14.4). No P1/P2 work before P0 (M1–M5) is complete (S§2.2).
Task IDs are stable identifiers; the list order inside a milestone is the execution order.
Every task ships with tests, i18n (en/de), docs and passes the Definition of Done (S§12). Decisions referenced as D n are recorded in `docs/decisions.md`.

## Open decisions (answer before the blocked task starts)

None at the moment. Decided on 2026-11-10: D1–D13, F-01 (rejected), F-02, F-03, F-09, F-10 — see `docs/decisions.md`.
Still to decide later (not blocking 1.0): own gallery URLs before R1.1-05 (D13), wordpress.org distribution (D9).

## Parallelization plan

Goal: run independent streams with sub-agents (one git worktree each, `isolation: worktree`), without conflicting edits. Max. 3–4 concurrent agents; more adds cost, not speed.
Rules for agents are in `AGENTS.md` ("Parallel work"). The orchestrator (main session) owns `TODO.md`, `DONE.md`, merge order and the final checks.

**Wave 0 — DONE 2026-11-10; Wave 1 — DONE 2026-11-10 (A, B, C, D merged). Wave 0 notes:** M1-01 to M1-04 and the interfaces of M1-13 (entities, repository, access-policy, layout and import contracts, no implementations); M1-18 registrar skeleton (`Plugin::boot` only iterates registrar classes, so features never edit the bootstrap again). Commit before any agent starts. Reusable for the adapter streams: `tests/Support/GalleryRepositoryContract.php` (run it against every `GalleryRepositoryInterface` implementation) and `InMemoryGalleries`.

| Wave | Streams (parallel) | Tasks | Owns (exclusive paths) |
|---|---|---|---|
| 1 | A core use cases | M1-05, M1-07, M1-09, M1-10, M1-11 (+ M1-02/M1-03 test doubles) | `packages/core/`, `tests/Unit/`, `tests/Support/` |
| 1 | B tooling/CI | M1-21, M1-22, M1-23, M1-24 | `bin/`, `tests/wp/`, `.github/`, `phpunit.xml.dist`, `languages/`, `tests/coverage/` |
| 1 | C admin scaffold | M2-14, M2-20 (then M2-15 after A+B merged) | `apps/admin/` |
| 1 | D import provider | M1-13 implementation, M1-14 | `packages/core/src/**/Import/`, `packages/wordpress/src/Import/` |
| 2 | A adapter | M1-06, M1-08, M1-12, M1-15 to M1-17, M1-19, M1-20 (after A merged; may split repository/lifecycle vs. migration/uninstall) | `packages/wordpress/src/` (own subfolders), `tests/wp/` |
| 2 | B E2E env | M2-36 (needs M2-15) | `e2e/`, `tests/e2e-env/` |
| 2 | C frontend build + lightbox | M3-17, M3-15, M3-16, M3-18 (markup contract from M3-07 draft) | `assets/frontend/`, `assets/lightbox/`, `vite` config |
| 3a | A design alignment (D14) | M2-39 (includes M2-21): tokens, fonts, shell, dark mode, UI building blocks, page header, E2E isolation update | `apps/admin/`, `e2e/` (CSS-isolation spec only) |
| 3a | B REST foundation + galleries | M2-01 to M2-04 (+ FU-03 `canPublish`) | `packages/wordpress/src/Rest/` (Base, Galleries) |
| 3a | C wiring (orchestrator) | M1-18 / FU-04: service registry; FU-01 test harness hygiene | `packages/wordpress/src/Bootstrap/`, `tests/wp/support/` |
| 3b | Gallery list on real data | M2-22, M2-23 (needs 3a A + B + C merged); then the remaining Wave 3 rows | `apps/admin/src/features/galleries/` |
| 3 | REST per route group | galleries (M2-02–04), items/order (M2-05–07), media (M2-09), settings/status/layouts (M2-08, M2-10, M2-11) | `packages/wordpress/src/Rest/<Group>/`; shared `Rest` base (M2-01) merged first |
| 3 | Vue features against mocks | list (M2-22/23), editor shell (M2-24), items grid + reorder (M2-25/26), picker/upload (M2-27/28), details + settings panel (M2-29/30) | `apps/admin/src/features/<feature>/` |
| 4 | Renderer, then layouts | M3-01 to M3-07 serial; then grid, masonry, justified (M3-08–10) and every layout of `docs/layout-proposals.md` as one agent each | `packages/wordpress/src/Rendering/Layout/<Id>/`, `resources/views/layouts/<id>/` |
| 4 | WordPress surfaces | shortcode (M4-01), PHP API (M4-02), block + glue (M4-03/04) | `Shortcode/`, `Block/`, `assets/blocks/` |
| 5 | Hardening | M5 tasks by theme (performance, security review, a11y, docs); review agents get code, not conclusions | per task |

**Merge rules**
- Order inside a wave: tooling → core → adapter → UI. After every merge the orchestrator runs `composer check`, `composer test:coverage`, and for JS `npm run typecheck && npm run lint && npm run test:coverage`; failures go back to the owning agent.
- Shared files are edited by one owner only: `composer.json`/lockfiles/`package.json` (stream B or C of the wave), `phpunit.xml.dist`/`phpcs*.xml.dist`/`phpstan.neon.dist` (stream B), `docs/hooks.md` (append-only, sorted at merge), `TODO.md`/`DONE.md` (orchestrator).
- Translations: agents add strings to their own feature files/keys; the orchestrator runs `bin/i18n.sh update` + `check` once per merge.
- Contracts change only through the orchestrator; an agent that needs an interface change stops and reports instead of editing it.
- Each agent ends with: changed files, commands run with results, open points, and the German tester checklist required by `AGENTS.md`.

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

Status after the review on 2026-11-10. Gaps were turned into acceptance criteria of the named tasks; open decisions moved to the table above; ideas moved to the post-1.0 backlog (need approval before they are built).

| Finding | Resolution |
|---|---|
| F-01 walking skeleton | Rejected, order stays (decisions.md). |
| F-02 E2E environment late | M2-36 moved directly after M2-15. |
| F-03 preview crosses milestones | Preview route and live preview moved to M3 (M3-22, M3-23); no 501 placeholder. |
| F-04 no decision record | `docs/decisions.md` created. |
| F-05 links into `../sw-backupit` | Self-contained copies required in M1-23, M1-25, M2-14, M5-11. |
| F-06 compatibility policy | M4-14. |
| F-07 Mozart only with runtime deps | M5-09. |
| F-08 portability | Decided as D12; M2-11, M5-14, R1.2-02. |
| F-09 post revisions | Decided; removal of `revisions` support in M1-15. |
| F-10 description format | Decided; M1-01. |
| F-11 cover rules, F-12 media types, F-14 settings forward compatibility | Defaults in decisions.md; M1-01, M1-05, M1-06, M1-16. |
| F-13 items per gallery | M2-07, M5-01. |
| F-15, F-16, F-24, F-27, F-31, F-32 | Ideas → backlog "Ideas". |
| F-17 late asset loading, F-22 browsers | M3-17, M2-14. |
| F-18 page caches/CDN | M3-14. |
| F-19 feeds/excerpts | M3-07. |
| F-20 image sizes | Default in decisions.md; M3-05. |
| F-21 RTL | M2-18, M3-08, M3-15. |
| F-23 theme integration | M3-08, M4-03. |
| F-25 own gallery URL | Decided as D13 (embed-only in 1.0). |
| F-26 nonce/login expiry | M2-17. |
| F-28 Media Library integration | M1-16, M2-38. |
| F-29 editor entry points | M2-19, M4-04, M4-15. |
| F-30 list counts | M2-02. |
| F-33 empty states, F-34 seed script | M2-22, M2-36. |
| F-35 no public route in 1.0 | M2-13. |
| F-36 logger sink | Default in decisions.md; M1-08, M2-11. |
| F-37 privacy, F-39 no download protection | M5-14. |
| F-38 wordpress.org requirements | D9: GitHub only for 1.0; relevant only if wordpress.org is chosen later. |

## M1 — Foundation (in progress)

Core domain and contracts
- [x] M1-01 `Gallery` entity + value objects (`GalleryStatus`, `GallerySettings`, cover, owner, plain-text description per F-10); cover = any readable image, not necessarily a member; invariants unit-tested.
- [x] M1-02 `GalleryRepositoryInterface` (find, search page, create, update, duplicate, trash, restore, delete) + in-memory test double.
- [x] M1-03 `GalleryAccessPolicyInterface` with actor/context value objects (view, edit, delete, manage items); decision table unit-tested incl. D4 status rules.
- [x] M1-04 Layout contracts: `GalleryLayoutInterface` (`getId`, `getLabel`, `getSettingsSchema`, `render`), `LayoutRegistryInterface` with `grid` fallback result flag; `ThumbnailResolverInterface`.
- [x] M1-05 Settings schema: versioned `GallerySettings`/item settings, responsive values, strict schema subset validator in core (no dependency); unknown keys from newer versions preserved; `SortMode` enum per D3; register all S§3.1 meta with schemas and auth callbacks.
- [x] M1-06 `MediaRepositoryInterface` extension: batched `MediaReference` lookup (dimensions, MIME, alt, caption, title) without N+1; allowed MIME types per decisions.md (no SVG/HEIC); `WpMediaRepository` implementation.
- [x] M1-07 Core `EventDispatcherInterface` + events (items assigned/removed/reordered, gallery created/updated/trashed/restored/deleted/duplicated); listener failures isolated.
- [x] M1-08 Core `LoggerInterface` (structured, safe context, no secrets) + WordPress adapter (`error_log` with `WP_DEBUG_LOG`, bounded ring buffer of 50 errors in a non-autoloaded option); `swg_*` hook bridge with `before_*` veto; document each hook in `docs/hooks.md`, fix its C§ reference.
- [x] M1-09 `TransactionRunnerInterface`; assign/reorder/remove/bulk-remove atomic incl. revision claim; rollback and non-transactional-engine fallback tested.
- [x] M1-10 Item use cases: bulk remove (bounded), update overrides (`NULL` inherit vs `''` blank), set item settings; typed results.
- [x] M1-11 Gallery use cases (`CreateGallery`, `UpdateGallery`, `DuplicateGallery`, `Trash/Restore/DeleteGallery`); every persisted change bumps `_swg_revision`, stale writes raise `RevisionConflictException`.
- [x] M1-12 Access policy implementation per D1 (`post` caps, `map_meta_cap`, ownership) and D11 (readable attachments may be assigned; global metadata needs `edit_post` on the attachment).
- [x] M1-13 Import contracts only: `ImportSource`, `RemoteMediaObject`, `ImportResult`, `ImportCapabilities`, `FolderPage`, `RemoteMediaPage`, `MediaImportProviderInterface`, `ImportJobInterface`, provider registry; no tables, no connectors.
- [x] M1-14 Media Library provider implementing M1-13 (pages local attachments, "import" resolves to the existing attachment, no copy); contract test suite reusable for later providers.

WordPress adapter
- [x] M1-15 `WpGalleryRepository` on `swg_gallery` (CRUD, duplicate copies membership/overrides only, cover = featured image, status, owner); remove `revisions` from CPT `supports` (F-09); attachments never touched.
- [x] M1-16 Lifecycle hooks: `delete_attachment` removes relations + clears cover references (render falls back to first item); `before_delete_post` removes gallery relations; trash/untrash keeps relations.
- [x] M1-17 Consistency repair service + `wp swg repair` (dry-run default, bounded batches, report); no deletions during read requests.
- [x] M1-18 Service registry/bootstrap with constructor injection; hook registration in dedicated registrars (C§3.2).
- [x] M1-19 Multisite + uninstall: schema on network activation and `wp_initialize_site`, per-site prefix; uninstall bounded/batched, all sites, removes options/transients only with opt-in.
- [x] M1-20 Schema migration runner: ordered versioned steps, repeatable, failure leaves version unchanged and retries; upgrade-from-v1 fixture test.

Tooling and tests
- [x] M1-21 `bin/test-db.sh` + `tests/wp` environment (own Composer, single site + multisite, `composer test:wp`, `test:wp:ms`); integration tests for `WpdbGalleryItemRepository`, `PostMetaRevisionStore`, `Schema`, lifecycle hooks, uninstall preservation.
- [x] M1-22 PHP coverage: add adapter, plugin file and `uninstall.php` to `<source>`, merge unit + WordPress-suite Clover, gate > 80 %; test that header version equals `SWGALLERY_VERSION`.
- [x] M1-23 `bin/i18n.sh` (update/check), `bin/i18n-core-messages.php`, `languages/` (pot, de_DE po/mo), `I18nTest`; scripts copied self-contained (no runtime reference to `../sw-backupit`).
- [x] M1-24 `.github/workflows/tests.yml`: cs, cs:wp, stan, unit, WordPress suites (PHP × WP matrix), coverage gate, `composer audit`, Sonar upload.
- [ ] M1-25 Repository basics: `README.md` (requirements, links), `LICENSE`. (Initial commit, worktree ignore rule and removal of doc links into `../sw-backupit` are done.)

## Follow-ups from Wave 1/2 (small tasks, do together with the next matching milestone)
- [ ] FU-01 (deferred, not part of Wave 3a: needs a careful per-test cleanup) Test harness (`tests/wp/support/SwgTestCase.php`): `tear_down()` runs `TRUNCATE`/dbDelta (DDL = implicit COMMIT) before the parent rollback, so posts/users/attachments leak between tests. Several tests depend on today's behaviour (a plain reorder or `DELETE` breaks 9+ tests, tried): fix deliberately with a per-test cleanup, not by editing the base class blindly.
- [x] FU-02 `MediaLibraryProvider` uses `edit_post` per attachment (authors see only their own uploads) — stricter than D11 and than `WpGalleryAccessPolicy::canUseAttachment`. Align it with the policy or document why not (M4-08).
- [x] FU-03 (done 2026-11-11 in the REST layer; `publish` and `private` need the publish right) REST (M2-03): call `WpGalleryAccessPolicy::canPublish` when a request sets status `publish`; `GalleryService` has no publish check.
- [x] FU-04 (done 2026-11-11: `Infrastructure/Services` builds all adapters and core services; `ServicesIntegrationTest` runs the full life cycle against the real database) Service wiring (M1-18, orchestrator): build `Services`-based registry — `WpGalleryRepository`, `WpdbGalleryRelations`, `WpGalleryAccessPolicy`, `WpMediaRepository`, `WpdbGalleryItemRepository(Services::transactions())`, `PostMetaRevisionStore`, `ConsistencyRepair`, core `GalleryService`/`GalleryItemService`/`GalleryItemEditService` with `Services::logger()`/`events()`.
- [ ] FU-06 E2E: on this host ~1 in 3 page loads lose all requests (`net::ERR_NETWORK_CHANGED`, many Docker veth interfaces churning); `retries: 1` hides it (reported as flaky). Verified in a trace: WordPress's own scripts fail too, so not a plugin defect. Revisit on CI.
- [x] FU-07 (not reproducible after Wave 5; regression tests added) Quasar creates two `#q-notify` containers on `<body>` (duplicate id, harmless, present since the scaffold; `Notify` is installed only once in `main.ts`) — find the cause (Quasar internals) and fix.
- [x] FU-08 Mobile (375 px): the five nav tabs overflow and scroll horizontally, the fifth is cut off at the edge (same as the siblings); consider a visible scroll affordance.
- [x] FU-09 Core `ValidationError` messages are not in the gettext catalog (REST returns them in English unless they match a catalog entry): route them through `CoreMessages` / a message map keyed by error code.
- [x] FU-10 (done 2026-11-12: `canPublishNew`) `WpGalleryAccessPolicy::canPublishNew(Actor)`: creating a gallery with status `publish`/`private` is currently checked in the REST controller (`publish_posts`); move the rule into the policy so every entry point shares it.
- [x] FU-11 (done 2026-11-12: `defaultLayout` on create, `itemsPerPage` in the renderer; `lightbox` read by the asset enqueue) `GalleryController::create` does not use the `defaultLayout` plugin setting yet (settings API exists); and the renderer does not read `itemsPerPage`/`lightbox` yet.
- [x] FU-12 `Rest/ItemRowReader` contains prepared SQL inside the REST layer (the repository's `findPage` omits item settings); move it into `WpdbGalleryItemRepository` as a batched read.
- [x] FU-13 (done 2026-11-12: `previewAccess`) Preview of drafts: the single access decision returns `Diagnostic` for drafts even for editors; the preview task (M3-22) needs an explicit authorised-preview rule in the policy.
- [x] FU-14 (done 2026-11-11: `workers: 3` in `e2e/playwright.config.ts`; with ~10 workers two tests failed even after the retry, with 3 workers the whole suite passes) E2E host noise.
- [x] FU-15 Gallery list polish: placeholder cover tile too tall (4:3 on mobile), double focus ring in the search field, 375 px rail nav slightly wider than the viewport.
- [ ] FU-16 Contributors lack `upload_files`, so under D11 all of their items count as `missing`: decide whether contributors may use already-readable library images (D11 revisit).
- [x] FU-17 `swg_gallery_changed` fires only from core events: `RevisionBump` (attachment deleted, cover cleared, repair run) bumps revisions without an event, so full-page caches/CDNs are not purged in those cases (the object-cache fragment cache stays correct through the media epoch) — emit the action from `GalleryLifecycleRegistrar`/repair too.
- [ ] FU-18 Pagination arg `swg_page_<galleryId>` is shared by two embeds of the same gallery on one page (documented); the shortcode/block must omit `page` unless the embed really sets it, otherwise the URL arg never takes effect.
- [x] FU-19 Admin app: a lazy chunk that fails to load (flaky network) fails silently; add a router `onError` message with retry.
- [x] FU-20 Block editor preview is unstyled (no gallery stylesheet in the editor): load the layout stylesheet via `editorStyle`/`enqueue_block_assets`.
- [ ] FU-21 Release packaging (M5-09/M5-10): the ZIP must ship `assets/blocks/block.json` and `assets/blocks/build/`, `assets/build/`, `assets/admin/`, the compiled `.mo` and the block JSON catalog; the build script must run all three npm builds.
- [x] FU-22 Docs: `docs/development.md` must describe the `assets/` and `assets/blocks` projects, `bin/rebuild-on-change.sh` and the `COMPOSER_PROCESS_TIMEOUT=0` need of the long WordPress suites.
- [x] FU-23 Shortcode output is not width-constrained by block themes (full width in Twenty Twenty-Five inside constrained post content, the block version gets alignment support): decide whether the shortcode wrapper should carry the theme's content-width class (F-23).
- [ ] FU-24 Keyboard-only reorder was not tested with a real screen reader; touch drag-and-drop is untested (keys and buttons are the alternative).
- [ ] FU-25 Link names: an image with empty alt text yields a link without accessible name (axe `link-name`, serious); renderer should fall back to the title or an `aria-label` (product decision).
- [ ] FU-26 Admin: gallery editor `GalleryEditor.vue` still uses `<header>`/`<aside>` (moderate axe waiver `landmark-*-is-top-level` in `a11y.spec.ts`): turn into divs and delete the waiver; editor settings form shows raw enum values (`left`/`justify`) — needs enum titles in the schema renderer.
- [ ] FU-27 Media Library: no delete warning in the grid-view media modal (needs Backbone code).
- [ ] FU-28 Editor live preview: the preview is a modal now (settings unreachable while open); a title-only save re-renders the preview; `diagnostics[].message` is shown untranslated; staged order/removals are not previewed (server has no staged-items input).
- [ ] FU-29 Media Overview: uploader filter is a numeric user id (no users endpoint); justified layout breaks rows greedily (a very narrow image may stretch alone on a narrow container).
- [ ] FU-31 Editor items grid keeps its fixed tile size: at 2560 px six tiles leave much empty space on the right (consider larger tiles or a size slider).
- [ ] FU-30 Public API: `@since` tags only on the layout interface; add them to all public hooks/classes. Flaky E2E on first attempt under host load (admin-shell, theme-and-rail, justified 768) — keep watching.
- [x] FU-05 Docs: `docs/development.md` must mention the `assets/` project, `bin/rebuild-on-change.sh`, `bin/watch-build.sh` and the new test commands.

## M2 — Management

REST API (`swg/v1`, S§8)
- [x] M2-01 REST foundation: controller base, `permission_callback` on every route (D1), exception → `WP_Error` mapper (400/401/403/404/409/422), bounded pagination headers, schema-validated input/output.
- [x] M2-02 `GET/POST /galleries` (search, status/owner filter, paging, cover; item counts from one grouped query, not one per row).
- [x] M2-03 `GET/PATCH/DELETE /galleries/{id}` (`expectedRevision`, status, cover, owner change needs `edit_others_posts`; DELETE trashes, `force=true` deletes).
- [x] M2-04 `POST /galleries/{id}/duplicate` and `/restore`.
- [x] M2-05 `GET/POST /galleries/{id}/items` (paged list with resolved global + local metadata; bulk assign with added/duplicate/missing result).
- [x] M2-06 `PATCH/DELETE /galleries/{id}/items/{itemId}`, `POST /items/bulk-remove` (bounded batch).
- [x] M2-07 `PUT /galleries/{id}/order` (complete ID set, atomic, single `CASE` update instead of one query per item); evaluate move command for large lists after M5-01 measurements.
- [x] M2-08 `GET /layouts` (registered layouts + setting schemas).
- [x] M2-09 `GET /media` (permission-filtered per D11, images only, paged) and `POST /media` (native upload handling, `upload_files`, MIME/size errors mapped).
- [x] M2-10 `GET/PATCH /settings` (`manage_options`): defaults for layout, page size, lightbox, image sizes, delete-data-on-uninstall with explicit confirmation.
- [x] M2-11 `GET /status`: schema version, cache state, counts, repair findings summary, recent errors from the logger ring buffer, export limitation hint (D12), sanitized diagnostics (no paths/secrets).
- [ ] M2-13 (role matrices and the all-routes-refuse-anonymous invariant exist for every route group; open: nonce/cookie boundary test) REST test matrix: roles × routes, foreign-owned objects/attachments, nonce/auth boundaries, validation, paging limits, 409 races; test that no `swg/v1` route is reachable unauthenticated (D5); `docs/rest-api.md` (short).

Admin app (`apps/admin`, C§7)
- [x] M2-14 Scaffold modelled on sw-backupit's admin app, copied self-contained: Vue 3, Quasar, TS strict, Vite, ESLint, Prettier, `vue-tsc`, Vitest + Vue Test Utils, `.nvmrc`, lockfile, shared `browserslist` (decisions.md).
- [x] M2-15 Admin page registrar: menu, mount point, assets only on sw-gallery screens, validated Vite manifest, plugin version on every asset, safe diagnostic on missing build.
- [x] M2-36 `tests/e2e-env` (Docker Compose, throw-away WordPress + DB), `e2e/` Playwright project, `bin/test-e2e.sh`, CI job; dev-only `bin/seed.php` (galleries with generated images, never shipped) for E2E, acceptance checks and M5-01 fixtures. (Moved here per F-02.)
- [ ] M2-16 (scoping + build-time check done; open: regression test on a foreign admin screen) Quasar style isolation (`swg-` scope, no restyling of WordPress chrome); regression test on a foreign admin screen.
- [ ] M2-17 (API-client skeleton done: nonce header, abort, GET retry, 409; open: server bootstrap, Heartbeat/auth-check nonce refresh) Typed API layer: REST root/nonce/locale/capabilities from server bootstrap, error translation, cancellation, retry, 409 surface; nonce refresh via Heartbeat and core auth-check dialog on expired login, unsaved changes kept; no `fetch()` in components.
- [ ] M2-18 (en/de catalogs, parity test, Quasar language pack done; open: RTL mode, WordPress locale/timezone formatting) i18n bridge: vue-i18n en/de, Quasar language pack follows user locale, RTL mode for RTL locales, parity test; date/number formatting per WordPress locale/timezone.
- [ ] M2-19 (router + five placeholder pages done; open: deep-linkable editor route) Router + navigation shell (Overview, Galleries, Media Overview, Settings, System Status; nothing for unshipped features); stable deep-linkable routes (e.g. editor of gallery n) used by block and admin bar.
- [x] M2-20 Coverage script `scripts/check-coverage.mjs` (> 80 %), CI job (typecheck, lint, tests, production build, `npm audit`), Sonar lcov.
- [x] M2-39 **Design alignment with the sw-* plugins (D14), Wave 3a, planned for the next working day.** Self-contained copies (prefix `swg-`, no `sw-backupit` names or runtime references) of: design tokens with **identical colour values** (indigo primary `#4f46e5`/strong/soft, teal accent, success/warning/danger incl. text and background tones, light + dark), self-hosted fonts (Inter, JetBrains Mono for numbers/code; woff2 only), `ShellLayout` (rail with brand block showing a gallery icon, product name and version, collapse, dark-mode toggle persisted per viewer, skip link, polite live region, capability-based navigation, `NoAccess`), `useTheme`/app store, and the UI building blocks PageHeader, Card, StatusChip, StatTile, EmptyState, ErrorState, DataState, ConfirmDialog, FormSheet, SideSheet, PaginationBar, SegmentedControl. Placeholder pages move to PageHeader/EmptyState. Keep CSS isolation (`.swg-root` scoping, build check) and the single PHP-owned h1. Update the E2E CSS-isolation spec (dark mode, fonts, no restyling of wp-admin) and unit tests, bundle-size note, light/dark/mobile screenshots for review against the sibling screenshots. Acceptance: Stefan compares with sw-backupit/sw-forms. Open: gallery icon/logo and display name.
- [x] M2-21 (delivered by M2-39) Shared state components: loading, empty, error, retry; confirmation dialog naming affected objects.
- [x] M2-22 Gallery list: grid + table, search, status filter, server paging; cover, title, count, status, modified; first-run empty state leads to "create gallery".
- [x] M2-23 Gallery list actions: create, duplicate, trash, restore, delete permanently (confirmation), preview, copy shortcode.
- [x] M2-24 Editor shell: title, plain-text description, cover, status, save/publish, unsaved-change warning, revision tracking.
- [x] M2-25 Editor image grid: server-paged/virtualized items, bulk selection, bulk remove with undo before save.
- [x] M2-26 Reorder: drag-and-drop (dependency license/maintenance review) plus keyboard/button alternative with screen-reader announcements.
- [x] M2-27 Media picker and upload via native `wp.media` modal (D2) with added/duplicate/missing feedback.
- [x] M2-28 Upload progress inside the picker flow: real per-file progress, partial-failure reporting (n of m), retry.
- [x] M2-29 Image details panel: global vs gallery-local title/alt/caption (inherit vs blank), dimensions, MIME, filesize, ID.
- [x] M2-30 Layout/settings panel generated from `/layouts` schemas, responsive values, sort mode (D3), validation errors.
- [x] M2-32 Conflict UX for 409: show what changed, reload or re-apply local edits; tested.
- [x] M2-33 Media Overview page per D6: permission-filtered attachment browser (search, server paging), "used in n galleries", only action "add to gallery" (gallery selection dialog, same bulk-assign result feedback as M2-27).
- [x] M2-34 Overview, Settings and System Status pages.
- [x] M2-38 Media Library integration: "used in n galleries" in attachment details; warning before deleting an attachment that is used in galleries (relations are still removed by M1-16).
- [x] M2-35 Accessibility pass of the admin app (focus, labels, contrast, reduced motion; WCAG 2.2 AA goal).
- [x] M2-37 Admin E2E: create gallery, upload/pick, reorder (mouse + keyboard), edit overrides, save/publish, duplicate, trash/restore, role restrictions.

## M3 — Frontend

- [x] M3-01 Core `RenderGallery` pipeline (S§5 steps 1–8) with `RenderContext` (block, shortcode, PHP API, preview, feed) and render result (HTML, required assets, diagnostics).
- [x] M3-02 Status + access enforcement per D4 in the pipeline; identical for all entry points; bypass tests (shortcode, block, preview, paging).
- [x] M3-03 Paged item query with batched attachment/meta priming (no N+1), sort modes per D3, skips missing/trashed attachments without deleting relations.
- [x] M3-04 Override resolution (local > global, `NULL` vs `''`) without mutating attachment metadata.
- [x] M3-05 `ThumbnailResolver` adapter: core/theme sizes only, CSS cropping, `srcset`/`sizes` per layout, width/height, lightbox target size.
- [x] M3-06 Loading strategy: first row eager/`fetchpriority`, rest lazy; CLS-safe aspect ratios.
- [x] M3-07 View layer `resources/views` with contextual escaping; captions restricted by a `wp_kses` policy; simplified markup for feeds, excerpts and REST `content.rendered` (no CSS/JS dependency).
- [x] M3-08 (grid layout, CSS, aspect setting, theming contract; done in Waves 3a/4) Layout `grid` (CSS grid, responsive columns/spacing, logical properties for RTL, documented CSS custom properties and `swg-` classes as public theming contract).
- [x] M3-09 Layout `masonry` per D10 (row-ordered markup, grid-like without JS, JS column packing keeps DOM order).
- [x] M3-10 Layout `justified` (CSS-only via aspect-ratio flex rows, works without JS).
- [x] M3-11 Frontend pagination per D5: link-based, per-instance query arg, several galleries per page independent, bounded page size, no JS.
- [x] M3-12 Unknown layout → `grid` + diagnostic visible to editors only.
- [x] M3-13 Fragment cache: key = gallery revision + settings hash + visibility context + media epoch; only `publish` output cached (D4); works with and without persistent object cache.
- [x] M3-14 Invalidation: gallery/item/settings changes, attachment update/delete/regenerated sizes, plugin update; documented action `swg_gallery_changed` (gallery ID) for full-page cache/CDN purging; docs note on page caches and paging/password output.
- [x] M3-15 Lightbox (TypeScript, no third-party service): dialog semantics, focus trap/return, Escape/arrows (mirrored in RTL), counter, captions/alt, neighbour preloading, reduced motion.
- [x] M3-16 Lightbox touch swipe without hijacking scroll/pinch (direction mirrored in RTL); multiple independent instances; images remain plain links without JS.
- [x] M3-17 (build + manifest + PHP enqueue with ver, only where rendered, late-render strategy) Frontend build: Vite pipeline for `assets/frontend` + `assets/lightbox` into generated `assets/build/` (gitignored) with manifest, shared `browserslist`; enqueue only on pages rendering a gallery, plugin version, no global injection; late-render strategy (detect block/shortcode in `wp_enqueue_scripts` where possible, footer fallback, minimal critical CSS against unstyled flash).
- [x] M3-18 Vitest for `assets/*` TypeScript; JS/TS coverage gate and Sonar include these sources (merged with `apps/admin`, still > 80 %).
- [ ] M3-19 (labels come from `data-swg-labels` incl. the optional `error` key — the PHP views must emit them) Lightbox/frontend strings server-localized (en/de).
- [x] M3-22 `POST /galleries/{id}/preview` (authorized, accepts unsaved settings, same renderer, never cached). (Was M2-12, moved per F-03.)
- [x] M3-23 Editor live preview in the Vue app (sandboxed, frontend CSS, debounced) using M3-22. (Was M2-31, moved per F-03.)
- [ ] M3-20 Renderer tests: snapshot per layout, large gallery fixture (query-count budget), missing attachments, stale cache, multiple instances, feed context.
- [x] M3-21 Frontend E2E + axe: render, paging, lightbox keyboard/focus/touch, JS disabled, editor live preview.

## M4 — WordPress surfaces

- [x] M4-01 Shortcode `[sw_gallery id="123"]`: strict attribute allowlist/validation, same renderer.
- [x] M4-02 PHP API `swg_render_gallery( int $id, array $args = array() ): string` (returns, never echoes; PHPDoc contract, `@since`).
- [x] M4-03 Block `sw-gallery/gallery`: `block.json`, attributes = gallery ID + validated overrides (no media IDs), `supports` for align wide/full and spacing, `render_callback` → same renderer.
- [x] M4-04 Block editor glue in `assets/blocks` (minimal TS, WordPress components as externals): gallery selector, server-rendered preview, deep link to the Vue editor; versioned assets.
- [x] M4-05 Block tests: Vitest for glue (counts towards the JS gate), PHP test that block, shortcode and PHP API produce identical output and respect visibility.
- [x] M4-06 Block i18n via `wp_set_script_translations()` (JSON catalogs generated by `bin/i18n.sh`).
- [x] M4-07 Layout registration extension point (`swg_register_layouts`), versioned contract, example in docs.
- [x] M4-08 Import provider registration extension point (contract only, Media Library provider registered through it).
- [x] M4-09 Public actions/filters for gallery/item lifecycle and render arguments, each with failure policy in `docs/hooks.md`.
- [x] M4-10 Listener isolation tests (throwing listener, vetoing `before_*` filter, read-only payloads).
- [x] M4-11 Optional integrations (sw-image-optimizer, sw-slider, sw-forms, sw-backupit, sw-cloudcalendar): verify no hard dependency; implement only where a concrete contract exists, otherwise document as not implemented.
- [x] M4-12 Per D7: document in `docs/extending.md` that an admin side-panel registry is not implemented in 1.0 (planned R1.1-07); no placeholder API.
- [x] M4-15 Admin bar "Edit gallery" entry on frontend pages rendering a gallery the user may edit (deep link from M2-19).
- [x] M4-13 Gutenberg E2E: insert block, select gallery, preview, publish, frontend render; shortcode in classic content.
- [x] M4-14 `docs/extending.md` (layouts, providers, hooks, PHP API, theming contract) incl. compatibility policy for public contracts (semver meaning, deprecation period, `@since`) — short, links instead of duplication.

## M5 — Hardening / release

- [ ] M5-01 Performance: seed fixtures with 1k/5k/10k items; budgets for query count, memory, response size (REST + render); document the tested maximum per gallery; fix regressions; decide M2-07 move command.
- [ ] M5-02 Frontend performance check: LCP image not lazy, no CLS, asset size budget for lightbox/frontend bundles.
- [ ] M5-03 Accessibility: axe in all E2E flows + documented manual review (keyboard, screen reader, reduced motion).
- [ ] M5-04 Security review against C§5 and S§10: authorization matrix, escaping sinks, `v-html` ban (lint rule), unserialize ban, no paths/secrets in REST/logs; regression test per finding.
- [ ] M5-05 Migration tests: upgrade, interrupted/retried migration, downgrade-safe failure, multisite prefixes.
- [ ] M5-06 Uninstall/deactivate tests: default preserves everything, opt-in removes plugin data only, attachments always preserved.
- [ ] M5-07 Full E2E suite per S§11 incl. remove/restore and role restrictions; flake-free in CI.
- [ ] M5-08 Supported matrix: define and test PHP × WordPress versions (min PHP 8.2 / WP 6.4 to current), MariaDB/MySQL; document in README.
- [ ] M5-09 `bin/build-zip.sh`: production Composer deps (Mozart prefixing `SWGallery\Dependencies\` only if runtime dependencies exist), compiled admin/blocks/frontend, compiled `.mo`; reproducible output.
- [ ] M5-10 `bin/verify-zip.sh`: no tests, dev deps, `.env`, coverage, secrets, unintended source maps; ZIP installs and activates in the E2E environment.
- [ ] M5-11 Release workflow: build ZIP on semver tags (optional `v`), checks must pass; self-contained in this repository.
- [ ] M5-12 Daily dependency audit workflow (Composer + npm), findings recorded.
- [ ] M5-13 SonarQube quality gate green; open issues/hotspots assessed.
- [ ] M5-14 Docs: README (features, requirements, matrix; private galleries do not protect upload URLs; no right-click/download protection; no telemetry or external requests; export limitation per D12), suggested privacy-policy text via `wp_add_privacy_policy_content` (EXIF/GPS stays in served files), `docs/user-guide.md`, `docs/security.md`, `docs/architecture.md` — concise, no duplication.
- [ ] M5-15 Release metadata: real Plugin URI, changelog, version bump procedure with single source for the version, `1.0.0` tag.
- [ ] M5-16 Originality check (S§1): names, UI texts and assets are independent of other gallery plugins.
- [ ] M5-17 GitHub self-update + signed releases per D8/D9: self-contained copy of the sw-backupit mechanism, isolated in one removable updater module; release manifest/signature verification tested; documented in `docs/github-updates.md`.

## Post-1.0 backlog (not started before M5 is done; split into tasks when scheduled)

### 1.1
- [ ] R1.1-01 Collections: `swg_collection`, `swg_collection_galleries` table + migration, repository, REST `/collections`, admin feature, block.
- [ ] R1.1-02 Design presets (`resources/presets`, admin page).
- [ ] R1.1-03 Layouts carousel, focus, mosaic through the layout registry (candidates: `docs/layout-proposals.md`).
- [ ] R1.1-04 Categorization: `swg_gallery_category`, `swg_gallery_tag`; frontend filtering.
- [ ] R1.1-05 Lightbox deep links and zoom; first decide whether galleries get own URLs (D13 revisit).
- [ ] R1.1-06 Load-more UI with access-checked public endpoint (first unauthenticated route: own security review).
- [ ] R1.1-07 Admin side-panel registry for the Vue editor (D7): design for extending a compiled SPA (e.g. registered panels rendering server-provided schema forms).

### 1.2
- [ ] R1.2-01 Batch metadata editor.
- [ ] R1.2-02 ZIP import (zip-bomb/resource limits) and bulk export incl. `wp swg export/import` with ID remapping (D12); "Import & Export" navigation entry appears with the first working feature.
- [ ] R1.2-03 Saved/smart queries and taxonomy-based dynamic galleries.
- [ ] R1.2-04 Migration/import adapters for other gallery data.
- [ ] R1.2-05 Local-folder import limited to configured safe roots (path guards).

### Ideas (need approval before scheduling)
- [ ] I-01 (F-15) "Recently removed" list / short item history so a saved bulk removal can be undone.
- [ ] I-02 (F-16) Multilingual sites (WPML/Polylang): verify nothing breaks; gallery translation later.
- [ ] I-03 (F-24) Mobile back button closes the lightbox (history entry); together with R1.1-05.
- [ ] I-04 (F-27) Core post lock ("X is currently editing") via Heartbeat in addition to 409 handling.
- [ ] I-05 (F-31) "Where is this gallery used" (posts containing block/shortcode), shown before trashing.
- [ ] I-06 (F-32) Convert `core/gallery` blocks or `[gallery ids="…"]` into an sw-gallery (block transform); fits R1.2-04.

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
