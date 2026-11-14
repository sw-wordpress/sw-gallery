# Architecture and product decisions

Recorded decisions that fill gaps in `SPECIFICATION.md` (S§) / `CODE-STYLE.md` (C§), as required by C§1. One line each; open questions live in `TODO.md`.

| ID | Date | Decision |
|---|---|---|
| D1 | 2026-11-10 | Galleries use the `post` capability type with `map_meta_cap`: authors manage own galleries, foreign ones need `edit_others_posts` / `delete_others_posts`. No role changes on activation. |
| D2 | 2026-11-10 | The editor picks/uploads images with the native `wp.media` modal; `GET/POST /media` serve the Media Overview and search. |
| D3 | 2026-11-10 | `_swg_sort_mode` ∈ `manual` (default), `date_asc`, `date_desc`, `title_asc`, `title_desc`. Non-manual modes sort at query time; stored positions stay untouched. |
| D4 | 2026-11-10 | Embedding: `publish` renders for everyone; `private` only with `read_post`; password-protected shows the core password form; `draft`/`pending`/`future`/`trash` render nothing (diagnostic for users who can edit). Only `publish` output is fragment-cached. |
| D5 | 2026-11-10 | 1.0 frontend paging is link-based with a per-instance query argument and works without JS. JS load-more is 1.1 (R1.1-06). Consequence: 1.0 has no unauthenticated `swg/v1` route. |
| D6 | 2026-11-10 | Media Overview = permission-filtered attachment browser (search, paging) showing "used in n galleries"; only action is "add to gallery". No metadata editing or deletion there. |
| D7 | 2026-11-10 | 1.0 extension points are PHP registries for layouts and import providers plus documented hooks. An admin side-panel registry is deferred to 1.1 and documented as not implemented. |
| D8 | 2026-11-10 | GitHub self-update and signed releases would follow the sw-backupit mechanism, copied self-contained into this repository and isolated in one removable module. **Status 2026-11-14:** not part of 1.0 (task M5-17 was removed from the plan); releases are plain GitHub releases with a SHA-256 file (D9). Revisit only on an explicit decision, including the signing key. |
| D9 | 2026-11-10 | 1.0 is distributed via GitHub releases only. Code stays wordpress.org-compatible (no forbidden patterns outside the updater module); wordpress.org is a later, separate decision. |
| D12 | 2026-11-10 | 1.0 does not export galleries; the limitation (WXR/migration tools move galleries without their images, attachment IDs change) is documented in README and System Status. `wp swg export/import` with ID remapping ships with R1.2-02. |
| D13 | 2026-11-10 | Galleries are embed-only in 1.0 (no own URL). Own URLs/SEO are decided again before R1.1-05. |
| D14 | 2026-11-10 | The admin app follows the visual language of the other sw-* plugins (sw-backupit, sw-cloudcalendar, sw-forms): **identical colour palette** (indigo primary `#4f46e5`, teal accent, same success/warning/danger sets, light and dark), Inter + JetBrains Mono, rail layout with brand block, collapse and dark-mode toggle, same UI building blocks. Taken over as self-contained copies with the `swg-` prefix, no shared runtime package. Implemented in Wave 3a (M2-39). |
| D10 | 2026-11-10 | Masonry markup is row-ordered in editor order (grid-like without JS); a small JS enhancement packs columns without changing DOM order. |
| D11 | 2026-11-10 | Users may add any image attachment they can read in the Media Library to galleries they may edit; changing global attachment metadata still requires `edit_post` on the attachment. |
| F-01 | 2026-11-10 | No walking-skeleton milestone; implementation order M1 → M5 (S§14.4) stays. |
| F-02 | 2026-11-10 | The Playwright/Docker E2E environment is built directly after the admin page registrar (M2-15), not at the end of M2. |
| F-03 | 2026-11-10 | Preview route and editor live preview are implemented in M3 together with the renderer; no placeholder route. |
| F-09 | 2026-11-10 | `swg_gallery` does not support WordPress post revisions (they would not contain items, order or settings). Concurrency uses `_swg_revision` only. |
| F-10 | 2026-11-10 | Gallery description is plain text with line breaks (`sanitize_textarea_field`, rendered with `esc_html` + `nl2br`). |

Decisions taken while implementing Wave 1 (2026-11-10):

| Topic | Decision |
|---|---|
| Item settings schema | Layouts describe per-item settings under the key `x-item-settings` inside `getSettingsSchema()`; a layout without it accepts any item settings and stores them untouched. Responsive values use `"x-responsive": true` (plain value or object keyed `base`, `sm`, `md`, `lg`, `xl`). |
| Authorization | Application services take an `Actor` and enforce `GalleryAccessPolicyInterface` themselves (REST is never the only gate). Unreadable attachments are reported as `missing`, so their existence does not leak. |
| Gallery service rules | Creating/duplicating yields revision 1; `create` forces the owner to the actor; changing the owner needs `canEdit` and `canDelete`; permanent delete does not require the trash first; settings are validated only when changed. |
| Transactions | Every write claims the revision first inside one transaction. On engines without transactions the runner just executes the work: a half-failed write still moves the revision, so stale clients reload. |
| Media Library provider | Needs `upload_files` to list and `edit_post` per attachment (matches the Media Library grid); missing, malformed and unreadable ids all answer `swg_import_not_found`. |

Decisions taken while implementing Wave 2 (2026-11-10):

| Topic | Decision |
|---|---|
| Capability mapping (D1) | Evaluated for the passed-in `Actor` with `user_can()` against the stored post (never the request user, never a forged entity). `canCreate` = `create_posts`; `canEdit` = `edit_post`; `canDelete` (trash, restore, delete) = `delete_post`; `canView` = `edit_posts` and `read_post`. Anonymous gets `false` everywhere except `embedAccess` of published galleries. |
| Publishing | `WpGalleryAccessPolicy::canPublish` (= `canEdit` and `publish_post`) is not part of the core interface: the REST layer must call it whenever a request sets status `publish`. |
| Attachments (D11) | `canUseAttachment` = exists, not trashed, allowed MIME type, `upload_files` and `read_post`; `canEditAttachment` = `edit_post` on an attachment only. |
| Allowed MIME types | JPEG, PNG, GIF always; WebP/AVIF only when the server image editor supports them; SVG and HEIC never (`AllowedMimeTypes`). |
| Cover | `_thumbnail_id`, written as direct meta. Deleting the attachment clears it everywhere (also in trashed galleries) and bumps the revision of the affected galleries; rendering falls back to the first item. |
| Status mapping | Post status; `Future` is rejected on create (galleries have no schedule); unknown statuses read as draft; updating a trashed gallery throws; `restore()` always yields a draft; with `EMPTY_TRASH_DAYS=0` the trash state is set by hand so galleries stay restorable. |
| Search order | Modified date descending, then id descending (the contract defines none). |
| Transactions | Nesting uses a process-wide depth: an inner failure swallowed by outer code would still commit the outer work. |
| Event bridge | A throwing callback is logged and ignored (remaining callbacks of that hook are skipped); a failing veto filter fails open. |
| Uninstall opt-in | Per site (multisite): each site opts in for itself. A network-wide opt-in would be a new decision. Deleting a site drops its item table. |
| Migrations | `Schema::migrate()` forces all steps (activation/repair); `maybe_migrate()` only runs when behind, guarded by a 60 s lock option. |

Decisions taken while implementing Wave 3a (2026-11-11):

| Topic | Decision |
|---|---|
| Brand | Rail brand block: icon `r_photo_library`, name "Gallery" ("Galerie" in German), version; changeable without migration. |
| Dark mode | Light/dark with the siblings' tokens; defaults to the system preference; the manual choice is stored per viewer in `localStorage` (`swg-theme`, `swg-rail-collapsed`, no secrets, the app works without storage). |
| `<body>` class | Quasar's dark plugin swaps `body--dark`/`body--light` on `<body>`. Nothing outside the app targets these classes; E2E verifies that wp-admin chrome styles and html classes are unchanged. |
| Portals | Quasar portal containers on `<body>` carry `swg-root swg-portal`; `.swg-portal` removes their minimum height, margin and background so they never take part in the page layout (found in review: they stretched the WordPress page by one empty screen each and painted navy in dark mode). Guarded by an E2E test. |
| Strict templates | `strictTemplates` stays `true`. Fallthrough attributes of Quasar components (`aria-*`, `data-*`, `role`, `id`, `form`, `autocomplete`, `onKeyup`) are allowed by a scoped augmentation of Vue's `AllowedComponentProps` in `env.d.ts`; everything else stays strictly checked. |
| Fonts and cache busting | Inter and JetBrains Mono ship as content-hashed woff2 files referenced from the CSS (no `ver` parameter); the entry script and stylesheet keep `ver=<plugin version>` from PHP. |
| Content area | The PHP page wraps the app in `<div class="wrap swg-wrap">`; `.swg-wrap.wrap` removes WordPress' 10px/20px content margin and `.swg-fullbleed` (negative left margin, `width: auto`) removes the 20px left padding, so the app is flush with admin bar, admin menu and right edge (E2E-measured at 1760/1280/700 px). The WordPress footer is left untouched; the sibling plugins hide it (open question). |
| Navigation | Data-driven: sections may require a capability (settings and status need `manage_options`); without `edit_posts` the app shows "no access". The server enforces permissions, the list is a UX hint. |

Decisions taken while implementing the REST gallery routes (2026-11-11, details in `docs/rest-api.md`):

| Topic | Decision |
|---|---|
| List visibility | Only users with `edit_others_posts` and `read_private_posts` see all owners and may use `owner`; everyone else sees only their own galleries (the owner filter is forced to the caller). Trash listing needs `delete_posts` (and `delete_others_posts` for foreign trashed galleries). |
| Existence leak | Every `{id}` route answers an identical 404 for missing and not-viewable galleries; 403 only when the user may view but not act. Viewable = `edit_posts` and `read_post`. |
| Publish rule | Status `publish` and `private` need the publish right (as core REST does for `private`); failure is 403 `swg_cannot_publish`. PATCH uses `canPublish`; create checks `publish_posts` in the controller (FU-10). |
| DELETE | Trashes by default (`{id, trashed, status, revision}`); `?force=true` deletes permanently (`{id, deleted}`); attachments are never touched; `expectedRevision` as query or body parameter. |
| Status codes | 400 schema violation; 422 semantic errors (invalid cover/owner/layout/settings); 409 stale revision or invalid state (also PATCH/duplicate of a trashed gallery, restoring a live one); 401 anonymous; 500 generic `swg_internal_error` (logged with the exception class only). |
| Misc | Empty PATCH is 400 `swg_no_changes`; PATCH `settings` replaces the whole values map; a page past the end is an empty 200 with correct totals; hand-written args need an explicit `validate_callback` for WordPress to enforce bounds. The public namespace index (`/wp-json/swg/v1`) lists route names and schemas only; every route handler refuses anonymous requests (tested). |

Decisions taken while implementing Wave 3b (2026-11-11; REST details in `docs/rest-api.md`, rendering in `docs/rendering.md`):

| Topic | Decision |
|---|---|
| Item PATCH | Absent override fields keep their stored value (controller merges, the core service replaces all three); `null` resets to inherit, `""` stores a blank; `settings` replaces the item's map (validated against `x-item-settings`); setting both writes settings first, the revision rises once per applied part. Texts are plain text (title 200, caption 5000, alt 500 characters). |
| Items list | Unusable attachments stay listed with `missing: true` (never deleted by a read). Bounds: assign/bulk-remove 500, reorder 10000 (proposal, to be measured in M5-01). `X-SWG-Revision` header gives the revision of the snapshot. Required payload arguments are validated by the handler, not marked `required`, so anonymous requests stay 401 (WordPress validates arguments before the permission callback). |
| Settings | One non-autoloaded option `swg_settings` (versioned JSON, unknown keys preserved); keys `defaultLayout`, `itemsPerPage` (1..200, default 24), `lightbox`; `deleteDataOnUninstall` maps to the per-site option and turning it ON needs `confirmDeleteData: true` (422 `swg_confirmation_required`). PATCH is partial, last-write-wins (no revision). |
| Media list | Visibility is a SQL predicate equal to `canUseAttachment` (own uploads, unattached uploads, attachments of published parents; private parents need `read_private_posts`); `usedInGalleries` counts live galleries with one grouped query; uploads are checked before anything is written (type from bytes, size, decodability) and never echo WordPress error text. |
| Status | Bounded: item rows counted exactly up to 50000 then `capped`; orphan scan read-only, at most 1 s, `complete: false`/`count: null` when skipped; recent errors capped at 20; cache state `none`; `adminBuild` boolean. No paths, hosts or secrets. |
| Rendering | One entry `Services::gallery_renderer()->render($galleryId, EntryPoint, $options)`. Sort query: one JOIN items x posts, allowlisted ORDER BY, ties by item id; unusable attachments skipped and not counted, relations never deleted. Eager rule: page 1 and interactive entry points only, first row eager (max 6), `fetchpriority=high` only on the first image of the first embed per request. Lightbox size: largest same-ratio size up to 2560 px; thumbnails: smallest same-ratio registered size >= display width, srcset up to 2048 px, core sizes only. Diagnostics (editor-only, never in feeds): gallery_not_found, gallery_not_public, unknown_layout, invalid_override, empty_gallery, images_unavailable. Feed markup is plain `<ul>` without class/data/style/srcset. |
| Gallery list UI | Search debounced 300 ms, page size 20; URL query `page`, `search`, `status` (deep-linkable); "Drafts" = `draft,pending`, "All" omits `status`; the edit sheet sends changed fields only; 409 keeps the input, shows a warning, fetches the fresh revision and reloads (a retry then overwrites); 404 and `swg_invalid_state` close the dialog and reload; toasts are hidden from assistive technology (one announcement via the live region); dark-mode primary buttons use the page colour as text for contrast (white on `#818cf8` fails 3:1). No edit-images/preview/open actions until those features exist. |

Decisions taken while implementing the frontend surfaces (Wave 4, 2026-11-12; details in `docs/rendering.md`):

| Topic | Decision |
|---|---|
| Layout CSS | `assets/frontend/src/layouts.css`, static, driven by custom properties; breakpoints sm 576, md 768, lg 1024, xl 1280 (min-width), reference width 1200 (the PHP image hints match, checked by a test); an unset breakpoint inherits the next smaller one. Grid gets an optional `aspect` setting (`auto` default, `1:1`, `4:3`, `3:2`, `16:9`). Public theming properties: `--swg-columns*`, `--swg-gap`, `--swg-aspect`, `--swg-radius` (4px), `--swg-caption-color`, `--swg-caption-size`, `--swg-focus-ring`, `--swg-hover-transform`, `--swg-pagination-gap`. One merged manifest: `lightbox/src/main.ts`, `style.css`, `frontend/src/layouts.css`. |
| Pagination | Real links, arg `swg_page_<galleryId>` (digits only, capped at 100000), other query args preserved, page 1 drops the arg, window of the current page ±2 with ellipses, `rel=prev/next`, `aria-current`; feed/preview only show "Page n of m". `per_page` 1..200, default from the plugin setting `itemsPerPage`. The request arg wins over an explicit `page` attribute only when the embed does not set one (shared per gallery id on a page). |
| Render cache | Object cache group `swg_render`, TTL 12 h, works without a persistent cache. Key: gallery id + revision, `public` visibility class, entry point, page, per_page, overrides, eager/priority flags, plugin settings, plugin version, locale, RTL, home_url, media epoch. Only published galleries without password for users who cannot edit them; never previews, drafts, private galleries, password forms or editor output. The per-request instance id is a placeholder filled on every hit. Out-of-range pages are cached under the clamped last page only. |
| Invalidation | Revision in the key covers gallery/item/settings changes; the media epoch (option `swg_media_epoch`, random token, at most 2 writes per request) covers attachment edits, deletion/trash, regenerated sub-sizes, alt text and file changes. Public hook `swg_gallery_changed( int $gallery_id )` fires once per core gallery/item event for full-page caches/CDNs. |
| Shortcode / PHP API | Allowlist `id` (required, `^[1-9]\d{0,9}$`), `layout` (registered), `columns` 1..12, `gap` 0..64, `page` >= 1; invalid values dropped silently, unknown attributes ignored, raw values never echoed; missing id: nothing for visitors, an escaped note for users with `edit_posts`. `swg_render_gallery( int $gallery_id, array $args ): string` returns (never echoes); same keys plus `eager`. In feeds the shortcode uses the Feed entry point. |
| Frontend assets | Handles registered on `wp_enqueue_scripts`; only pages where a gallery was rendered load anything; styles go to `<head>` when the queried post contains the shortcode/our block, otherwise late in the footer (documented flash-of-unstyled trade-off, F-17); lightbox script is classic, `defer`, footer, only with the `lightbox` setting; every asset carries `SWGALLERY_VERSION`; hostile or missing manifests enqueue nothing (editors get a footer note without paths). |
| Preview | `previewAccess` on the policy: users who can edit the gallery see any non-trashed status ignoring a password; everybody else nothing; public entry points keep `embedAccess`. `POST /galleries/{id}/preview` (never persisted, never cached, `Cache-Control: no-store`) returns `{html, layoutId, page, totalPages, totalItems, diagnostics[], assets{css[],js[]}}`. |
| Admin bar | One "Edit gallery" node per distinct gallery rendered on the request (max 5), frontend only, only for users who may edit it, linking to `admin.php?page=swg-galleries#/galleries/<id>`. |
| Publish on create | `WpGalleryAccessPolicy::canPublishNew`; `create` uses the plugin setting `defaultLayout` when the request names no layout. |

Decisions taken while implementing the editor and the block (Wave 4, 2026-11-13):

| Topic | Decision |
|---|---|
| Staged editing | Removals, order, text overrides, cover, status, layout, sort mode and settings are staged and applied by Save; adding images (picker, upload) is applied immediately because the server assigns item ids (undo = remove). Undo/redo covers staged changes, Save clears the history, "Discard" is undoable. |
| Save order | Remove (batches of 500) → text overrides (one PATCH per item, changed fields only) → PUT order with the complete list → one gallery PATCH (complete merged `settings` map); publishing is last. Every returned revision becomes the next `expectedRevision`. A failing step stops the pipeline, reports what was saved and what not, keeps the rest staged and re-syncs; a revision mismatch opens the conflict dialog. |
| Conflicts | Staged details and texts always win and differing server changes are listed first; removals of vanished items are moot, texts of vanished items are dropped and counted; the staged order survives only if nobody else moved items (others' additions are appended), otherwise it is dropped with a notice; "Discard" takes the server state; re-apply only stages (the user saves again). 403 and 404 on load show identical not-found wording. |
| Items and upload | All pages of 100 are loaded; above the 10,000 reorder limit the first 10,000 load and reordering is off with a notice. Upload: two files at a time over XHR with real progress, per-file cancel and retry, client type/size checks are hints only (`image/jpeg\|png\|gif\|webp\|avif`, 64 MB), all successful uploads are assigned in one call. The picker (native `wp.media`) is only enqueued for users with `upload_files` on the plugin screen. |
| Reorder | Native HTML5 drag and drop without a dependency (sortablejs rejected: it mutates the DOM and fights the windowed list and `aria-activedescendant`); keyboard (Ctrl/Cmd+arrows/Home/End) and toolbar buttons are the accessible alternative. |
| Admin app chunking | PHP loads the entry as `app-<hash>.js?ver=<plugin version>`; a lazy chunk importing the entry under its unversioned URL makes the browser evaluate it twice (second Vue, remount, reset to Overview). The build therefore puts shared code into `vendor`/`core` chunks, `scripts/check-chunks.mjs` fails the build when any chunk imports the entry, and `bootstrap()` is idempotent. Applies to every future lazy chunk. |
| Block | Attributes `galleryId`, optional `layout`, `columns` (1..12), `gap` (0..64), `eager`, `anchor`; no media ids, `save` returns null; validated server-side (`BlockAttributes`), invalid values dropped silently; rendered through the collector-aware `Services::embed_renderer()`; the wrapper carries `get_block_wrapper_attributes` (alignment/spacing) and prints the `anchor` id itself. Editor script `swg-block-editor` (explicit dependencies, `SWGALLERY_VERSION`), minimal UI (selector, server-side preview, four override controls, links to the Vue editor/list). |
| Block build and translations | Own npm project `assets/blocks` (Vite library mode, one classic IIFE, `@wordpress/*` from `wp.*` globals, stable `build/index.js`); translation chain in plain Node (`assets/blocks/scripts/i18n.mjs`): strings from `src/**` and `block.json` merge into the `.pot`, a JSON catalog `languages/sw-gallery-de_DE-<md5 of build/index.js>.json` is generated from the `.po`; `bin/i18n.sh check` fails on missing/fuzzy translations and stale JSON. |
| Surfaces | Shortcode, PHP API, block and renderer produce identical HTML (modulo the per-request instance id) and the same visibility; each reports to the asset collector (tested in `SurfacesTest`). The visitor's `swg_page_<id>` request argument wins over a `page` attribute (the attribute only sets the initial page). |

Defaults chosen during planning as low-risk details (AGENTS.md "Unsicherheit und Entscheidungen"); revisable without migration:

| Topic | Default |
|---|---|
| Cover | Any readable image attachment, not necessarily a member; if missing/deleted the first item is used and the stale reference is cleared by the attachment-deletion hook. |
| Media types | Raster images WordPress can create sub-sizes for (JPEG, PNG, GIF, WebP, AVIF where supported by the server). SVG and HEIC are excluded in 1.0. |
| Image sizes | Only core/theme-registered sizes; no plugin-registered sizes. Cropping is done with CSS (`object-fit`). |
| Settings forward compatibility | Unknown keys from newer versions are preserved on save and ignored when rendering. |
| Logging | Structured events go to `error_log` when `WP_DEBUG_LOG` is on, plus a bounded ring buffer (last 50 errors, non-autoloaded option) shown on System Status. |
| Browser support | One shared `browserslist` (last 2 versions of evergreen browsers, Safari ≥ 16) for admin and frontend builds. |

## Wave 5 decisions

| Topic | Decision |
|---|---|
| Layout contract | `GalleryLayoutInterface::API_VERSION = 1`, passed as 2nd argument of `swg_register_layouts`; registration validates the id pattern and a non-empty schema; a duplicate never replaces a built-in. |
| Third-party code | Registries handed to listeners are guarded (reject + log, never throw). A layout failing in schema merge, `imageHints()` or `render()` falls back to `grid` (`swg_layout_failed`, editor-only diagnostic `layout_failed`). No render-argument filter in 1.0 (it would bypass `EmbedArguments`). |
| Shortcode wrapper | `<div class="swg-shortcode is-layout-constrained">`: the gallery follows the content column of block themes, inert in classic themes; the one documented difference between surfaces. |
| Media Library | Count in details/column (one grouped query), links only to galleries the user may edit; confirm script only on `upload.php` and the attachment screen. FU-02/D11: the import provider lists every readable image. |
| `swg_gallery_changed` | Also fired from `RevisionBump` (attachment deletion, cover cleared, repair of covers), once per affected gallery. |
| Masonry / justified | Masonry = grid + CSS row spans measured per item, DOM order untouched, fallback plain grid; justified = ratio-driven flex rows with zero-width filler, CSS only. Extra assets via `LayoutAssetsProviderInterface`. No author `aspect-ratio` unless a fixed aspect is set. |
| Admin pages | Media nav capability `upload_files`; add-to-gallery re-reads the revision and `canEdit` first; Settings typed confirmation word (EN `DELETE`, DE `LÖSCHEN`); Status summary is fixed English from API fields only; Overview totals from `X-WP-Total`. |
| Live preview | Opens from a "Preview" button in a modal covering the full viewport (no tabs, no side-by-side mode). The pane exists only while the dialog is open: no request while closed, cancelled on close, a fresh sandboxed `srcdoc` iframe per open (sandbox, CSP with nonce, exact asset allowlist, token-checked height messages unchanged). Re-render on staged layout/sort/settings and on revision change; staged order/removals are not previewed (stated in the UI). Debounce 400 ms, min. 800 ms between requests. Settings stay behind the modal (hint "close to change settings"), the form is not duplicated. |
| Admin shell | App shell is `div role="region"` (WordPress owns `main`); fragile chunking: the shell must not import modules shared with lazy views through the entry (`check-chunks` guards it). |
| Editor width | The editor route sets `meta.fullWidth`; `ShellLayout` drops the 1320 px cap for it only (side panel 360 px). |
| Masonry corner radius | Setting `radius` (0..64 px, default 0) emits `--swg-thumb-radius`; grid and justified keep `--swg-radius` (4 px). |
| Portal z-index | Quasar names portal containers `q-portal--<type>--<n>`, so the old `.q-portal--dialog` rule never matched; dialogs now use `.swg-root .q-dialog { z-index: 100100 }` (above the WP admin menu and bar). |
| E2E retries | Two retries: Chromium aborts requests with `net::ERR_NETWORK_CHANGED` on host network changes, leaving the app unmounted; WordPress core's Gravatar requests are ignored by the failed-request monitor. |

## Wave 6 decisions

| Topic | Decision |
|---|---|
| Version source | The plugin header `Version:` is the single source; `bin/version.sh check|set` keeps `SWGALLERY_VERSION`, the npm packages and the CHANGELOG in sync, drift fails CI (`composer check`, `VersionScriptTest`). |
| Release ZIP | `bin/build-zip.sh` is reproducible (same commit, same sha256), ships no `docs`/`bin`/tests, no vendor prefixing (no runtime dependencies; the script fails if one appears). `bin/verify-zip.sh` plus an install smoke test (`bin/test-zip-install.sh`) gate it. The block catalog name is md5 of the path string. |
| Release workflows | `release.yml` on tags `v?X.Y.Z[-suffix]` reuses the CI workflows via `workflow_call`, publishes with `GITHUB_TOKEN` (pre-release for suffixed tags); nothing is signed (hook marked). `security-audit.yml` daily: shipped dependencies block, dev tooling and e2e are report-only. Action pinning stays on full tags. Supported matrix PHP 8.2-8.4 × WP 6.4/6.9/latest (+ trunk experimental, MariaDB/MySQL variants), defined for CI, not run locally. |
| Reorder | A CASE-batched UPDATE of the rows that change (chunks of 1000) in the existing transaction; no move command needed (10k items: 21 queries, ~250 ms). Supersedes the M2-07 wording. |
| Supported maximum | 10 000 items per gallery (measured; `PUT /order` refuses more with 400). Numbers in `docs/performance.md`. The `performance` test group stays in the default run (~10 s). Snapshots update with `UPDATE_SNAPSHOTS=1`, refused when `CI` is set. |
| Downgrade policy | A stored schema version newer than the code: no DDL, writes refused (503 `swg_schema_newer`, `SchemaNewerException`), reads and rendering keep working, System Status reports `newerThanCode`. |
| Uninstall | Opt-in deletion reads galleries straight from the posts table (all statuses incl. trash and private, also under WP-CLI). |
| Admin session | Nonce replay once per request with a shared refresh; 401 shows a banner and keeps the views mounted so staged editor state survives. Heartbeat nonces are applied. |
| i18n/RTL | One formatter (`utils/format.ts`, site timezone, WP locale); Quasar `noHtmlAttrs` plus an explicit `direction` on the root and portals. |
| Editor | Tile size S/M/L (110/150/240 px, per viewer `swg-tile-size`); deep link route `#/galleries/%d` is a documented contract shared by PHP and router; `x-enum-titles` for enum labels; preview diagnostics translated by code only. |

