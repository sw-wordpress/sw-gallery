# REST API `swg/v1` – gallery routes

Contract: `SPECIFICATION.md` §8, security rules `CODE-STYLE.md` §5. Code: `packages/wordpress/src/Rest/`. The admin UI uses the gallery routes (list view); the item, order, layout, media, settings and status routes are ready for the editor, the Media Overview, Settings and System Status pages.

Every route needs a logged-in user (cookie + `X-WP-Nonce`); 1.0 has no unauthenticated route (decision D5). Permission callbacks are coarse (capability), the access policy and the core services decide per object and enforce it again.

## Routes

| Method, route | Needs | Success |
|---|---|---|
| `GET /galleries` | `edit_posts` | 200 list |
| `POST /galleries` | `create_posts` | 201 + `Location` |
| `GET /galleries/{id}` | `edit_posts`, may view | 200 |
| `PATCH /galleries/{id}` | may edit; `expectedRevision` | 200 |
| `DELETE /galleries/{id}` | may delete; `expectedRevision`; `force` | 200 |
| `POST /galleries/{id}/duplicate` | may view, `create_posts` | 201 + `Location` |
| `POST /galleries/{id}/restore` | may delete; `expectedRevision` | 200 |

Create body: `title` (required, 1-200), `description` (<= 10000, plain text with line breaks, F-10), `status` (`draft` default, `pending`, `publish`, `private`), `layout`, `sortMode`, `perPage` (1..200 or `null` = site default), `hover` (`{title, caption, animation}` with `animation` one of `slide-up|slide-down|cover|fade`, missing keys = off/`fade`, `null` = site default), `settings` (object, validated against the layout schema). The owner is always the caller. PATCH accepts the same fields plus `ownerId` and `coverAttachmentId` (nullable); changing `ownerId` needs `edit_others_posts` (403) and a new owner who exists, holds `edit_posts` and (multisite) belongs to the site (422 `swg_invalid_owner`), an unchanged value is no change; `settings` replaces the stored map; an empty PATCH is 400 `swg_no_changes`. Duplicate takes an optional `title` (default `"<title> (copy)"`) and yields a draft owned by the caller at revision 1; trashed galleries cannot be duplicated (409).

DELETE trashes (`{id, trashed, status: "trash", revision}`); `?force=true` deletes permanently (`{id, deleted: true}`). Attachments are never touched, trashing keeps the item relations (restore is lossless). `expectedRevision` may be a query or body parameter. Restore always yields a draft.

## List

Query: `search`, `status` (comma list of `draft,pending,publish,private,trash`), `owner`, `page` (1..100000), `per_page` (1..100, default 20). Headers `X-WP-Total`, `X-WP-TotalPages`; a page past the end is an empty 200. Newest modification first. Item counts come from one grouped query.

Visibility: only users with `edit_others_posts` and `read_private_posts` see galleries of all owners (and may use `owner`); everybody else sees only their own, the `owner` filter is forced to the caller. The trash is listed only on `status=trash`: needs `delete_posts` (403 otherwise), and `delete_others_posts` to include foreign galleries.

## Gallery object

`id`, `title`, `description`, `status`, `ownerId`, `coverAttachmentId`, `coverUrl` (WordPress medium size, `null` unless the actor may use the attachment), `layout`, `sortMode`, `perPage` (`null` = site default), `hover` (`null` = site default), `settings` (values map), `passwordProtected`, `modified` (ISO 8601 UTC), `revision`, `itemCount`, and the UX hints `canEdit`, `canDelete`, `canPublish` (the server enforces). The schema is served by `OPTIONS`.

## Concurrency

`expectedRevision` (integer >= 0) is **required** on PATCH, DELETE and restore; create and duplicate start at revision 1. A stale value answers 409 `swg_revision_conflict` with `data.expectedRevision` and `data.currentRevision`; nothing is written. Details: `SPECIFICATION.md` §6.

## Errors

JSON `{code, message, data: {status, ...}}`, messages translated (en/de), never paths, SQL or stack traces.

| Status | When | Codes |
|---|---|---|
| 400 | malformed request, schema violation (type, bounds, enum, missing parameter) | `rest_invalid_param`, `rest_missing_callback_param`, `rest_invalid_json`, `swg_no_changes` |
| 401 | anonymous | `swg_forbidden` |
| 403 | missing right; veto | `swg_forbidden`, `swg_cannot_publish`, `swg_operation_vetoed` (`data.reason`) |
| 404 | missing **or not viewable** gallery (existence does not leak) | `swg_gallery_not_found` |
| 409 | stale revision; wrong state (trashed update/duplicate, restore of a live gallery, trashing twice) | `swg_revision_conflict`, `swg_invalid_state` |
| 503 | the stored schema is newer than this plugin version: writes are refused, reads keep working | `swg_schema_newer` |
| 422 | well-formed but invalid | `swg_validation_failed` (`data.errors[]` with `path`, `code`, `message`), `swg_unknown_layout`, `swg_invalid_cover`, `swg_invalid_owner`, `swg_invalid_request` |
| 500 | unexpected failure; logged as `swg_rest_unexpected_error` with the exception class only | `swg_internal_error` |

## Publishing (FU-03)

`GalleryService` has no publish check. The REST layer refuses status `publish` and `private` with 403 `swg_cannot_publish` unless the user may publish: `WpGalleryAccessPolicy::canPublish` for existing galleries, `publish_posts` on create (no id yet). Contributors can therefore only keep `draft` or `pending`.

## Cover

A changed `coverAttachmentId` must pass `canUseAttachment` (D11), else 422 `swg_invalid_cover`; an unchanged one is not re-checked.

Password-protected gallery details, items and duplication require edit access to the gallery. Collection rows can show the title and status with protected fields omitted or set to defaults for other users. Collection search matches protected galleries by title. An authorized duplicate preserves the password.

## Items and order

Code: `Rest/ItemController`, `ItemPresenter`; the item rows including their settings come from `WpdbGalleryItemRepository::findPageWithSettings()` (one query per page). Same conventions as the gallery routes (errors, camelCase, `expectedRevision`, identical 404 for missing and not-viewable galleries, 403 when the user may view but not edit). Coarse check `edit_posts`; the core services enforce the access policy again.

| Method, route | Body / query | Success |
|---|---|---|
| `GET /galleries/{id}/items` | `page`, `per_page` (1..100, default 50) | 200 array of items; headers `X-WP-Total`, `X-WP-TotalPages`, `X-SWG-Revision` (gallery revision of this snapshot) |
| `POST /galleries/{id}/items` | `attachmentIds` (1..500 integers >= 1, deduplicated), `expectedRevision` | 200 `{added, alreadyAssigned, missing, revision}` |
| `PATCH /galleries/{id}/items/{itemId}` | `overrides`, `settings` (at least one), `expectedRevision` | 200 `{item, revision}` |
| `DELETE /galleries/{id}/items/{itemId}` | `expectedRevision` (query or body) | 200 `{id, removed: true, revision}` |
| `POST /galleries/{id}/items/bulk-remove` | `itemIds` (1..500), `expectedRevision` | 200 `{removed, notFound, revision}` |
| `PUT /galleries/{id}/order` | `itemIds` (complete ordered list, max 10000), `expectedRevision` | 200 `{itemCount, revision}` |

Items are listed in stored manual order (display sort modes belong to the renderer, D3). Assign answers 200 even when ids were skipped: `alreadyAssigned` are already members, `missing` covers nonexistent, unsupported and unreadable attachments alike (existence does not leak, D11). When nothing was added the revision is unchanged. Removal deletes the relation only, never the attachment; unknown item ids of a bulk removal are `notFound`, and without a removal the revision is unchanged. A missing payload argument (`attachmentIds`, `itemIds`) is 400 `rest_missing_callback_param`; it is not declared `required` in the route schema because WordPress would then validate before the permission callback and answer anonymous requests with 400 instead of 401.

### Item resource

`id` (item id, **not** the attachment id), `attachmentId`, `position` (1-based), `overrides` `{title, caption, alt}` (each string or `null`: `null` = inherit, `""` = intentional blank, serialised faithfully), `effective` (the three texts after applying the overrides to the global ones), `global` (`{title, caption, alt}` of the attachment), `settings` (object, the item settings map), `media` (`{mimeType, width, height, thumbnailUrl, fullUrl}`, core `medium` size URL; no file names or paths) and `missing`.

`missing: true` when the attachment no longer exists, is trashed, is not an allowed image type, or the actor may not use it (D11; contributors lack `upload_files`, so they see their items as missing). Such rows stay listed so the admin can remove them, are never deleted by a read, and expose `global: null`, `media: null` and `effective` computed against empty global texts. Metadata of a page is fetched with a bounded number of queries (independent of the page size; tested).

### PATCH semantics

`overrides` is partial: fields that are absent keep their stored value, `null` resets that field to inherit, `""` stores a blank. Unknown keys are 400. `settings` replaces the item's map and is validated against the layout's `x-item-settings` schema (422 `swg_validation_failed`; layouts without the key store it untouched); `{}` clears it (schema defaults apply). A PATCH without `overrides` fields and without `settings` is 400 `swg_no_changes`. With both parts the settings are validated and written first, then the overrides: the revision rises once per applied part (two steps). Bounds: `title` <= 200, `caption` <= 5000, `alt` <= 500 characters (checked before sanitising).

Texts are plain text: `title` and `alt` go through `sanitize_text_field`, `caption` through `sanitize_textarea_field` (line breaks kept); markup is stripped on save (a `<script>` is never stored). Global attachment metadata is never changed. Clients must still render all texts as plain text.

### Order

`itemIds` must be an exact permutation of the gallery's item ids, else 422 `swg_invalid_order` (nothing written); a stale revision is 409. The adapter writes one `UPDATE` per item inside one transaction; a single `CASE` update is an open point for M5-01 measurements (a move command for large lists as well).

### Status codes

400 schema violation or missing payload; 401 anonymous; 403 may view but not edit (subscribers are refused by the coarse check); 404 gallery missing or not viewable (`swg_gallery_not_found`) or item not in this gallery (`swg_item_not_found`); 409 stale revision (`swg_revision_conflict`); 422 invalid settings (`swg_validation_failed`) or order (`swg_invalid_order`).
## Layouts

`GET /layouts` (`edit_posts`): `{fallbackId: "grid", layouts: [{id, label, settingsSchema}]}`. Labels are translated when presented; `settingsSchema` is the layout's schema unchanged (including `x-responsive` and `x-item-settings`), an empty `properties` stays a JSON object. The admin app builds its forms from it.

## Media

`GET /media` (`upload_files`): images only (JPEG, PNG, GIF, plus WebP/AVIF when the server supports them), paged like the gallery list (`page`, `per_page` 1..100 default **40**, `X-WP-Total*`, past the end = empty 200). Query: `search` (<= 200), `orderby` (`date` default, `title`), `order` (`desc` default, `asc`), `uploadedBy` (user id; users without `edit_others_posts` always get their own uploads, the filter cannot be used to browse others).

Visibility (D11) is a database predicate that mirrors `canUseAttachment`: own uploads, unattached uploads, attachments of published parents (private parents need `read_private_posts`); users with `edit_others_posts` and `read_private_posts` see all images. Trashed items and other types never appear. Every row is re-checked with the access policy. Cost: a constant number of queries (search + total, post meta, parents, one grouped usage count), never one per row.

Resource: `id`, `title`, `alt`, `caption`, `mimeType`, `width`, `height`, `thumbnailUrl` (medium), `fullUrl` (both `null` when WordPress knows no file), `uploadedAt` (ISO 8601 UTC), `uploadedBy` (user id), `usedInGalleries` (number of live, non-trashed galleries, global count). No file system paths.

`POST /media` (`upload_files`, multipart field `file`, optional `title` <= 200, `alt` <= 500) uploads through `wp_handle_upload` and creates attachment and sub-sizes the WordPress way; 201 with the resource. The type is checked from the bytes (`wp_check_filetype_and_ext`) against the allowed list before anything is written, and the image must be decodable by the image editor; a failure after storing removes the file again.

| Status | Code | When |
|---|---|---|
| 400 | `swg_missing_file`, `swg_upload_failed` | no/empty/malformed file; partial upload |
| 413 | `swg_file_too_large` | above `wp_max_upload_size()` or PHP size limit |
| 422 | `swg_unsupported_media_type`, `swg_invalid_image` | SVG, HEIC, PDF, scripts, wrong content; not decodable |
| 500 | `swg_upload_failed` | storage failure; the core message (may contain paths) is never passed on |

## Settings

`GET`/`PATCH /settings` (`manage_options`). One versioned JSON object in the non-autoloaded option `swg_settings` (`schemaVersion`); keys unknown to this version are preserved on save. Response: `defaultLayout` (registered layout id, default `grid`), `itemsPerPage` (1..200, default 24), `lightbox` (default true), `deleteDataOnUninstall` (default false), plus `schemaVersion`, `defaults` and `deleteDataNotice` (the plain statement that galleries and relations are removed but **never attachments**). Invalid or out-of-range stored values read as their default.

PATCH is partial; unknown request keys are ignored, a body without a known key is 400 `swg_no_changes`. **Last write wins** (settings change rarely, no `expectedRevision`). Types and bounds are schema errors (400); an unregistered `defaultLayout` is 422 `swg_unknown_layout`.

`deleteDataOnUninstall` is the existing per-site option `swg_delete_data_on_uninstall` (read by the uninstall routine). Turning it **on** needs `confirmDeleteData: true` in the same request, otherwise 422 `swg_confirmation_required` and nothing changes; repeating `true` or turning it off needs no confirmation.

When the WordPress MCP Adapter is available, the response adds `mcpAccessLevel` (configured level) and `mcpEffectiveLevel` (after configuration overrides). PATCH can change `mcpAccessLevel` to `off`, `read`, `write`, `destructive` or `admin`. The default is `off`. See [MCP abilities](mcp-abilities.md#1-for-site-owners).

## Status

`GET /status` (`manage_options`), no secrets, paths, hostnames or SQL:

- `schema`: `stored`, `current`, `migrationPending`, `newerThanCode`, `itemTablePresent`.
- `counts.galleries` per status (`draft, pending, publish, private, future, trash`); `counts.itemRows` `{count, capped}` (exact up to 50000 rows, then `capped: true`); `counts.orphanRelations` `{count, complete}`: a read-only scan with the repair command's query, only on non-capped tables and for at most one second. Otherwise `complete: false` (`count: null` when skipped); the repair itself stays WP-CLI only.
- `recentErrors`: the logger ring buffer, newest first, at most 20, already sanitized (`time` ISO UTC, `code`, `context`).
- `versions` (`plugin`, `wordpress`, `php`, `database: {type, version}`), `multisite`, `cache: {state: "none"}` (until the renderer cache exists), `adminBuild` (boolean: the build manifest is valid), `hints[]` (`{key: "export_limitation", message}`, decision D12).

## Preview

`POST /galleries/{id}/preview` (`edit_posts`; the actor must be able to edit the gallery: 404 when it is not viewable, 403 when viewable but not editable, 401 anonymous). Body, all optional and **never persisted**: `layout` (registered id, else 422 `swg_unknown_layout`), `sortMode`, `galleryPerPage` (unsaved gallery page size, nullable), `galleryHover` (unsaved hover texts, nullable), `settings` (object; replaces the stored map, validated against the layout schema, else 422 `swg_validation_failed`), `page` (>= 1), `perPage` (1..200). Malformed values are 400. Drafts, pending, private and password-protected galleries render for their editors; the trash renders nothing (`html: ""`).

Response 200 (`Cache-Control: no-store`): `{ html, layoutId, page, totalPages, totalItems, diagnostics: [{code, message}], assets: { css: [url], js: [url] } }`. `html` is the renderer's markup without the editor diagnostics (they are in `diagnostics`). `assets` are the validated build files with `?ver=<plugin version>` (empty arrays when the build is missing; the lightbox files only when the `lightbox` setting is on) for the editor's sandboxed iframe.

## Admin bootstrap (`window.swgAdmin`)

Printed inline by the plugin page (JSON with hex escaping, `JSON_HEX_TAG|AMP|APOS|QUOT`): `restRoot` and `nonce` (required, validated once by the admin app), `locale`, `version`, `adminUrl`, `capabilities[]`, `isRtl`, `timezone`, `ajaxUrl`, `loginUrl`, `assetsUrl` (optional URLs must be http(s), otherwise dropped). The only secret is the per-user REST nonce. The client replays a request once after `rest_cookie_invalid_nonce` with a fresh nonce from `admin-ajax.php?action=rest-nonce` (WordPress rejects before executing, so the replay is safe); 401 shows the session-expired banner without unmounting the views.

