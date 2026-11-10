<?php
/**
 * Resolves native WordPress image attachments.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Media;

use SWGallery\Core\Contract\MediaReferenceRepositoryInterface;
use SWGallery\Core\Contract\MediaRepositoryInterface;
use SWGallery\Core\Domain\Media\MediaReference;

final class WpMediaRepository implements MediaRepositoryInterface, MediaReferenceRepositoryInterface {

	public function existingImageIds( array $attachmentIds ): array {
		if ( array() === $attachmentIds ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'post__in'               => $attachmentIds,
				'post_mime_type'         => implode( ',', AllowedMimeTypes::all() ),
				'fields'                 => 'ids',
				'posts_per_page'         => count( $attachmentIds ),
				'orderby'                => 'post__in',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * Two queries per chunk of {@see MediaReferenceRepositoryInterface::MAX_BATCH} ids (posts, then their meta in one
	 * go); title, caption, MIME type, alt text and dimensions are read from those primed caches.
	 */
	public function findReferences( array $attachmentIds ): array {
		$ids        = array_values( array_unique( array_filter( array_map( 'intval', $attachmentIds ), static fn( int $id ): bool => $id > 0 ) ) );
		$references = array();
		foreach ( array_chunk( $ids, self::MAX_BATCH ) as $chunk ) {
			$posts = get_posts(
				array(
					'post_type'              => 'attachment',
					'post_status'            => 'inherit',
					'post__in'               => $chunk,
					'post_mime_type'         => implode( ',', AllowedMimeTypes::all() ),
					'posts_per_page'         => count( $chunk ),
					'orderby'                => 'post__in',
					'no_found_rows'          => true,
					'update_post_meta_cache' => true,
					'update_post_term_cache' => false,
				)
			);
			foreach ( $posts as $post ) {
				$references[ $post->ID ] = $this->reference( $post );
			}
		}
		return $references;
	}

	private function reference( \WP_Post $post ): MediaReference {
		$meta = wp_get_attachment_metadata( $post->ID );
		return new MediaReference(
			$post->ID,
			$post->post_title,
			$post->post_excerpt,
			(string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
			$post->post_mime_type,
			is_array( $meta ) ? max( 0, (int) ( $meta['width'] ?? 0 ) ) : 0,
			is_array( $meta ) ? max( 0, (int) ( $meta['height'] ?? 0 ) ) : 0
		);
	}
}
