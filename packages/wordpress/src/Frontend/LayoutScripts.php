<?php
/**
 * The progressive-enhancement scripts of the layouts: the single table that wires them to the frontend assets.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Frontend;

use SWGallery\Core\Application\Render\RenderAsset;

/**
 * Adding a layout script is one entry here (plus the {@see RenderAsset} case and the build entry in
 * `assets/vite.config.ts` / `assets/scripts/build-all.mjs`): the manifest reader validates the entry, the registrar
 * registers the handle with the plugin version, the footer enqueue loads it only for galleries that were rendered and
 * the editor preview lists it. Keys are {@see RenderAsset::value}; the manifest key is the Vite source path.
 */
final class LayoutScripts {

	/** @var array<string, string> Manifest key per RenderAsset value. */
	public const MANIFEST_KEYS = array(
		'masonry'  => 'frontend/src/masonry.ts',
		'carousel' => 'frontend/src/carousel.ts',
		'focus'    => 'frontend/src/focus.ts',
	);

	/**
	 * The assets that are layout scripts.
	 *
	 * @return list<RenderAsset>
	 */
	public static function assets(): array {
		return array_map( static fn ( string $value ): RenderAsset => RenderAsset::from( $value ), array_keys( self::MANIFEST_KEYS ) );
	}

	/**
	 * Script handle, e.g. `swg-masonry`.
	 *
	 * @return non-empty-string
	 */
	public static function handle( RenderAsset $asset ): string {
		return 'swg-' . $asset->value;
	}
}
