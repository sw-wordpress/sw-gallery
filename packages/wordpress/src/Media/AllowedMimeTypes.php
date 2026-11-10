<?php
/**
 * MIME types that may be used in galleries (docs/decisions.md: raster images WordPress can create sub-sizes for).
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Media;

final class AllowedMimeTypes {

	/** Always allowed: every WordPress image editor handles them. */
	private const ALWAYS = array( 'image/jpeg', 'image/png', 'image/gif' );

	/** Allowed only when the server's image editor can process them (no sub-sizes otherwise). */
	private const IF_SUPPORTED = array( 'image/webp', 'image/avif' );

	/**
	 * SVG and HEIC are deliberately absent in 1.0.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		$types = self::ALWAYS;
		foreach ( self::IF_SUPPORTED as $type ) {
			if ( wp_image_editor_supports( array( 'mime_type' => $type ) ) ) {
				$types[] = $type;
			}
		}
		return $types;
	}

	public static function is_allowed( string $mime_type ): bool {
		return in_array( $mime_type, self::all(), true );
	}
}
