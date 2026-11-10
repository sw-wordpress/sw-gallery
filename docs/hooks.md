# Events and hooks – failure policy

Failure policy per CODE-STYLE §9. Every public action/filter (`swg_*`) is an observation point with a **defined failure policy**.

* Hooks fire only after persistent state is consistent (e.g. after gallery items/revision are saved), never mid-write.
* Listener errors are isolated: caught, logged (no secrets), ignored. Exceptions: explicit `before_*` filters may veto (return `false`/`WP_Error`),
  which fails the operation cleanly *before* any state change.
* Payloads are read-only arrays of IDs/scalars; never raw paths, tokens or private URLs.
* Core (`packages/core`) exposes its own event dispatcher; only the WordPress adapter bridges it to `swg_*` hooks.
* Document each hook in this file: name, when, payload, may-veto. Hooks listed in SPECIFICATION §7 (layout/provider/panel registries) follow the same rules.

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
