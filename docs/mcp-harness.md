# MCP integration – harness rules (copy 1:1 into every sw-* plugin)

**Part 1 of 2.** Generic and plugin-independent; do not edit per plugin. Part 2 is the plugin's own `docs/mcp-abilities.md` (ability catalogue); create it from §10. Paste this file unchanged as `docs/mcp-harness.md` and add one line to `AGENTS.md`: *"MCP: read `docs/mcp-harness.md` (rules) and `docs/mcp-abilities.md` (catalogue) before touching `Mcp/`."*

Placeholders (values live in the plugin's part 2): `{slug}` (plugin slug, e.g. `sw-gallery`), `{Ns}` (PHP namespace), `{prefix}` (function/option prefix, e.g. `swg`), `{CONST}` (constant prefix, e.g. `SW_GALLERY`).

## 1. Goal and non-goals

- Expose the actions selected in part 2 to MCP clients as **WordPress Abilities** (core API since 6.9, `wp_register_ability`). The [`mcp-adapter`](https://github.com/WordPress/mcp-adapter) plugin turns abilities into MCP tools.
- Without the adapter only the two availability hooks in §2 may be installed. Register no category or ability, render no MCP UI and create no option on that path.
- **No second code path.** An ability calls the same services, access policy, presenters and renderer as REST/block/shortcode/CLI. It never re-implements rules, never calls REST internally (`rest_do_request`), never touches `$wpdb` directly.
- Non-goals: custom authentication, a custom MCP server/transport, OAuth, a dependency on adapter classes, remote import by URL (SSRF), binary upload through the tool channel.

## 2. Availability gate (hard requirement)

`{Ns}\Mcp\McpAvailability::isAvailable()` is the **only** switch, evaluated on `wp_abilities_api_categories_init` / `wp_abilities_api_init` (never at file load):

```php
function_exists( 'wp_register_ability' )                  // WP >= 6.9; plugin minimum stays lower
&& class_exists( '\\WP\\MCP\\Plugin', false )              // string only, adapter is never imported
&& ! ( defined( '{CONST}_MCP_DISABLED' ) && {CONST}_MCP_DISABLED ) // kill switch (wp-config)
&& tier() !== 'off'                                        // §4
```

`McpRegistrar` (wired through the plugin bootstrap named in part 2) adds the two hooks only when the Abilities API function exists; the gate decides inside. No filter may force it on. Adapter classes are never referenced, so the plugin loads and passes PHPStan without the adapter; the adapter is a **dev dependency of the WP test suite only**.

## 3. Exposure

- Category `{slug}` is registered once; every ability is `{slug}/{verb}-{noun}` (lowercase, dashes; verbs: `list get create update delete trash restore duplicate assign remove reorder render`).
- Exposure is **MCP-only and explicit**: `meta.mcp.public = true`, `meta.public` unset, `meta.show_in_rest = false`. The ability is then not listed in `/wp-abilities/v1` either. Adapter 0.7.2 inherits `meta.public` when `mcp.public` is absent. Test the effective exposure through discovery and execution.
- Annotations are mandatory and truthful: `readonly`, `destructive`, `idempotent` all set explicitly (no `null`).
- Abilities are **tools**. Resources and prompts only with an extra decision in `docs/decisions.md`.
- Server: the adapter's default server (`/wp-json/mcp/mcp-adapter-default-server`, meta tools `discover-abilities`, `get-ability-info`, `execute-ability`) – one client connection for all sw-* plugins. Plugins do not create or reconfigure servers and do not use `mcp_adapter_default_server_config` or the transport-permission filters (global effect on other plugins).

## 4. Tiers (blast-radius control)

Every ability has exactly one tier. The site owner sets the maximum tier; **default `read`**.

| Tier | Meaning | Annotations |
|---|---|---|
| `read` | no state change | readonly |
| `write` | additive or fully reversible (create, update, trash, restore, assign, reorder) | destructive=false |
| `destructive` | loses data that cannot be restored (permanent delete, removing relations with their overrides) | destructive=true, input requires `confirm: true` |
| `admin` | plugin-wide settings, diagnostics | by effect |

Setting: option `{prefix}_mcp_max_tier` ∈ `off|read|write|destructive|admin` (the tier control stays visible whenever the adapter is present, also at `off`, so an owner can re-enable access) and the constant `{CONST}_MCP_MAX_TIER`, as an optional ceiling. Use the lower of option and ceiling (option `read` + ceiling `write` gives `read`). Tiers are cumulative, so `admin` also enables destructive abilities for users with the required capabilities. Abilities above the tier are **not registered** and, as defence in depth, `permission_callback` re-checks the tier at call time. Settings that can cause data loss on uninstall, secrets and tokens are never writable or readable through an ability.

## 5. Authentication and authorisation

Layers, outermost first; each one alone must hold:

1. **Transport / identity (WordPress, not us).** Authentication is WordPress core's: **Application Passwords** over HTTPS (Basic auth, per user, revocable, listed in the profile). Cookie authentication without a nonce becomes anonymous. An invalid nonce returns 403. The plugin adds **no** auth code, no `determine_current_user` filter, no API-key scheme, no token storage. WP-CLI stdio (`wp mcp-adapter serve --user=…`) is local only and runs as the named user.
2. **Transport capability (adapter).** Default `read` (any logged-in user). Too coarse by design: **abilities are the authority**; nothing may rely on the transport check.
3. **Ability `permission_callback`** – always, never `__return_true`, never a constant. Order: tier → capability → object check → return `true` or a `WP_Error`.
4. **Object level.** Same access policy as REST. A missing and a not-viewable object give the **same** `WP_Error` (`{prefix}_not_found`, status 404 in `data`); viewable but not permitted gives `{prefix}_forbidden` (403). No existence leak.
5. **Service layer** enforces again (defence in depth, unchanged from REST).

Operational guidance (documented for site owners in part 2): one dedicated, least-privileged WordPress user per agent (never an administrator for read/write work), one application password per client, revoke on suspicion; application passwords need HTTPS (or `WP_ENVIRONMENT_TYPE=local`); WordPress has no brute-force limit for application passwords, so rate limiting belongs to the web server/WAF. Explain in the site-owner guide that the tier governs this plugin’s MCP abilities. The application password also authenticates the account at other enabled WordPress endpoints with that account’s capabilities.

## 6. Ability contract

Each ability is one small class `{Ns}\Mcp\Ability\<Verb><Noun>Ability` implementing `AbilityDefinition` (id, tier, label, description, input schema, output schema, annotations, `permission( array $input )`, `execute( array $input )`). A shared `AbilityFactory` builds the `wp_register_ability` args and wraps the callbacks; definitions cannot bypass the wrapper.

Required of every definition:

- **Input schema**: JSON Schema, `type: object`, `additionalProperties: false`, every string with `maxLength` or `enum`/`pattern`, every integer with `minimum`/`maximum`, every array with `maxItems`; ids `integer, minimum 1`. Same bounds as REST (page sizes, batch limits). `expectedRevision` required wherever REST requires it. No free-form `object` without size bounds.
- **Client discovery**: publish fixed choices as typed enums with defaults and bounds. Identify the read ability that supplies dynamic choices and their schemas. Test completeness against the authoritative registry, including extensions.
- **Destructive input**: require the JSON boolean `true` and check `($input['confirm'] ?? null) === true` before mutation. A client can set this flag itself.
- **Output schema**: declare allowed fields and their types, including structured settings and schemas; no paths, SQL, secrets, hostnames, e-mail addresses of others, nonces, raw provider data. Output is size-capped (`{CONST}_MCP_MAX_OUTPUT`, default 200 KB, whole records and complete schemas; part 2 defines pagination, oversize handling and how clients resume).
- **Wrapper behaviour**: `execute` runs inside `try/catch (Throwable)` → generic `WP_Error( '{prefix}_internal_error' )`, the log gets the exception class only. Domain errors map through the existing REST `ErrorMapper` to the same codes; messages translated, never leaking internals.
- **Errors at the client**: three different surfaces (verified with adapter 0.7.2 / WP 6.9). `WP_Ability::check_permissions()` returns the plugin's own `WP_Error` (code, status). Core `WP_Ability::execute()` replaces **every** refusal by the generic `ability_invalid_permissions` and raises a `_doing_it_wrong` notice with the plugin's message. The adapter's `execute-ability` tool calls the permission callback itself, so a client sees `isError: true` with the plugin's own message text (no code, no data). Never rely on codes at the client; keep refusal messages free of existence hints.
- **Audit**: every mutation logs `{prefix}_mcp_call` with ability id, user id and result code (whitelisted keys of the plugin logger; no payloads, no free text). Observers use a documented action that **fails open** (never vetoes), following `docs/hooks.md`.
- **Texts**: `label` translated (en + de, normal catalogs); `description` and schema descriptions are machine-facing English, not translated (decision recorded in `docs/decisions.md`). Descriptions are static: no user data, no instructions that depend on content.
- **Content is data.** Titles, captions and other user text returned by abilities may contain prompt-injection attempts; abilities return them as plain data fields, never concatenated into instructions or descriptions. Destructive effects are gated by tier + `confirm` + revision checks, not by trust in the caller.
- **Cache-busting/assets**: abilities add no CSS/JS. Admin UI for the tier setting follows the plugin's existing asset rules.

## 7. Code layout

Use the WordPress adapter directory named in part 2 (the domain core stays free of WP and MCP): `McpAvailability`, `McpRegistrar`, `TierPolicy`, `AbilityDefinition`, `AbilityFactory`, `Ability/*`. Optional input/output value objects live next to the abilities. Dependencies point inwards (Mcp → services/policy). `wp_register_ability` appears **only** in `AbilityFactory`. The generic classes (`McpAvailability`, `TierPolicy`, `AbilityDefinition`, `AbilityFactory`, the test trait of §8) are copied between plugins unchanged except namespace/prefix; a shared package is a later decision.

## 8. Mandatory security test (`McpAbilitySecurityTest` + `McpSecurityTestTrait`)

Runs in the WordPress PHPUnit suite with the adapter loaded (the test environment installs the pinned adapter as a dev dependency and initialises it **before the first test**, because the adapter initialises once per process and the test case restores hooks after every test); include its command in CI and the coverage gate (part 2). It fails closed: a new ability or route without an entry fails the build.

| # | Check |
|---|---|
| 1 | **Inventory.** Every registered ability with prefix `{slug}/` appears in the test's matrix; every matrix row exists (like the REST authorisation matrix). Names are valid, category is `{slug}`. |
| 2 | **Meta lint.** Per ability: `mcp.public === true`, `public` unset, `show_in_rest === false`; all three annotations are booleans; tier known; input schema has `additionalProperties: false`, bounded strings/arrays/integers; output schema present; `destructive` tier ⇒ `confirm` required and `destructive=true`; tier `read` ⇒ `readonly`; admin reads may also be readonly. |
| 3 | **No open callback.** Reflection on the registered args: `permission_callback` is neither `__return_true` nor a closure that returns a constant; static scan: `wp_register_ability` only in `AbilityFactory`, no `rest_do_request`, `$wpdb`, `unserialize`, `eval` in `Mcp/`. |
| 4 | **Actor × ability matrix.** Actors: anonymous, subscriber, contributor, author (owner), other author, editor, administrator (+ super admin in the multisite suite). Per ability the expected result (`allowed`, `forbidden`, `not_found`) is declared; `WP_Ability::check_permissions` (the plugin's codes) and `execute` (core's generic refusal, `setExpectedIncorrectUsage( 'WP_Ability::execute' )`) are called with a valid input per actor. Anonymous never succeeds. Not-found and hidden objects return the identical error. No response contains a path, SQL, secret, salt, nonce or foreign e-mail (pattern scan as in the REST matrix). |
| 5 | **Tier gate.** For each tier setting (`off`, `read`, `write`, `destructive`, `admin`): abilities above the tier are not registered, are absent from `discover-abilities`, and `execute-ability` answers an error; when lowered after registration, execution is refused immediately and discovery omits the ability after fresh registration. Test that the constant can lower the option and cannot raise it. Default is `read`. |
| 6 | **Transport (end to end through the adapter).** Enumerate every route of the MCP namespace via `rest_get_server()->get_routes()` and every method (POST, GET, DELETE): anonymous ⇒ 401/403; cookie without nonce ⇒ anonymous; invalid cookie nonce ⇒ 403; wrong application password ⇒ authentication failure; a subscriber reaches the transport and each ability follows the plugin matrix; valid application password acts as that user with identical object checks. Add a JSON-RPC path (`initialize`, `tools/call` of `discover-abilities`/`execute-ability`; failures arrive as `isError`): owner succeeds, a hidden object looks exactly like a missing one, tier-disabled abilities are neither discoverable nor executable, anonymous clients get no session. `execute-ability` refuses private and unknown names. A public fixture from a second plugin follows its own permission callback on the shared server. New routes in the namespace fail the test until listed. |
| 7 | **Annotation honesty.** Snapshot (hash of posts, postmeta, plugin tables, options) before/after each `readonly` ability as administrator: unchanged. Each `write` ability changes only plugin-owned objects (attachments, users, foreign options untouched). |
| 8 | **Input hardening.** Per ability: unknown key, wrong type, over-long string, oversize array, negative/zero/float/array ids, JSON depth bomb ⇒ validation error, nothing written. |
| 9 | **Output cap and errors.** Exercise pagination and the declared output limit, including an oversized single record; a throwing service yields `{prefix}_internal_error` without exception text; the log gets the class name only. |
| 10 | **Audit.** Each successful mutation writes exactly one `{prefix}_mcp_call` entry without payload data; record denials reaching the plugin permission callback there. Permission probes can run multiple times; do not count them as successful executions. |
| 11 | **Absence without adapter.** Second PHPUnit group runs without the adapter (and a unit case with the gate forced off through the kill-switch constant): no `{slug}/` ability, no category, only the two availability hooks may remain, no option created and no UI, plugin loads without notices. |

Coverage of `Mcp/` is above the plugin gate (> 80 %, `AGENTS.md`). PHPStan level 8 without baseline applies; WP/adapter symbols are declared in `tests/phpstan/`.

## 9. Delivery workflow per ability

Interface/definition → service reuse (no new rule logic) → add matrix row (§8 #4) → tests → catalogue entry in `docs/mcp-abilities.md` (parameters, result, tier, errors, permissions) → project checks named in part 2 → i18n update (`label`) → small commit. An ability is not done until its matrix row, schema bounds and catalogue entry exist.

## 10. Part 2 template (`docs/mcp-abilities.md` of the plugin)

1. Placeholder values (`{slug}`, `{Ns}`, `{prefix}`, `{CONST}`), adapter path, bootstrap, WordPress test/coverage commands and required versions (WP ≥ 6.9, tested adapter version – the adapter is pre-1.0, pin and re-test on update).
2. Site-owner section: enable, tier setting/constant, service user, application password, endpoint URL, client config example.
3. Overview table: ability id, tier, annotations, capability + object rule, REST equivalent.
4. Per ability: purpose (one sentence for the LLM), input table (name, type, bounds, required), output, errors, notes.
5. Shared conventions (revision handling, error codes, pagination).
6. Explicitly not exposed, with reason.
7. Security matrix table (actor × ability expectations) = the data of §8 #4.
8. Open decisions.
