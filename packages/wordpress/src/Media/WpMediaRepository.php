<?php
/**
 * Resolves native WordPress image attachments.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Media;

use SWGallery\Core\Contract\MediaRepositoryInterface;

final class WpMediaRepository implements MediaRepositoryInterface {

	public function existingImageIds( array $attachmentIds ): array {
		if ( array() === $attachmentIds ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post__in'       => $attachmentIds,
				'post_mime_type' => 'image',
				'fields'         => 'ids',
				'posts_per_page' => count( $attachmentIds ),
				'orderby'        => 'post__in',
				'no_found_rows'  => true,
			)
		);
		return array_values( array_map( 'intval', $ids ) );
	}
}
