<?php
/**
 * Translated labels of the lightbox dialog.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Rendering\View;

final class LightboxLabels {

	/**
	 * Read by the frontend script from `data-swg-labels` (assets/README.md); the key set is part of the markup contract.
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		return array(
			'dialog'   => __( 'Image viewer', 'sw-gallery' ),
			'close'    => __( 'Close', 'sw-gallery' ),
			'previous' => __( 'Previous image', 'sw-gallery' ),
			'next'     => __( 'Next image', 'sw-gallery' ),
			/* translators: 1: {current} placeholder, 2: {total} placeholder; keep both placeholders unchanged. */
			'counter'  => __( '{current} of {total}', 'sw-gallery' ),
			'error'    => __( 'The image could not be loaded.', 'sw-gallery' ),
		);
	}

	/**
	 * The escaped attribute value.
	 *
	 * @param array<string, string> $extra Additional translated labels of a layout script (carousel, focus), merged after the lightbox keys.
	 */
	public static function attribute( array $extra = array() ): string {
		return esc_attr( (string) wp_json_encode( array_merge( self::all(), $extra ) ) );
	}
}
