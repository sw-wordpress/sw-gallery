<?php
/**
 * Implementation of the public PHP API function {@see swg_render_gallery()}.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Api;

use SWGallery\Core\Application\Render\EntryPoint;
use SWGallery\WordPress\Infrastructure\Services;

final class GalleryApi {

	/**
	 * @param int                  $gallery_id Gallery id.
	 * @param array<string, mixed> $args       Untrusted arguments, see swg_render_gallery().
	 * @return string Escaped markup; empty when nothing may be shown.
	 */
	public static function render( int $gallery_id, array $args ): string {
		if ( $gallery_id <= 0 ) {
			return '';
		}
		$options = Services::embed_arguments()->options( $gallery_id, $args, true );
		return Services::embed_renderer()->render( $gallery_id, EntryPoint::PhpApi, $options );
	}
}
