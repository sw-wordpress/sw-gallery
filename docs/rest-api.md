# REST API `swg/v1` – gallery routes

Contract: `SPECIFICATION.md` §8, security rules `CODE-STYLE.md` §5. Code: `packages/wordpress/src/Rest/`. The admin UI does not use these routes yet. Items, order, layouts, media, settings and status routes follow in later tasks.

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

Create body: `title` (required, 1-200), `description` (<= 10000, plain text with line breaks, F-10), `status` (`draft` default, `pending`, `publish`, `private`), `layout`, `sortMode`, `settings` (object, validated against the layout schema). The owner is always the caller. PATCH accepts the same fields plus `ownerId` and `coverAttachmentId` (nullable); `settings` replaces the stored map; an empty PATCH is 400 `swg_no_changes`. Duplicate takes an optional `title` (default `"<title> (copy)"`) and yields a draft owned by the caller at revision 1; trashed galleries cannot be duplicated (409).

DELETE trashes (`{id, trashed, status: "trash", revision}`); `?force=true` deletes permanently (`{id, deleted: true}`). Attachments are never touched, trashing keeps the item relations (restore is lossless). `expectedRevision` may be a query or body parameter. Restore always yields a draft.

## List

Query: `search`, `status` (comma list of `draft,pending,publish,private,trash`), `owner`, `page` (1..100000), `per_page` (1..100, default 20). Headers `X-WP-Total`, `X-WP-TotalPages`; a page past the end is an empty 200. Newest modification first. Item counts come from one grouped query.

Visibility: only users with `edit_others_posts` and `read_private_posts` see galleries of all owners (and may use `owner`); everybody else sees only their own, the `owner` filter is forced to the caller. The trash is listed only on `status=trash`: needs `delete_posts` (403 otherwise), and `delete_others_posts` to include foreign galleries.

## Gallery object

`id`, `title`, `description`, `status`, `ownerId`, `coverAttachmentId`, `coverUrl` (WordPress medium size, `null` unless the actor may use the attachment), `layout`, `sortMode`, `settings` (values map), `passwordProtected`, `modified` (ISO 8601 UTC), `revision`, `itemCount`, and the UX hints `canEdit`, `canDelete`, `canPublish` (the server enforces). The schema is served by `OPTIONS`.

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
| 422 | well-formed but invalid | `swg_validation_failed` (`data.errors[]` with `path`, `code`, `message`), `swg_unknown_layout`, `swg_invalid_cover`, `swg_invalid_owner`, `swg_invalid_request` |
| 500 | unexpected failure; logged as `swg_rest_unexpected_error` with the exception class only | `swg_internal_error` |

## Publishing (FU-03)

`GalleryService` has no publish check. The REST layer refuses status `publish` and `private` with 403 `swg_cannot_publish` unless the user may publish: `WpGalleryAccessPolicy::canPublish` for existing galleries, `publish_posts` on create (no id yet). Contributors can therefore only keep `draft` or `pending`.

## Cover

A changed `coverAttachmentId` must pass `canUseAttachment` (D11), else 422 `swg_invalid_cover`; an unchanged one is not re-checked.
