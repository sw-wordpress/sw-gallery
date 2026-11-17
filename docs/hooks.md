# Events and hooks – failure policy

Failure policy per CODE-STYLE §9. Every public action/filter (`swg_*`) is an observation point with a **defined failure policy**.

* Hooks fire only after persistent state is consistent (e.g. after gallery items/revision are saved), never mid-write.
* Listener errors are isolated: caught, logged (no secrets), ignored. Exceptions: explicit `before_*` filters may veto (return `false`/`WP_Error`),
  which fails the operation cleanly *before* any state change.
* Payloads are read-only arrays of IDs/scalars; never raw paths, tokens or private URLs.
* Core (`packages/core`) exposes its own event dispatcher; only the WordPress adapter bridges it to `swg_*` hooks.
* Document each hook in this file: name, when, payload, may-veto. Hooks listed in SPECIFICATION §7 (layout/provider/panel registries) follow the same rules.

## Index of all public hooks
Everything not listed here is internal. Compatibility rules: `docs/extending.md`. All hooks are `@since 1.0.0` (first public release).

| Hook | Type | Fired | Arguments | May veto | Failure policy |
|---|---|---|---|---|---|
| `swg_<event>` (10 events, below) | action | after commit | `array $payload` (ids/scalars) | no | throwing callback caught, logged, ignored |
| `swg_before_gallery_trashing`, `swg_before_gallery_deleting` | filter | before any change | `true`, `array $payload` | yes (`false` / `WP_Error`) | throwing callback logged, does **not** veto (fails open) |
| `swg_gallery_changed` | action | after every persisted gallery change, incl. attachment deletion/cover cleared/repair | `int $gallery_id` | no | caught, logged, ignored |
| `swg_register_layouts` | action | once per request after `plugins_loaded`, built-ins registered | `LayoutRegistryInterface $registry`, `int $api_version` | no (a rejected layout is logged) | caught, logged; remaining callbacks of the hook skipped |
| `swg_register_import_providers` | action | once per request after `plugins_loaded` | `ImportProviderRegistryInterface $registry` | no (a rejected provider is logged) | caught, logged; remaining callbacks skipped |

No render-argument filter and no admin side-panel hook exist in 1.0 (reasons: `docs/extending.md`, "Not implemented in 1.0"). Log codes (`swg_listener_failed`, `swg_layout_rejected`, `swg_layout_failed`, `swg_import_provider_rejected`) carry only the hook/event name, class names and exception classes, never messages, paths or values.

## Core events
Names are the core event names; the adapter bridges them as `swg_<name>` (bridge: `Infrastructure/EventBridge`, see the end of this file). Payloads are IDs and scalars only.
Listener failures are logged with code `swg_listener_failed` (event name and exception class only, never the message) and ignored.
A veto reason code must match `^[a-z][a-z0-9_]{0,63}$`, otherwise it becomes `swg_vetoed`.

| Event | Fired | Payload | May veto |
|---|---|---|---|
| `gallery_created` | after commit | gallery_id, owner_id, revision | no |
| `gallery_updated` | after commit | gallery_id, revision | no |
| `gallery_trashed` | after commit | gallery_id, revision | no |
| `gallery_restored` | after commit | gallery_id, revision | no |
| `gallery_deleted` | after commit | gallery_id, revision | no |
| `gallery_duplicated` | after commit | gallery_id (copy), source_id, owner_id, revision | no |
| `items_assigned` | after commit | gallery_id, attachment_ids (actually added), revision | no |
| `items_removed` | after commit | gallery_id, item_ids (actually removed), revision | no |
| `items_reordered` | after commit | gallery_id, revision | no |
| `item_updated` | after commit (overrides or settings) | gallery_id, item_id, revision | no |
| `before_gallery_trashing` | before any change | gallery_id, actor_id | yes |
| `before_gallery_deleting` | before any change | gallery_id, actor_id | yes |

## WordPress bridge (implemented)
`EventBridgeRegistrar` attaches `EventBridge` to the shared dispatcher (`Services::events()`); every core event reaches WordPress as `swg_<event_name>`.

* **Actions** (all events except the two below): `do_action( 'swg_<name>', array $payload )`, payload exactly as in the table. A callback that throws is caught, logged as `swg_listener_failed` (event and exception class) and ignored; remaining callbacks of that hook are skipped, other core listeners still run.
* **Filters** `swg_before_gallery_trashing` and `swg_before_gallery_deleting`: `apply_filters( $hook, true, array $payload )` with payload `gallery_id`, `actor_id`. Returning `false` or a `WP_Error` vetoes; the `WP_Error` code is the reason code (must match `^[a-z][a-z0-9_]{0,63}$`, else `swg_vetoed`; the message is never exposed). Any other return value, or a throwing callback (logged), does **not** veto.
* No other hook can veto. Hooks are observation points, never an access-control mechanism.

```php
add_filter( 'swg_before_gallery_deleting', static function ( $allowed, array $payload ) {
	return is_gallery_locked( $payload['gallery_id'] ) ? new WP_Error( 'my_gallery_locked', 'Locked.' ) : $allowed;
}, 10, 2 );
add_action( 'swg_items_assigned', static function ( array $payload ): void {
	// $payload['gallery_id'], $payload['attachment_ids'], $payload['revision']
} );
```

## `swg_gallery_changed` (cache purge)
`do_action( 'swg_gallery_changed', int $gallery_id )` fires after every persisted gallery change, once per change and gallery, after the revision was saved; vetoable `before_*` events never fire it. Sources (never two for one change):

* the core events `gallery_created|updated|trashed|restored|deleted|duplicated`, `items_assigned|removed|reordered`, `item_updated`;
* `RevisionBump`: an attachment was deleted (once per gallery that used it or had it as cover; relations and cover are cleaned first), and `wp swg repair --apply` cleared a dangling cover (once per fixed gallery). A dry run fires nothing.

Use it to purge page caches/CDN for pages embedding the gallery. Payload: the gallery id only; no veto. A throwing callback is caught, logged as `swg_listener_failed` (event, hook, exception class) and ignored. Plain attachment edits (alt text, regenerated sub-sizes) do not fire it (they change the media epoch of the fragment cache, see `docs/rendering.md`). Not covered: orphan relation rows removed by repair (no visible change: unusable attachments are skipped when rendering).

```php
add_action( 'swg_gallery_changed', static function ( int $gallery_id ): void {
	my_cache_purge_gallery( $gallery_id );
} );
```

## Registration actions
`swg_register_layouts( LayoutRegistryInterface $registry, int $api_version )` and `swg_register_import_providers( ImportProviderRegistryInterface $registry )` fire **once per request** the first time the registry is used after `plugins_loaded` (the registry instance is the same before and after, a callback that reads it does not recurse). The registry passed to callbacks is guarded: `register()` never throws; a contract violation (invalid id, empty schema, throwing `getId()`), a duplicate id or an invalid provider id is rejected and logged (`swg_layout_rejected`, `swg_import_provider_rejected`: class names only). A throwing callback is logged as `swg_listener_failed` (`hook`, `exception`), what was registered before it stays, later callbacks of that hook are skipped. Contracts and examples: `docs/extending.md`.

## Verified update local configuration

`swg_verified_update_descriptors` and `swg_verified_release_public_keys` configure trusted local PHP identity/key data; never expose them as user-entered URLs or remote key discovery. Exceptions abort the check (fail closed), never authorize an unsigned fallback. Defaults bind only sw-gallery. See [update architecture](GITHUB-UPDATES.md).
