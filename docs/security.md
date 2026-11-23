# Security review (M5-04, M2-13, M2-16)

Independent adversarial review of sw-gallery 0.1.0 by a reviewer who did not write the code. Rules: `CODE-STYLE.md` §4-§6, §9,
`SPECIFICATION.md` §10. Reports: `SECURITY.md`. Every verdict below names the test that keeps it true.

## Threat model

| | |
|---|---|
| Assets | Draft/pending/private galleries and their texts; attachments (never modified or deleted by the plugin); the WordPress session and REST nonce; site integrity (stored markup, files in uploads); availability (database size, memory). |
| Actors | Anonymous visitor; subscriber; contributor; author (own and foreign objects); editor; administrator; multisite super admin; an authenticated user's browser abused by another site (CSRF); a third-party plugin or theme (trusted PHP, see below). |
| Trust boundaries | HTTP request → REST argument schema → coarse capability check → access policy / core services (object level) → repositories (prepared SQL) → renderer (escaping at the sink) → browser. Uploads: multipart → type sniffing → `wp_handle_upload`. Admin app: server-rendered bootstrap → Vue app (no markup sinks) → REST. |
| Out of scope | Server hardening, a compromised administrator account, a malicious plugin/theme/mu-plugin (PHP runs with full rights). |

## Known limitations (state in the README)

- Private, draft and password-protected galleries hide the gallery **markup**, not the upload URLs: anybody who knows an image URL can fetch it.
- No right-click, hotlink or download protection.
- No telemetry and no outbound requests of any kind (enforced by `DangerousCodeScanTest`).
- EXIF/GPS data stays inside the served files (WordPress core behaviour).
- Registered layouts (`swg_register_layouts`) and import providers are **trusted PHP code**. The plugin contains their exceptions
  (grid fallback, logged with the class only) and never calls them for a gallery the visitor may not see, but it does **not**
  sanitise or sandbox the markup a layout returns. Layout authors must escape their output (`RegisteredLayoutTrustTest`).
- A hook listener that throws never vetoes an operation (`swg_before_gallery_*` fail open, `docs/hooks.md`); a security plugin must
  not rely on a veto filter that can fail.
- A valid image with appended script content is accepted as an image (same as WordPress core); it is harmless as long as the web
  server does not execute `.png`/`.jpg` files in uploads.

## Checklist and verdicts

| | Item | Verdict | Evidence |
|---|---|---|---|
| a | Authorization matrix over every route × method × role | OK. 20 handlers × 7 actors = 140 cases; fails when a route is added without an entry; no `__return_true`; ms: super admin = site admin results | `Security/RouteAuthorizationMatrixTest` (+ ms run), `RestSecurityTest`, `RestItemSecurityTest` |
| b | Nonce/cookie, app passwords, CSRF, method | OK. Cookie without nonce = anonymous (401); invalid nonce 403; nonce of another user refused; app password acts as the real user with the same object checks; no write through GET. WordPress core honours `?_method=` / `X-HTTP-Method-Override`: it cannot skip the nonce or any check (documented behaviour, not a finding) | `Security/AuthenticationBoundaryTest`, E2E `security-rest.spec.ts` |
| c | Object-level checks | OK. Item ids of another gallery (patch, delete, bulk, order), foreign gallery through a visible one, non-attachment ids, trashed/private/SVG/PDF attachments, cover. **Finding S-01 fixed** (items of a trashed gallery) | `InputValidationTest` |
| d | Input validation and limits | OK after **S-02**. Sizes, counts (500/10000), per_page/page caps, negative/overflow/array ids, JSON depth bombs, unknown enums, settings keys; settings maps bounded (32 KB, depth 6, 200 keys) | `InputValidationTest`, `SettingsSchemaValidatorTest` |
| e | Output escaping | OK. Hostile data forged in the database (titles, captions, alt, sub-size file names, settings used in inline `style`, layout id) through grid/masonry/justified × shortcode/block/PHP API/preview/feed: no active element, no `on*`, no `javascript:`, no CSS injection; captions through a fixed `wp_kses` allowlist; error and diagnostic texts escaped; admin notices static. Bootstrap: `</script` cannot occur (JSON slash escaping); S-03 fixed (`<!--` escaped by the hex flags) | `Security/RenderForgedDataEscapingTest`, `RenderPipelineTest::test_hostile_text_is_escaped_for_its_context`, `RenderViewTest`, `Security/AdminBootstrapSecurityTest` |
| e | JS sinks | OK. `innerHTML`, `outerHTML`, `insertAdjacentHTML`, `document.write(ln)`, `eval`, `new Function`, string timers, `javascript:` and `v-html` are lint errors in apps/admin (was only `v-html`), assets and blocks (were missing `eval`/`Function`/`javascript:`); built bundles contain no `eval`/`new Function` | `apps/admin/tests/security/sinks.test.ts` (lint probes + bundle scan), `npm run lint` |
| e | Log ring buffer | OK. Codes and keys are whitelisted patterns, strings with `/`, `\\`, `://` are `[redacted]`, control characters removed, max 50 entries, not autoloaded | `WpLoggerTest` |
| f | SQL | OK. All `$wpdb` use is prepared or built from validated ints/placeholders and `$wpdb->prefix`; `esc_like` for searches (WP_Query for galleries and media, `LIKE` in uninstall); ORDER BY is an enum; no SQL in Rendering/Admin; the one SQL builder in `Rest/MediaSearch` is prepared (placement is an architecture note, not a weakness) | `phpcs-wordpress.xml.dist` (`WordPress.DB.*` active, `PhpcsSecuritySniffsTest`), `InputValidationTest::test_sql_and_like_metacharacters_in_searches_are_data` |
| g | Dangerous functions | OK. Token scan over `packages/`, `sw-gallery.php`, `uninstall.php`: `eval`, `unserialize`, `extract`, `assert`, shell/process, `$_REQUEST`, variable includes, outbound HTTP/sockets, file writes; allowlist of 3 justified reads/opens plus the bounded verified-update HTTP transport and private-tempfile chmod + 2 autoloader includes; stale allowlist entries fail | `Unit/Security/DangerousCodeScanTest`, `PhpcsSecuritySniffsTest` |
| h | Files and URLs | OK. Type from bytes (`wp_check_filetype_and_ext`) before storing, decodable check, double extension/traversal/NUL/RTL names stored inside uploads with a non-executable name, HTML/PHP/SVG/phar/`.htaccess` refused, 60000×60000 header refused (422, no fatal), no outbound HTTP, import path guard (realpath inside uploads, no wrapper). **S-05** informational | `Security/HostileUploadTest`, `RestMediaUploadTest`, `MediaLibraryProviderTest` |
| i | Information disclosure | OK. Identical 404 for missing and hidden galleries on every object route; status/settings only `manage_options`; no paths/SQL/secrets/salts/emails in any matrix response; 500s generic and logged by class only; REST answers carry no-cache headers; the fragment cache holds only public renders (`class: public`, not for editors, drafts, passwords, previews); draft/pending/private render nothing for visitors incl. `?swg_page_x` and feeds | matrix `assertNoDisclosure`, `RestSecurityTest::test_unexpected_failures_answer_500…`, `RestStatusTest::test_the_payload_leaks_no_paths_credentials_or_hosts`, `RenderCacheTest`, E2E `security-rest.spec.ts`, `frontend.spec.ts` |
| j | Preview/iframe | OK. Cannot read what the actor cannot edit (matrix column preview, `PreviewAccessTest`); sandbox string and CSP are asserted by the admin tests (`apps/admin/tests/editor-preview/srcdoc.test.ts`, `pane.test.ts`); hostile server responses covered there | see files |
| k | Admin app | OK. No `localStorage` secrets (nonce stays in memory); the server refuses what the UI hides (matrix); styles isolated: **M2-16 done**, foreign screens load no plugin asset and no Quasar/`swg-` rule | `css-isolation.spec.ts` (foreign screens), `access.spec.ts` |
| l | Lifecycle | OK. Uninstall deletes only plugin-owned posts/table/options/transients (prefix-anchored `LIKE`, escaped), attachments never; deactivation is a no-op; multisite per site opt-in; all options non-autoloaded; hooks follow `docs/hooks.md`; layouts trusted (above) | `UninstallTest`, `RegisteredLayoutTrustTest`, `HookPolicyTest` |
| m | Dependencies | OK 2026-11-14. `composer audit` (root, tests/wp): no advisories; `npm audit --omit=dev`: 0 vulnerabilities in apps/admin, assets, assets/blocks, e2e | commands |

## Findings

| Id | Severity | Finding | Status | Test |
|---|---|---|---|---|
| S-01 | Low (integrity) | `POST/PATCH/DELETE` item routes and order changed a **trashed** gallery (PATCH of the gallery itself already answered 409). Now 409 `swg_invalid_state` in `GalleryItemService` and `GalleryItemEditService`; restore stays lossless. | Fixed | `InputValidationTest::test_item_routes_refuse_a_trashed_gallery` |
| S-02 | Medium (availability) | `settings` of gallery and item PATCH/POST kept **unknown keys** with no size, depth or key bound: any contributor could store megabytes per gallery in post meta (up to `post_max_size`). Unknown keys stay allowed (layouts that are inactive), the map is bounded: 32 KB JSON, depth 6, 200 keys per level, error 400 `rest_invalid_param`. Applies to gallery create/patch, item patch, preview. | Fixed | `InputValidationTest::test_settings_are_bounded_in_size_depth_and_key_count` |
| S-03 | Low (hardening) | The `window.swgAdmin` inline script is encoded with default JSON flags: `</script` is safe (slashes escaped) but `<!--<script>` in a value is not. Not exploitable today (locale/timezone/URLs come from validated or core sources). Fix is one line in `Admin/AdminRegistrar::enqueue()` (owned by stream N): `wp_json_encode( $this->bootstrap(), JSON_HEX_TAG | JSON_HEX_AMP )`; same for `Block/BlockRegistrar` (`window.swgBlock`) and `MediaLibraryRegistrar` (`window.swgMediaLibrary`). Then enable the commented assertion in `AdminBootstrapSecurityTest`. | **Fixed** (all three inline bootstraps use `JSON_HEX_TAG\|AMP\|APOS\|QUOT`; the assertion is enabled) | `AdminBootstrapSecurityTest` |
| S-04 | Low (product) | Decision "changing the owner needs `canEdit` and `canDelete`" lets every gallery owner (a contributor with a draft) give it to **any** existing user, including users who cannot use galleries, or dump drafts into an administrator's list. Options: (a) require `edit_others_posts` for an owner change, (b) the new owner must hold `edit_posts`, (c) keep. **Recommendation: (a)+(b).** Owner decision 2026-11-14: (a)+(b). | **Fixed**: `canChangeOwner` (`canEdit` and `edit_others_posts`) answers 403, `canOwnGalleries` (existing user with `edit_posts`, multisite: member of the site) answers 422 `swg_invalid_owner`; an unchanged `ownerId` is not a change. | `InputValidationTest::test_a_contributor_cannot_publish_and_ownership_transfer_is_characterised` |
| S-05 | Info | A file name longer than the file system allows answers a generic 500 `swg_upload_failed` (nothing stored, no path); WordPress core behaves alike. | Accepted | `HostileUploadTest::test_a_name_longer_than_the_file_system_allows…` |

## Accepted risks and notes

- Image polyglots (valid image + script bytes) and pixel bombs that the image library accepts are a WordPress core property; the
  upload runs only for users with `upload_files` (author and above) and checks decodability first.
- `attachmentIds`/`itemIds` accept a scalar (core coerces it to a one-element list); the list limits still apply.
- `GET /media` and the gallery list expose `ownerId`/`uploadedBy` user ids (no logins, no emails).
- `Rest/MediaSearch` builds a prepared SQL predicate inside the REST namespace (D11 as a database predicate). Moving it into
  `Repository/` is a refactor for stream L, not a security change.
- Cross-site request forgery: not possible with cookie authentication (nonce required); Basic/application-password requests are
  not sent by browsers automatically.

## How the permanent guards run

The [MCP security matrix](mcp-abilities.md#8-security-matrix-data-of-the-mandatory-test) covers WordPress permissions, tier gates, strict confirmation, revision conflicts, settings allowlists and audit. The adapter transport tests verify discovery across all five levels and execution at the required levels. Invalid confirmation leaves the gallery intact.

`composer test` (scan, sniff guard), `composer test:wp` and `composer test:wp:ms` (matrix, boundary, input, escaping, upload),
`npm run test:coverage` in apps/admin (lint probes, bundle scan), `bin/test-e2e.sh` (real HTTP boundary, foreign admin screens).

Verified update trust, signature/package checks and execution contexts: [architecture](GITHUB-UPDATES.md). Only the dedicated transport may issue bounded `wp_safe_remote_get`; only the update service may restrict its exclusive temp ZIP to mode 0600. Native update input is restricted to scalar GET/POST and verified against Core action nonces.
