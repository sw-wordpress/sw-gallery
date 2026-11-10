<?php
/**
 * `$wpdb` implementation of the gallery membership repository.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Repository;

use SWGallery\Core\Contract\GalleryItemRepositoryInterface;
use SWGallery\Core\Domain\Gallery\GalleryItem;
use SWGallery\Core\Domain\Gallery\ItemOverrides;
use SWGallery\WordPress\Database\Schema;

final class WpdbGalleryItemRepository implements GalleryItemRepositoryInterface {

	public function findPage( int $galleryId, int $offset, int $limit ): array {
		global $wpdb;
		$table = Schema::items_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, gallery_id, attachment_id, position, title_override, caption_override, alt_override FROM {$table} WHERE gallery_id = %d ORDER BY position ASC, id ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
				$galleryId,
				max( 1, min( 500, $limit ) ),
				max( 0, $offset )
			),
			ARRAY_A
		);

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = new GalleryItem(
				(int) $row['id'],
				(int) $row['gallery_id'],
				(int) $row['attachment_id'],
				(int) $row['position'],
				new ItemOverrides(
					null === $row['title_override'] ? null : (string) $row['title_override'],
					null === $row['caption_override'] ? null : (string) $row['caption_override'],
					null === $row['alt_override'] ? null : (string) $row['alt_override']
				)
			);
		}
		return $items;
	}

	public function count( int $galleryId ): int {
		global $wpdb;
		$table = Schema::items_table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE gallery_id = %d", $galleryId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
	}

	public function orderedIds( int $galleryId ): array {
		global $wpdb;
		$table = Schema::items_table();
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE gallery_id = %d ORDER BY position ASC, id ASC", $galleryId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
		return array_values( array_map( 'intval', (array) $ids ) );
	}

	public function assignedAttachmentIds( int $galleryId, array $attachmentIds ): array {
		global $wpdb;
		if ( array() === $attachmentIds ) {
			return array();
		}
		$table        = Schema::items_table();
		$placeholders = implode( ',', array_fill( 0, count( $attachmentIds ), '%d' ) );
		$sql          = "SELECT attachment_id FROM {$table} WHERE gallery_id = %d AND attachment_id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix, placeholders generated.
		$found        = $wpdb->get_col( $wpdb->prepare( $sql, array_merge( array( $galleryId ), $attachmentIds ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		return array_values( array_map( 'intval', (array) $found ) );
	}

	public function append( int $galleryId, int $attachmentId ): GalleryItem {
		global $wpdb;
		$table    = Schema::items_table();
		$now      = gmdate( 'Y-m-d H:i:s' );
		$position = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(position), 0) + 1 FROM {$table} WHERE gallery_id = %d", $galleryId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.

		$inserted = $wpdb->insert(
			$table,
			array(
				'gallery_id'    => $galleryId,
				'attachment_id' => $attachmentId,
				'position'      => $position,
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%d', '%d', '%d', '%s', '%s' )
		);
		if ( 1 !== $inserted ) {
			throw new \RuntimeException( 'The gallery item could not be stored.' );
		}
		return new GalleryItem( (int) $wpdb->insert_id, $galleryId, $attachmentId, $position );
	}

	public function remove( int $galleryId, int $itemId ): bool {
		global $wpdb;
		$deleted = $wpdb->delete(
			Schema::items_table(),
			array(
				'id'         => $itemId,
				'gallery_id' => $galleryId,
			),
			array( '%d', '%d' )
		);
		return 1 === $deleted;
	}

	public function applyOrder( int $galleryId, array $itemIds ): void {
		global $wpdb;
		$table = Schema::items_table();
		$wpdb->query( 'START TRANSACTION' );
		try {
			foreach ( $itemIds as $index => $item_id ) {
				$result = $wpdb->update(
					$table,
					array( 'position' => $index + 1 ),
					array(
						'id'         => $item_id,
						'gallery_id' => $galleryId,
					),
					array( '%d' ),
					array( '%d', '%d' )
				);
				if ( false === $result ) {
					throw new \RuntimeException( 'The order could not be stored.' );
				}
			}
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
	}
}
