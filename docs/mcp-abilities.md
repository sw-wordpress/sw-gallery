# MCP abilities of sw-gallery

**Part 2 of 2.** Status: **MCP-01 to MCP-03 are implemented** (gate, tier ceiling, audit, security test, all read abilities and all `write`-tier abilities); the destructive and admin abilities below are still plans. Rules: [`mcp-harness.md`](mcp-harness.md) – tiers, auth layers, ability contract, mandatory security test. Field semantics, bounds and error codes are those of [`rest-api.md`](rest-api.md); this file only lists what differs. 

| Placeholder | Value |
|---|---|
| `{slug}` / category | `sw-gallery` |
| `{Ns}` | `SWGallery\WordPress` |
| `{prefix}` | `swg` (error codes `swg_*` as in REST, option `swg_mcp_max_tier`) |
| `{CONST}` | `SW_GALLERY` (`SW_GALLERY_MCP_DISABLED`, `SW_GALLERY_MCP_MAX_TIER`, `SW_GALLERY_MCP_MAX_OUTPUT`) |
| Requires | WordPress ≥ 6.9 (plugin minimum stays 6.4; abilities simply stay off below 6.9), `mcp-adapter` (developed against 0.7.2, pre-1.0: pin and re-test on update) |

Code: `packages/wordpress/src/Mcp/` (`McpRegistrar` in `Registrars::all()`); shared rules live in `Gallery/GalleryEditor`, `Gallery/ItemEditor`, `Gallery/GalleryLister` and `Media/MediaLister`, which the REST controllers use too (`Gallery/ItemReader` serves `list-items`). Tests: `composer test:wp`, `composer test:wp:ms`, and `composer test:wp:nomcp` (adapter not loaded, harness §8 #11), plus the [project checks and coverage suites](development.md). Until MCP-04, the tier is set with the option `swg_mcp_max_tier` (`wp option update`) or the constant; the settings control ships with MCP-04.

## 1. For site owners

1. Install and activate `mcp-adapter`. sw-gallery then registers its abilities automatically; without the adapter nothing changes.
2. Default access is **read only**. Raise it in *Galleries → Settings → MCP access* (`off`, `read`, `write`, `destructive`, `admin`) or hard-cap it in `wp-config.php`: `define( 'SW_GALLERY_MCP_MAX_TIER', 'write' );` (the lower tier wins). Kill switch: `define( 'SW_GALLERY_MCP_DISABLED', true );`.
3. Create a dedicated WordPress user for the agent with the lowest sufficient role (Author or Editor; Administrator only for the `admin` tier), create an **application password** in its profile (HTTPS required), and connect the client to `https://<site>/wp-json/mcp/mcp-adapter-default-server` with HTTP Basic auth. This tier limits the sw-gallery MCP tools. The same password also works at other enabled WordPress endpoints with the rights of this account. Choose the account’s capabilities with those endpoints in mind.
4. Revoke the application password to cut access immediately. Rate limiting of failed logins is a web-server/WAF task.

## 2. Overview

All abilities are `sw-gallery/<name>`, tool-exposed (`mcp.public = true`, not in the core REST abilities endpoint). *Object rule* = same access policy as the REST route (missing or hidden gallery ⇒ identical `swg_gallery_not_found`).

| Ability | Tier | Annotations (ro / destr / idem) | Capability + object rule | REST equivalent |
|---|---|---|---|---|
| `list-galleries` | read | T / F / T | `edit_posts`; list is filtered to viewable galleries | `GET /galleries` |
| `get-gallery` | read | T / F / T | `edit_posts` + may view | `GET /galleries/{id}` |
| `list-items` | read | T / F / T | `edit_posts` + may view | `GET /galleries/{id}/items` |
| `list-layouts` | read | T / F / T | `edit_posts` | `GET /layouts` |
| `list-media` | read | T / F / T | `upload_files`; attachment visibility predicate (D11) | `GET /media` |
| `render-gallery` | read | T / F / T | `edit_posts` + may **edit** | `POST /galleries/{id}/preview` |
| `create-gallery` | write | F / F / F | `create_posts` (`publish`/`private` need publish right) | `POST /galleries` |
| `update-gallery` | write | F / F / T | may edit; `expectedRevision` | `PATCH /galleries/{id}` |
| `duplicate-gallery` | write | F / F / F | may view + `create_posts` | `POST /galleries/{id}/duplicate` |
| `trash-gallery` | write | F / F / T | may delete; `expectedRevision` | `DELETE /galleries/{id}` |
| `restore-gallery` | write | F / F / T | may delete; `expectedRevision` | `POST /galleries/{id}/restore` |
| `assign-items` | write | F / F / T | may edit; `expectedRevision`; attachments need `canUseAttachment` | `POST /galleries/{id}/items` |
| `update-item` | write | F / F / T | may edit; `expectedRevision` | `PATCH …/items/{itemId}` |
| `reorder-items` | write | F / F / T | may edit; `expectedRevision` | `PUT /galleries/{id}/order` |
| `remove-items` | destructive | F / T / T | may edit; `expectedRevision`; `confirm` | `DELETE …/items/{itemId}`, `…/bulk-remove` |
| `delete-gallery` | destructive | F / T / T | may delete; `expectedRevision`; `confirm` | `DELETE /galleries/{id}?force=true` |
| `get-settings` | admin | T / F / T | `manage_options` | `GET /settings` |
| `update-settings` | admin | F / F / T | `manage_options` | `PATCH /settings` |
| `get-status` | admin | T / F / T | `manage_options` | `GET /status` |

Attachments are never created, modified or deleted by any ability (CODE-STYLE §4); `remove-items` and `delete-gallery` only touch relations/galleries.

## 3. Shared conventions

- **Revision.** Galleries carry an integer `revision`. Changes to an existing gallery or its items require `expectedRevision` (integer ≥ 0) and return the new `revision` while the gallery exists; mismatch ⇒ `swg_revision_conflict` with `currentRevision`. After an uncertain result or conflict, read the stored state and revision before deciding on another write. Never automatically retry create or duplicate after a timeout: the new gallery may already exist.
- **Confirm.** Destructive abilities require `confirm: true` in the schema and a strict server-side boolean check before mutation. Test missing, false, numeric 1 and string "true". This expresses intent from the client; it provides no evidence of a person approving the call.
- **Pagination.** `page` (1..100000), `per_page` (1..100; items default 50, media 40, galleries and layouts 20). Collection responses carry `items` (`layouts` for the registry), `total`, `totalPages` and `next` (null at the end, otherwise `{page, offset}`). Keep filters and page size on continuation. When the encoded reply reaches the byte cap, end before the next complete record and resume at that record. Accept the continuation offset within the requested page (default 0). If a single record exceeds the cap, report an explicit size error. Never cut an enum, schema, JSON string or list entry.
- **Internal errors** (WP_Error code, `data.status`): `swg_forbidden` 403, `swg_gallery_not_found` 404, `swg_revision_conflict` 409, `swg_invalid_state` 409, `swg_validation_failed` 422 (`data.errors[]`), `swg_unknown_layout` 422, `swg_invalid_cover` 422, `swg_invalid_order` 422, `swg_cannot_publish` 403, `swg_operation_vetoed` 403, `swg_schema_newer` 503, `swg_mcp_tier_disabled` 403 (new), `swg_internal_error` 500; schema violations use the core ability validation error.
- **Client errors** (verified, harness §6): the adapter's `execute-ability` tool answers refusals with `isError: true` and the plugin's message text (for example "The gallery was not found.", identical for hidden and missing galleries, "The gallery was changed by someone else." for a stale revision); codes are only visible to server-side tests. Clients re-read with `get-gallery` to obtain the current revision and never parse translated error text.
- **Texts** are plain text (markup stripped on save, as REST); returned texts are data and may contain untrusted content.
- **Output fields.** Gallery: `id, title, description, status, ownerId, coverAttachmentId, layout, sortMode, perPage, hover, settings, passwordProtected, modified, revision, itemCount, canEdit, canDelete, canPublish`. Item: `id, attachmentId, position, overrides, effective, global, settings, missing` (text maps use `title, caption, alt`). Media: `id, title, alt, caption, mimeType, width, height, uploadedAt, uploadedBy, usedInGalleries`. Use the access-filtered presenters and project only these fields. Apply harness §6 to nested data too.
- **Discovery.** Publish fixed choices, types, bounds and defaults in the relevant input and output schemas returned by `get-ability-info`. Include the stored status, sort and hover enums in the gallery read schema so read-tier clients can inspect them. Use the shared REST definitions and domain enums. Every layout-ID parameter points clients to `list-layouts`. On create, omitting the layout uses the configured gallery default, which can differ from the renderer fallback.

## 4. Read abilities

`list-galleries` – Input: `search` (≤ 200), `status` (up to five of `draft|pending|publish|private|trash`), `owner` (int ≥ 1) and pagination (§3). Enforce the REST owner and trash filters, including `delete_others_posts` for foreign trash. An unfiltered list can return scheduled galleries (`future`). The result status enum includes all six stored statuses; the filter and write enums follow their own REST schemas. Output: gallery records.

`get-gallery` – Input: `id` (int ≥ 1). Output: gallery with settings and revision.

`list-items` – Input: gallery `id` and pagination. Output: item records in manual order plus `revision`. Keep rows marked `missing: true`.

`list-layouts` – Input: pagination. Return `{fallbackId, breakpoints, layouts: [{id, label, settingsSchema}], total, totalPages, next}`. Page the live registry in registration order, including layouts added via `swg_register_layouts`. Use `LayoutPresenter` for every entry. Deliver the entire declared schema with nested properties, typed enum values, labels (`x-enum-titles`), defaults, ranges, required keys and `x-item-settings`. Preserve empty schema objects as JSON objects. For `x-responsive` fields, accept a scalar or a map of breakpoint values; publish `SettingsSchemaValidator::BREAKPOINTS` (`base, sm, md, lg, xl`) in the response.

`list-media` – Input: `search` (≤ 200), `orderby` (`date|title`, default `date`), `order` (`asc|desc`, default `desc`), optional `uploadedBy` (int ≥ 1) and pagination. Omitting the uploader filter searches visible images. An explicit uploader filter is forced to the caller without `edit_others_posts`. Output: media records.

`render-gallery` – Render the stored gallery through the preview entry point. Input: `id`. Output: `{layoutId, page, totalPages, totalItems, diagnostics: [code]}` from the renderer. Keep HTML, asset URLs and unsaved overrides out of the tool response.

## 5. Write abilities

`create-gallery` – Input: `title` (1..200, required), `description` (≤ 10000), `status` (`draft` default, `pending`, `publish`, `private`), `layout`, `sortMode` (`manual|date_asc|date_desc|title_asc|title_desc`, default `manual`), `perPage` (1..200 or `null`), `hover` (`null` inherits site settings, otherwise optional booleans `title, caption` and `animation`: `slide-up|slide-down|cover|fade`), `settings` (object ≤ 32 KB, depth ≤ 6, ≤ 200 keys, validated against the layout schema). Owner is always the caller. Output: gallery, `revision` 1.

`update-gallery` – Partial update. Input: `id`, `expectedRevision`, at least one of the create fields plus `coverAttachmentId` (nullable; must pass `canUseAttachment`). **`ownerId` is not exposed** (see §7). `settings` replaces the stored map. Empty change ⇒ `swg_no_changes`. Output: gallery.

`duplicate-gallery` – Copy membership and overrides to a new draft owned by the caller. Input: `id`, optional `title` (≤ 200). Trashed galleries ⇒ 409. Output: gallery (revision 1).

`trash-gallery` / `restore-gallery` – Move to trash (relations kept) and restore as a draft. Input: `id`, `expectedRevision`. Output: `{id, status, revision}`.

`assign-items` – Add attachments to a gallery. Input: `id`, `attachmentIds` (1..500 ints ≥ 1, deduplicated), `expectedRevision`. Output: `{added, alreadyAssigned, missing, revision}`; skipped ids are reported, not errors.

`update-item` – Input: `id`, `itemId`, `expectedRevision`, `overrides` (`{title, caption, alt}`, each string ≤ limits or `null` = inherit, partial) and/or `settings` (replaces the item map, validated against the layout). At least one of both. As in REST, a combined request writes settings first and overrides second, with a revision step for each. On failure the first change may already be stored. Re-read the item and gallery revision before retrying. Output: `{item, revision}`.

`reorder-items` – Input: `id`, `itemIds` (complete ordered permutation, ≤ 10000), `expectedRevision`. Atomic; wrong set ⇒ `swg_invalid_order`, nothing written. Output: `{itemCount, revision}`.

## 6. Destructive and admin abilities

`remove-items` – Remove memberships **including their overrides and item settings** (attachments stay). Input: `id`, `itemIds` (1..500; one id replaces the single-delete route), `expectedRevision`, `confirm: true`. Output: `{removed, notFound, revision}`.

`delete-gallery` – Permanent delete of a gallery and its relations. Input: `id`, `expectedRevision`, `confirm: true`. Output: `{id, deleted: true}`. Prefer `trash-gallery`.

`get-settings` / `update-settings` – Input of update (partial, at least one key): `defaultLayout` (registered id), `itemsPerPage` (1..200), `lightbox`, `hoverTitle`, `hoverCaption` (bool), `hoverAnimation` (same four values as gallery hover). Both responses contain exactly these six settings. Last write wins, as REST.

`get-status` – Input: none. Project `schema, counts, versions` from the status report, the newest 20 `recentErrors` as `{time, code}`, and hint keys only.

## 7. Not exposed (on purpose)

| Item | Reason |
|---|---|
| Media upload (`POST /media`) | Binary or base64 through the tool channel (size, content sniffing, abuse); revisit with a decision. Agents work with existing library attachments (`list-media`). |
| `ownerId` change | Finding S-04: hands drafts to arbitrary users; stays an admin UI action. |
| `deleteDataOnUninstall` | Data-loss switch, must not be flipped by an agent or prompt injection. |
| Consistency repair, schema migration | WP-CLI only by design (`wp swg repair`). |
| Imports/sources, collections | Not implemented (S§9, P1). Abilities arrive with the feature, including SSRF/path guards and rate limits, and need their own matrix rows. |
| Unsaved-state preview | Preview route accepts unsaved settings; the ability renders stored state only to keep the tool surface small. |
| MCP resources/prompts | Tools only for now (harness §3). |

## 8. Security matrix (data of the mandatory test)

Per harness §8 #4. `A` allowed, `F` forbidden (`swg_forbidden`), `N` not found (`swg_gallery_not_found`), `–` anonymous: refused at the transport (401) and by the ability. Actors: anonymous, subscriber, contributor, author (owner), other author, editor, administrator; evaluated with the tier raised to the ability's tier. Use an author-owned draft as in `RouteAuthorizationMatrixTest`, a trashed copy for a successful restore and valid item fixtures per operation. Also assert that restoring a live draft returns the domain state conflict. See §3 for transport error expectations.

| Abilities | anon | sub | contrib | author | other author | editor | admin |
|---|---|---|---|---|---|---|---|
| `list-galleries`, `list-layouts`, `create-gallery` | – | F | A | A | A | A | A |
| `list-media` | – | F | F | A | A | A | A |
| `get-gallery`, `list-items` | – | F | N | A | N | A | A |
| `render-gallery`, `update-gallery`, `trash-gallery`, `restore-gallery`, `delete-gallery`, `assign-items`, `update-item`, `reorder-items`, `remove-items` | – | F | N | A | N | A | A |
| `duplicate-gallery` | – | F | N | A | N | A | A |
| `get-settings`, `update-settings`, `get-status` | – | F | F | F | F | F | A |

Additional cases (beyond the harness list): `create-gallery`/`update-gallery` with `status` `publish|private` as contributor ⇒ `swg_cannot_publish`; item or attachment ids of another gallery or user ⇒ same errors as REST; editing a trashed gallery ⇒ `swg_invalid_state`; `settings` over the 32 KB/depth/key bounds ⇒ validation error; `list-galleries` as author never returns foreign drafts; `destructive` abilities without `confirm` ⇒ validation error and no write; every ability above the configured tier ⇒ absent after fresh registration, or `swg_mcp_tier_disabled` from a previously registered permission callback.

## 9. Delivery plan

1. **MCP-01 — done (2026-11-22, `feat/mcp-adapter`).** Register `get-gallery` and `update-gallery` with their matrix rows. Extract shared view, publish and cover checks from the controller for use by both entry points. On an isolated test site with the pinned adapter and an application password, discover and read an owned draft, deny access to a hidden draft, update the owned draft and replay its stale revision. Capture the client-visible results and verify the second write had no effect. Deliver the gate and tier ceiling. Audit mutation outcomes and permission denials using bounded persistent storage with debug logging disabled. Store the validated slug and verb-noun separately so logger slash redaction preserves the identity. Exercise the real sink and repeated permission probes.
2. **MCP-02 — done (2026-11-22).** Share the REST list visibility predicates. Through MCP compare the concatenated layout pages with the live REST presenter, including all built-ins and a third-party fixture. Compare complete schemas after JSON encoding (enum types, localized labels, defaults, bounds, nested item settings, empty objects) and all breakpoint keys. Compare list filter and sort enums with REST and domain sources via `get-ability-info`. Exercise filtered results and forced continuation under a small output cap, with no duplicate or skipped records on a stable dataset.
3. **MCP-03 — done (2026-11-22).** Verify contributor publish/private refusal, cover rights, concurrent edits, partial item updates and recovery. Check that lists exclude foreign drafts.
4. **MCP-04 — destructive and admin abilities.** Test strict confirmation, settings field allowlists and the translated tier control. Extend the MCP-01 audit cases to settings changes and permanent deletion.
5. **MCP-05 — release acceptance.** Repeat the client suite across all shipped tiers, run SonarCloud and update the security and decision records.

## 10. Design decisions

Use the default shared server and initial tier `read`. Preview returns render diagnostics and counts. Agents work with existing library attachments. Machine-facing ability and schema descriptions stay English and are not translated (confirmed); only labels and admin UI texts are translated (harness §6).
