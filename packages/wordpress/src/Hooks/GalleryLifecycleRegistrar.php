<?php
/**
 * Keeps gallery data consistent when attachments or galleries are deleted (SPECIFICATION 3.5).
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Hooks;

use SWGallery\WordPress\Bootstrap\RegistrarInterface;
use SWGallery\WordPress\Gallery\RevisionBump;
use SWGallery\WordPress\PostTypes;
use SWGallery\WordPress\Repository\WpdbGalleryRelations;

/**
 * - `delete_attachment`: removes the item relations of the attachment and clears it as cover of any gallery; the
 *   renderer then falls back to the first item. Affected galleries get a new revision.
 * - `before_delete_post` of a gallery: removes its relations.
 * - Trashing and untrashing a gallery deliberately register nothing: relations are kept so a restore is lossless.
 *
 * All handlers are idempotent and work in bounded batches. They never delete an attachment or a gallery.
 */
final class GalleryLifecycleRegistrar implements RegistrarInterface {

	/** Galleries handled per page while clearing covers. */
	private const PAGE = 200;

	/** Safety stop for the cover loop (PAGE * MAX_PAGES galleries per attachment). */
	private const MAX_PAGES = 50;

	private WpdbGalleryRelations $relations;
	private RevisionBump $revisions;

	public function __construct( ?WpdbGalleryRelations $relations = null, ?RevisionBump $revisions = null ) {
		$this->relations = $relations ?? new WpdbGalleryRelations();
		$this->revisions = $revisions ?? new RevisionBump();
	}

	public function register(): void {
		add_action( 'delete_attachment', array( $this, 'on_delete_attachment' ) );
		add_action( 'before_delete_post', array( $this, 'on_before_delete_post' ) );
	}

	/**
	 * @param int|string $attachment_id Attachment about to be deleted.
	 */
	public function on_delete_attachment( $attachment_id ): void {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return;
		}
		$this->without_db_errors( fn() => $this->clean_up_attachment( $attachment_id ) );
	}

	/**
	 * Cleanup is best effort: a database problem here (e.g. the items table does not exist yet) must neither print
	 * errors nor stop the Media Library from deleting the attachment. `wp swg repair` removes whatever is left over.
	 *
	 * @param callable(): mixed $work Cleanup work.
	 */
	private function without_db_errors( callable $work ): void {
		global $wpdb;
		$previous = $wpdb->suppress_errors( true );
		try {
			$work();
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	private function clean_up_attachment( int $attachment_id ): void {
		$affected = array();
		for ( $page = 0; $page < self::MAX_PAGES; ++$page ) {
			$galleries = $this->galleries_with_cover( $attachment_id );
			if ( array() === $galleries ) {
				break;
			}
			foreach ( $galleries as $gallery_id ) {
				delete_post_meta( $gallery_id, '_thumbnail_id', $attachment_id );
				$affected[ $gallery_id ] = $gallery_id;
			}
		}
		foreach ( $this->relations->gallery_ids_with_attachment( $attachment_id ) as $gallery_id ) {
			$affected[ $gallery_id ] = $gallery_id;
		}
		$this->relations->delete_by_attachment( $attachment_id );
		foreach ( $affected as $gallery_id ) {
			$this->revisions->bump( $gallery_id );
		}
	}

	/**
	 * @param int|string $post_id Post about to be deleted permanently.
	 */
	public function on_before_delete_post( $post_id ): void {
		$post_id = (int) $post_id;
		if ( $post_id > 0 && PostTypes::GALLERY === get_post_type( $post_id ) ) {
			$this->without_db_errors( fn() => $this->relations->delete_by_gallery( $post_id ) );
		}
	}

	/**
	 * @return list<int> Galleries (any status, including the trash) that use the attachment as cover.
	 */
	private function galleries_with_cover( int $attachment_id ): array {
		$ids = get_posts(
			array(
				'post_type'              => PostTypes::GALLERY,
				'post_status'            => 'any',
				'fields'                 => 'ids',
				'posts_per_page'         => self::PAGE,
				'no_found_rows'          => true,
				'suppress_filters'       => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- bounded, runs only when an attachment is deleted.
				'meta_key'               => '_thumbnail_id',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- see above.
				'meta_value'             => (string) $attachment_id,
			)
		);
		return array_values( array_map( 'intval', $ids ) );
	}
}
