<?php
/**
 * Gallery revision counter stored in post meta, bumped with an atomic compare-and-swap.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Repository;

use SWGallery\Core\Contract\RevisionStoreInterface;
use SWGallery\Core\Domain\Gallery\Revision;
use SWGallery\Core\Exception\RevisionConflictException;
use SWGallery\WordPress\PostTypes;

final class PostMetaRevisionStore implements RevisionStoreInterface {

	public function current( int $galleryId ): Revision {
		return new Revision( max( 0, (int) get_post_meta( $galleryId, PostTypes::META_REV, true ) ) );
	}

	public function compareAndIncrement( int $galleryId, Revision $expected ): Revision {
		global $wpdb;
		$actual = $this->current( $galleryId );
		if ( ! $actual->equals( $expected ) ) {
			throw new RevisionConflictException( $expected->value, $actual->value );
		}

		// metadata_exists(): get_post_meta() returns the registered default (0) for a missing row, never ''.
		if ( ! metadata_exists( 'post', $galleryId, PostTypes::META_REV ) ) {
			// First revision: add_post_meta(unique) is atomic enough for the unique key (post_id, meta_key) check.
			if ( false === add_post_meta( $galleryId, PostTypes::META_REV, 1, true ) ) {
				throw new RevisionConflictException( $expected->value, $this->current( $galleryId )->value );
			}
			return new Revision( 1 );
		}

		$next    = $expected->next();
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE post_id = %d AND meta_key = %s AND meta_value = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table name.
				(string) $next->value,
				$galleryId,
				PostTypes::META_REV,
				(string) $expected->value
			)
		);
		if ( 1 !== $updated ) {
			throw new RevisionConflictException( $expected->value, $this->current( $galleryId )->value );
		}
		wp_cache_delete( $galleryId, 'post_meta' );
		return $next;
	}
}
