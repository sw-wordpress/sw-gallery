<?php
/**
 * Collects what the galleries rendered on the current request need.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- core value objects use camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Frontend;

use SWGallery\Core\Application\Render\RenderAsset;
use SWGallery\Core\Application\Render\RenderResult;

/**
 * Tiny request-scoped registry shared by every output surface (shortcode, PHP API, block). A surface calls
 * {@see self::record()} with each {@see RenderResult} it printed; {@see FrontendAssets} enqueues from it in the
 * footer and the admin bar lists the recorded galleries. Results without assets (nothing rendered, password form,
 * feed markup) are ignored, so a page without a visible gallery loads nothing.
 */
final class AssetCollector {

	/** @var array<int, true> Gallery ids in render order. */
	private array $galleries = array();

	/** @var array<string, RenderAsset> */
	private array $assets = array();

	/**
	 * @param int          $gallery_id Gallery that was rendered.
	 * @param RenderResult $result     Its render result.
	 */
	public function record( int $gallery_id, RenderResult $result ): void {
		if ( $gallery_id <= 0 || array() === $result->assets ) {
			return;
		}
		$this->galleries[ $gallery_id ] = true;
		foreach ( $result->assets as $asset ) {
			$this->assets[ $asset->value ] = $asset;
		}
	}

	/** Whether any gallery with frontend assets was rendered. */
	public function has_rendered(): bool {
		return array() !== $this->galleries;
	}

	public function needs( RenderAsset $asset ): bool {
		return isset( $this->assets[ $asset->value ] );
	}

	/**
	 * Distinct ids of the rendered galleries in render order.
	 *
	 * @return list<int>
	 */
	public function gallery_ids(): array {
		return array_keys( $this->galleries );
	}
}
