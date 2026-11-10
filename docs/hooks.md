# Events and hooks – failure policy

Failure policy per CODE-STYLE §9. Every public action/filter (`swg_*`) is an observation point with a **defined failure policy**.

* Hooks fire only after persistent state is consistent (e.g. after gallery items/revision are saved), never mid-write.
* Listener errors are isolated: caught, logged (no secrets), ignored. Exceptions: explicit `before_*` filters may veto (return `false`/`WP_Error`),
  which fails the operation cleanly *before* any state change.
* Payloads are read-only arrays of IDs/scalars; never raw paths, tokens or private URLs.
* Core (`packages/core`) exposes its own event dispatcher; only the WordPress adapter bridges it to `swg_*` hooks.
* Document each hook in this file: name, when, payload, may-veto. Hooks listed in SPECIFICATION §7 (layout/provider/panel registries) follow the same rules.
