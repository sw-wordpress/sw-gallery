<?php
/**
 * `$wpdb` implementation of the gallery membership repository.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Repository;

use SWGallery\Core\Contract\GalleryItemRepositoryInterface;
use SWGallery\Core\Contract\ItemOverridesStoreInterface;
use SWGallery\Core\Contract\ItemSettingsStoreInterface;
use SWGallery\Core\Domain\Gallery\GalleryItem;
use SWGallery\Core\Domain\Gallery\ItemOverrides;
use SWGallery\WordPress\Database\Schema;
use SWGallery\WordPress\Infrastructure\WpTransactionRunner;

final class WpdbGalleryItemRepository implements GalleryItemRepositoryInterface, ItemOverridesStoreInterface, ItemSettingsStoreInterface {

	/** Upper bound of the stored JSON of one item's settings, in bytes. */
	public const MAX_SETTINGS_BYTES = 65536;

	public function __construct( private readonly ?WpTransactionRunner $transactions = null ) {
	}

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
		// Joins the caller's transaction when there is one; a stand-alone call is atomic by itself.
		( $this->transactions ?? new WpTransactionRunner() )->run(
			static function () use ( $wpdb, $table, $galleryId, $itemIds ): void {
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
			}
		);
	}

	/** NULL columns mean "inherit", empty strings are intentional blanks; both round-trip exactly. */
	public function saveOverrides( int $galleryId, int $itemId, ItemOverrides $overrides ): bool {
		global $wpdb;
		$result = $wpdb->update(
			Schema::items_table(),
			array(
				'title_override'   => $overrides->title,
				'caption_override' => $overrides->caption,
				'alt_override'     => $overrides->alt,
				'updated_at'       => gmdate( 'Y-m-d H:i:s' ),
			),
			array(
				'id'         => $itemId,
				'gallery_id' => $galleryId,
			),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d', '%d' )
		);
		return $this->updated_or_exists( $result, $galleryId, $itemId );
	}

	public function itemSettings( int $galleryId, int $itemId ): array {
		global $wpdb;
		$table = Schema::items_table();
		$json  = $wpdb->get_var( $wpdb->prepare( "SELECT settings FROM {$table} WHERE id = %d AND gallery_id = %d", $itemId, $galleryId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
		if ( ! is_string( $json ) || '' === $json ) {
			return array();
		}
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$settings = array();
		foreach ( $decoded as $key => $value ) {
			$settings[ (string) $key ] = $value;
		}
		return $settings;
	}

	public function saveItemSettings( int $galleryId, int $itemId, array $settings ): bool {
		global $wpdb;
		$json = null;
		if ( array() !== $settings ) {
			$json = wp_json_encode( $settings );
			if ( false === $json ) {
				throw new \InvalidArgumentException( 'The item settings are not JSON compatible.' );
			}
			if ( strlen( $json ) > self::MAX_SETTINGS_BYTES ) {
				throw new \LengthException( 'The item settings are too large.' );
			}
		}
		$result = $wpdb->update(
			Schema::items_table(),
			array(
				'settings'   => $json,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array(
				'id'         => $itemId,
				'gallery_id' => $galleryId,
			),
			array( '%s', '%s' ),
			array( '%d', '%d' )
		);
		return $this->updated_or_exists( $result, $galleryId, $itemId );
	}

	/**
	 * Zero affected rows can still mean an identical row, so existence is checked separately.
	 *
	 * @param int|false $result Result of $wpdb->update().
	 * @throws \RuntimeException When the update failed.
	 */
	private function updated_or_exists( int|false $result, int $galleryId, int $itemId ): bool {
		global $wpdb;
		if ( false === $result ) {
			throw new \RuntimeException( 'The gallery item could not be stored.' );
		}
		if ( $result > 0 ) {
			return true;
		}
		$table = Schema::items_table();
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id = %d AND gallery_id = %d", $itemId, $galleryId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
	}
}
