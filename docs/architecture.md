# Architecture

Orientation for developers. Binding rules: `AGENTS.md`, `CODE-STYLE.md`, `SPECIFICATION.md`; decisions: [decisions.md](decisions.md).

## Layers

```
 apps/admin (Vue 3 + Quasar)      assets/ (lightbox, layout CSS, masonry; block editor script; media-library.js)
        |  REST swg/v1 + window.swgAdmin       ^ enqueued by the adapter, markup contract in assets/README.md
        v                                      |
 packages/wordpress  (adapter: the only code that uses WordPress APIs)
        |  implements the contracts, wires Services, owns REST, rendering views, admin page, hooks, CLI
        v
 packages/core       (framework independent: no WordPress functions, classes or hooks)
        Domain (values, status rules) · Application (services, render pipeline) · Contract (interfaces) · Event · Exception
```

Dependencies point inwards: `wordpress -> core`, never the reverse; the apps talk to PHP only through REST and the bootstrap object. Core
is PSR-12 with `strict_types`; the adapter follows WPCS (CODE-STYLE §3). Core works with in-memory test doubles (`tests/Support`), so its
rules run without WordPress or a database.

| Part | Where | Notes |
|---|---|---|
| Contracts | `packages/core/src/Contract` | repositories, access policy, layout/import interfaces, `RevisionStoreInterface`, `TransactionRunnerInterface` |
| Services | `packages/core/src/Application/Service` | `GalleryService`, `GalleryItemService`, `GalleryItemEditService`, `LayoutRegistry`; take an `Actor` and enforce the access policy themselves |
| Render pipeline | `packages/core/src/Application/Render/RenderGallery.php` | WordPress free |
| Adapter wiring | `packages/wordpress/src/Infrastructure/Services.php`, `Bootstrap/Registrars.php` | one registrar per feature, listed alphabetically; `Plugin::boot()` runs them |
| Persistence | `Repository/`, `Database/` | `WpGalleryRepository`, `WpdbGalleryItemRepository`, `WpdbGalleryRelations`, `PostMetaRevisionStore`, `Schema`, `Migrator` |
| REST | `Rest/` | controllers `GalleryController`, `ItemController`, `MediaController`, `LayoutController`, `SettingsController`, `StatusController`, `PreviewController` |
| Surfaces | `Shortcode/`, `Block/`, `Api/`, `Frontend/`, `Cli/` | all call `Services::embed_renderer()` / `Services::gallery_renderer()` |
| Admin | `Admin/AdminRegistrar.php`, `Admin/MediaLibraryRegistrar.php` | menu page + bootstrap; Media Library column, field and delete guard |

## Data model

| What | Storage | Code |
|---|---|---|
| Gallery | custom post type `swg_gallery` (not public, no UI, no REST of its own); status = post status, owner = post author, password = post password, cover = `_thumbnail_id` | `PostTypes` |
| Gallery meta | `_swg_revision`, `_swg_layout`, `_swg_settings`, `_swg_sort_mode`, `_swg_schema_version` | `PostTypes`, `PostMetaRevisionStore` |
| Items (order, relation to attachment, overrides) | table `{prefix}swg_gallery_items`, versioned by option `swg_schema_version` | `Database/Schema` (steps), `Migrator`, `WpdbGalleryItemRepository` |
| Options | `swg_settings`, `swg_delete_data_on_uninstall`, `swg_media_epoch`, a log ring buffer, a migration lock; none autoloaded | `Settings/PluginSettings`, `Lifecycle`, `Rendering/MediaEpoch`, `Infrastructure/WpLogger`, `Database/Migrator` |
| Caches | object cache group `swg_render` (fragments, TTL 12 h); no transients are written (uninstall still removes any `swg_` transient) | `Rendering/RenderCache` |

Attachments are the source of truth and are never copied or deleted by gallery operations. Uninstall removes the plugin's own data only when
the owner opted in ([user-guide.md](user-guide.md)). Not public: tables, meta and option names ([extending.md](extending.md) compatibility policy).

## Flows

**REST write path** (`PATCH /galleries/{id}` as the example)
1. The controller validates the argument schema and checks the coarse capability (`edit_posts`); anonymous requests are 401.
2. It builds an `Actor` and calls the core service with `expectedRevision`.
3. The service checks the access policy (`WpGalleryAccessPolicy`, capabilities evaluated with `user_can()` against the stored post), then
   inside one `TransactionRunnerInterface` transaction claims the revision with `RevisionStoreInterface::compareAndIncrement()` (a stale value
   fails with `RevisionConflictException`, REST 409, nothing written), and writes through the repositories. A newer stored schema refuses the write.
4. After commit the service dispatches core events (`GalleryUpdated`, `ItemsReordered`, ...); `EventBridge` forwards them as `swg_<event>`
   actions, veto events as filters; failing listeners are isolated ([hooks.md](hooks.md)). `GalleryChangedNotifier` fires `swg_gallery_changed`.
5. `ErrorMapper` turns exceptions into the documented status codes and generic messages ([rest-api.md](rest-api.md)).

**Render path** (block, shortcode, `swg_render_gallery()`, preview, feed, `wp swg render`)
1. A surface turns untrusted arguments into options (`Frontend/EmbedArguments`, `Block/BlockAttributes`) and calls `GalleryRenderer::render()`.
2. Access: `GalleryAccessPolicyInterface::embedAccess()` decides before any item is loaded (D4): content, password form, diagnostic or nothing.
3. Fragment cache (`RenderCache`): only public output for users who cannot edit; the key contains gallery id, revision, settings, locale, plugin
   version and the media epoch. Gallery/item changes bump the revision; attachment changes change the epoch. Misses run `RenderGallery`
   (one JOIN for a page of items, `ThumbnailResolver` for sizes, a registered layout renders the markup; a failing layout falls back to `grid`).
4. The result lists the assets it needs; `Frontend/AssetCollector` and `FrontendAssets` enqueue them (read from the build manifest) with
   `ver=SWGALLERY_VERSION`, only on pages that rendered a gallery. Details: [rendering.md](rendering.md).

**Admin app bootstrap**
`AdminRegistrar` adds the menu page and, only on that screen, resolves the hashed files from `assets/admin/.vite/manifest.json`
(`Admin/AssetManifest`), enqueues the module script and stylesheet with the plugin version, and prints `window.swgAdmin` (REST root, nonce,
locale, capability hints, timezone, RTL) as inline JSON with hex-escaped tags. `apps/admin/src/main.ts` mounts Vue, Pinia, vue-i18n and the hash
router; `api/client.ts` adds the nonce and refreshes it, a 401 shows the session banner without unmounting the views. A missing build is
reported on the page and in System Status. App details: `apps/admin/README.md`.

## Extension points

Layout and import-provider registries, hooks, the PHP function, REST and CSS custom properties: [extending.md](extending.md),
[hooks.md](hooks.md), [integrations.md](integrations.md). The admin side-panel registry does not exist in 1.0 (D7).

## Build outputs

| Project | Output (git-ignored, generated) | Shipped as |
|---|---|---|
| `apps/admin` | `assets/admin` (ES module bundle, chunks, fonts, `.vite/manifest.json`) | same path |
| `assets` | `assets/build` (lightbox, masonry, layout CSS, manifest) | same path |
| `assets/blocks` | `assets/blocks/build/index.js` + `assets/blocks/block.json` | same paths |
| `assets/media-library` | none (plain script) | as is |

`bin/build-zip.sh` builds all three from clean `npm ci`, stages the allowlist, installs production Composer packages (none yet) in the staging copy and
zips reproducibly; `bin/verify-zip.sh` and `bin/test-zip-install.sh` gate it ([release.md](release.md)). Third-party code in the ZIP:
[../THIRD-PARTY-NOTICES.md](../THIRD-PARTY-NOTICES.md).

## Tests

| Suite | Where | Covers | Command |
|---|---|---|---|
| Unit | `tests/Unit` | core domain, services, render pipeline with in-memory doubles; extension contracts; security scans (`Security/`); release scripts (`VersionScriptTest`, `VerifyZipScriptTest`); i18n | `composer test` |
| WordPress | `tests/wp/tests` | adapter against a real WordPress and a private database: repositories, REST, rendering, block, shortcode, cache, lifecycle, hooks, security matrix; also multisite | `composer test:wp`, `composer test:wp:ms` |
| Admin app | `apps/admin/tests` | Vitest: components, stores, API client, i18n parity, lint probes, bundle scan | `npm run test:coverage` |
| Frontend / block | `assets/lightbox/tests`, `assets/frontend/tests`, `assets/blocks/tests` | lightbox, layout CSS, masonry, block editor UI | `npm run test:coverage` |
| End-to-end | `e2e/tests`, `e2e/zip` | Playwright against a throw-away WordPress: admin flows, roles, accessibility (axe), frontend, ZIP smoke test | `bin/test-e2e.sh`, `bin/test-zip-install.sh` |

Coverage gate (strictly above 80 %), the private database and the CI matrix: [development.md](development.md). `tests/Integration` and `tests/E2e`
are reserved, currently empty suites.

## Decision log

[decisions.md](decisions.md) (D-numbers, per-wave tables). Open work: `TODO.md`; finished work: `DONE.md`.
