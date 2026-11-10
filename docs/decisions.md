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
| D10 | 2026-11-10 | Masonry markup is row-ordered in editor order (grid-like without JS); a small JS enhancement packs columns without changing DOM order. |
| D11 | 2026-11-10 | Users may add any image attachment they can read in the Media Library to galleries they may edit; changing global attachment metadata still requires `edit_post` on the attachment. |
| F-01 | 2026-11-10 | No walking-skeleton milestone; implementation order M1 → M5 (S§14.4) stays. |
| F-02 | 2026-11-10 | The Playwright/Docker E2E environment is built directly after the admin page registrar (M2-15), not at the end of M2. |
| F-03 | 2026-11-10 | Preview route and editor live preview are implemented in M3 together with the renderer; no placeholder route. |
| F-09 | 2026-11-10 | `swg_gallery` does not support WordPress post revisions (they would not contain items, order or settings). Concurrency uses `_swg_revision` only. |
| F-10 | 2026-11-10 | Gallery description is plain text with line breaks (`sanitize_textarea_field`, rendered with `esc_html` + `nl2br`). |

Defaults chosen during planning as low-risk details (AGENTS.md "Unsicherheit und Entscheidungen"); revisable without migration:

| Topic | Default |
|---|---|
| Cover | Any readable image attachment, not necessarily a member; if missing/deleted the first item is used and the stale reference is cleared by the attachment-deletion hook. |
| Media types | Raster images WordPress can create sub-sizes for (JPEG, PNG, GIF, WebP, AVIF where supported by the server). SVG and HEIC are excluded in 1.0. |
| Image sizes | Only core/theme-registered sizes; no plugin-registered sizes. Cropping is done with CSS (`object-fit`). |
| Settings forward compatibility | Unknown keys from newer versions are preserved on save and ignored when rendering. |
| Logging | Structured events go to `error_log` when `WP_DEBUG_LOG` is on, plus a bounded ring buffer (last 50 errors, non-autoloaded option) shown on System Status. |
| Browser support | One shared `browserslist` (last 2 versions of evergreen browsers, Safari ≥ 16) for admin and frontend builds. |
