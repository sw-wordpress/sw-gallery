# Extending sw-gallery

Public extension points of 1.0: **layouts**, **import providers**, **hooks**, the **PHP API**, the **theming contract** and the **REST API**. Everything else is internal (see "What is not public"). Third-party code never breaks a request: every registration and every callback is isolated, failures are logged without messages or paths (`docs/hooks.md`).

## Layouts (`swg_register_layouts`)

```php
use SWGallery\Core\Contract\GalleryLayoutInterface;
use SWGallery\Core\Contract\LayoutRegistryInterface;
use SWGallery\Core\Domain\Layout\LayoutRenderRequest;

final class My_Wall_Layout implements GalleryLayoutInterface {
	public function getId(): string { return 'my-wall'; }                 // ^[a-z][a-z0-9_-]{0,63}$
	public function getLabel(): string { return 'My wall'; }              // English, translated by your plugin if you like
	public function getSettingsSchema(): array {                         // never empty
		return array(
			'type'       => 'object',
			'properties' => array(
				'columns' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 6, 'x-responsive' => true ),
			),
			'x-item-settings' => array(                                   // optional per-item settings
				'type'       => 'object',
				'properties' => array( 'emphasis' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 3 ) ),
			),
		);
	}
	public function render( LayoutRenderRequest $request ): string {      // complete, escaped HTML; usable without JavaScript
		$html = '';
		foreach ( $request->items as $item ) { // RenderItem: itemId, attachmentId, title, caption, alt, thumbnail, full, loading
			$html .= '<li class="swg-item">' . esc_html( $item->title ) . '</li>';
		}
		return '<ul class="swg-items">' . $html . '</ul>';
	}
}

add_action( 'swg_register_layouts', static function ( LayoutRegistryInterface $registry, int $api_version ): void {
	if ( 1 === $api_version ) {
		$registry->register( new My_Wall_Layout() );
	}
}, 10, 2 );
```

* **When:** once per request, lazily on first use of the registry after `plugins_loaded`; the built-ins (`grid`, ...) are registered first. Register from a plugin file, never from a theme template. A callback that reads the registry (`has`, `all`) sees the layouts registered so far.
* **Contract version:** `GalleryLayoutInterface::API_VERSION` (currently `1`), also passed as the second action argument. Breaking changes ship as a new interface and a new version; a layout written for version 1 keeps working for the whole 1.x line.
* **Validation:** an object that breaks the contract (invalid id, empty schema, throwing `getId()`/`getSettingsSchema()`) or reuses an id is **rejected and logged** (`swg_layout_rejected`, layout class and exception class only); `register()` returns normally. A duplicate never replaces a built-in or an earlier layout.
* **Schema:** a strict JSON-schema subset (`type`, `enum`, `minimum`, `maximum`, `minLength`, `maxLength`, `minItems`, `maxItems`, `items`, `properties`, `required`, `default`), plus `x-responsive: true` (value or object keyed `base|sm|md|lg|xl`), `x-item-settings` (schema of the per-item settings) and `x-enum-titles` (optional map `enum value => translated label` for `enum` properties, e.g. `'x-enum-titles' => array( 'left' => __( 'Natural height (left-aligned)', 'sw-gallery' ) )`; only non-empty string labels are used, a missing title shows the raw value, it never affects validation). The admin app builds its forms from it; `GET /layouts` lists every registered layout with its schema.
* **Optional:** implement `ImageHintsProviderInterface` (`imageHints()`) to tell the thumbnail resolver the display width and `sizes`.
* **Containment:** if your layout throws while rendering (`render`, `imageHints`, merging overrides), the request continues: the pipeline logs `swg_layout_failed`, renders the `grid` fallback and shows editors a diagnostic (visitors see the plain grid). Do not rely on this: it is a safety net.
* **Rules:** DOM order = manual order, items stay plain links (lightbox contract in `assets/README.md`), escape everything, no secrets or paths in the markup, no network access.

## Import providers (`swg_register_import_providers`)

`MediaImportProviderInterface` (`packages/core/src/Contract`) is provider neutral: folders and media are listed page-wise with opaque cursors, `openDownloadStream()` returns a stream, failures are `ImportProviderException` (transient or permanent). Register with `$registry->register( $provider )` inside `add_action( 'swg_register_import_providers', ... )` (once per request). The provider id must match `^[a-z][a-z0-9_-]{0,63}$`; an invalid or duplicate id is rejected and logged (`swg_import_provider_rejected`), so nobody can replace the built-in `media-library` provider (registered through this same action at priority 0).

**Not implemented in 1.0:** no cloud connector (Google Drive, Dropbox, ...), no import UI, no import REST routes or jobs; the registry and the contract are the only parts that exist (SPECIFICATION §9 is the plan). Credentials never appear in the contract. The Media Library provider resolves to existing attachments (nothing is copied) and follows decision D11 (`WpGalleryAccessPolicy::canUseAttachment`).

## Hooks

All public actions and filters with payload, timing and failure policy: `docs/hooks.md`. Summary: after-commit actions `swg_<event>`, `swg_gallery_changed`, veto filters `swg_before_gallery_trashing|deleting`, registration actions above.

## PHP API

`swg_render_gallery( int $gallery_id, array $args = array() ): string` returns (never echoes) escaped HTML or `''`; allowlisted `$args` and access rules in `docs/rendering.md`. It is the only PHP function that is public; classes under `SWGallery\` are internal except the interfaces and value objects named on this page.

## Theming contract

CSS custom properties (`--swg-*`), class names (`.swg-gallery`, `.swg-item`, ...) and the data attributes of the lightbox: `assets/README.md`. The shortcode wrapper `.swg-shortcode` and its width rule: `docs/rendering.md`.

## REST API

`swg/v1`: `docs/rest-api.md` (routes, errors, `expectedRevision`, `GET /layouts`).

## Not implemented in 1.0

* **Admin side-panel registry:** deliberately absent (decision D7); planned as R1.1-07. There is no placeholder API, filter or action; do not build on any internal admin app structure.
* **Cloud import connectors and import UI:** see above.
* **Render-argument filter:** none. Presentation overrides are validated against the layout schema (`EmbedArguments`); a filter would let other code bypass that validation, and every surface (shortcode, block, PHP API, preview, REST) must stay identical. Use a custom layout or the stored settings instead.
* **Dedicated integrations** with sibling plugins: `docs/integrations.md`.

## Compatibility policy

Public contracts follow semantic versioning (`MAJOR.MINOR.PATCH`) from 1.0 on. "Public" means: documented on this page or in `docs/hooks.md`, `docs/rest-api.md` or `assets/README.md`.

| Contract | Minor/patch release may | Only a major release may |
|---|---|---|
| Hooks (`swg_*`) | add hooks, add payload keys, add optional arguments | rename or remove a hook, remove a payload key, change a payload type or the failure policy |
| PHP API (`swg_render_gallery`) | add optional `$args` keys | change or remove a signature, change the return type |
| REST (`swg/v1`) | add routes, optional parameters and response fields | remove or rename routes/fields, change types, status codes or permissions |
| Layout / provider interfaces | add a new optional interface, add constants | change a method signature or the meaning of `API_VERSION` (a new interface/version is introduced instead) |
| Theming (`--swg-*`, `.swg-*`, `data-swg-*`) | add properties, classes and attributes | rename or remove one, change its meaning |

* **Deprecation:** a public element is announced as deprecated for **at least one minor release** before removal in the next major: `@deprecated x.y.z` in the docblock and a `_deprecated_function()` / `_deprecated_hook()` / `_deprecated_argument()` notice (`WP_DEBUG` only) where WordPress offers one, plus an entry in the changelog. Removal never happens in a minor or patch release.
* **`@since`:** every public class, interface, function, hook and constant carries `@since <version>`; new hooks are documented with their version in `docs/hooks.md`.
* **Not public** (may change in any release): everything under `packages/wordpress/src` except what is listed above, database tables and columns (`{prefix}swg_gallery_items`, ...), post meta keys (`_swg_*`), option names, the `swg_gallery` post type (not `public`, no front-end URLs), the transient/object-cache keys and the admin app (`window.swgAdmin`, routes, components). Read data through the REST API or `swg_render_gallery()`, not through the tables.

Layouts may read `LayoutRenderRequest::$revision` (seed) and `RenderItem::$settings` (the item's `x-item-settings` values). Element ids in layout markup must start with `<instance id>-` (the render cache rewrites the instance id on hits). Layout scripts are registered through the `Frontend/LayoutScripts` table; integer enums with `x-enum-titles` render as selects in the settings form.

