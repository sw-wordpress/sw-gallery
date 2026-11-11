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
| D8 | 2026-11-10 | GitHub self-update and signed releases follow the sw-backupit mechanism, copied self-contained into this repository and isolated in one removable module. |
| D9 | 2026-11-10 | 1.0 is distributed via GitHub releases only. Code stays wordpress.org-compatible (no forbidden patterns outside the updater module); wordpress.org is a later, separate decision. |
| D12 | 2026-11-10 | 1.0 does not export galleries; the limitation (WXR/migration tools move galleries without their images, attachment IDs change) is documented in README and System Status. `wp swg export/import` with ID remapping ships with R1.2-02. |
| D13 | 2026-11-10 | Galleries are embed-only in 1.0 (no own URL). Own URLs/SEO are decided again before R1.1-05. |
| D14 | 2026-11-10 | The admin app follows the visual language of the other sw-* plugins (sw-backupit, sw-cloudcalendar, sw-forms): **identical colour palette** (indigo primary `#4f46e5`, teal accent, same success/warning/danger sets, light and dark), Inter + JetBrains Mono, rail layout with brand block, collapse and dark-mode toggle, same UI building blocks. Taken over as self-contained copies with the `swg-` prefix, no shared runtime package. First task of Wave 3a (M2-39); planned, not implemented yet. |
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

Defaults chosen during planning as low-risk details (AGENTS.md "Unsicherheit und Entscheidungen"); revisable without migration:

| Topic | Default |
|---|---|
| Cover | Any readable image attachment, not necessarily a member; if missing/deleted the first item is used and the stale reference is cleared by the attachment-deletion hook. |
| Media types | Raster images WordPress can create sub-sizes for (JPEG, PNG, GIF, WebP, AVIF where supported by the server). SVG and HEIC are excluded in 1.0. |
| Image sizes | Only core/theme-registered sizes; no plugin-registered sizes. Cropping is done with CSS (`object-fit`). |
| Settings forward compatibility | Unknown keys from newer versions are preserved on save and ignored when rendering. |
| Logging | Structured events go to `error_log` when `WP_DEBUG_LOG` is on, plus a bounded ring buffer (last 50 errors, non-autoloaded option) shown on System Status. |
| Browser support | One shared `browserslist` (last 2 versions of evergreen browsers, Safari ≥ 16) for admin and frontend builds. |
