<?php
/**
 * Batched read of gallery item rows including their settings.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Rest;

use SWGallery\Core\Domain\Gallery\GalleryItem;
use SWGallery\Core\Domain\Gallery\ItemOverrides;
use SWGallery\WordPress\Database\Schema;

/**
 * One query per page (or per single item), independent of the page size: `GalleryItemRepositoryInterface::findPage()`
 * omits the settings and `ItemSettingsStoreInterface::itemSettings()` reads a single item, which would cost one query
 * per row. Read only; every write goes through the core services.
 */
final class ItemRowReader {

	/**
	 * One page in stored manual order (position, then id).
	 *
	 * @param int $gallery_id Gallery id.
	 * @param int $offset     Zero-based offset.
	 * @param int $limit      Page size (1..500).
	 * @return list<ItemRow>
	 */
	public function page( int $gallery_id, int $offset, int $limit ): array {
		global $wpdb;
		$table = Schema::items_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, gallery_id, attachment_id, position, title_override, caption_override, alt_override, settings FROM {$table} WHERE gallery_id = %d ORDER BY position ASC, id ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
				$gallery_id,
				max( 1, min( 500, $limit ) ),
				max( 0, $offset )
			),
			ARRAY_A
		);
		return $this->hydrate( (array) $rows );
	}

	/**
	 * A single item of the gallery, or null when it is not part of it.
	 *
	 * @param int $gallery_id Gallery id.
	 * @param int $item_id    Item id.
	 */
	public function find( int $gallery_id, int $item_id ): ?ItemRow {
		global $wpdb;
		$table = Schema::items_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, gallery_id, attachment_id, position, title_override, caption_override, alt_override, settings FROM {$table} WHERE gallery_id = %d AND id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
				$gallery_id,
				$item_id
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}
		return $this->hydrate( array( $row ) )[0];
	}

	/**
	 * @param array<int, mixed> $rows Database rows.
	 * @return list<ItemRow>
	 */
	private function hydrate( array $rows ): array {
		$result = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$result[] = new ItemRow(
				new GalleryItem(
					(int) $row['id'],
					(int) $row['gallery_id'],
					(int) $row['attachment_id'],
					(int) $row['position'],
					new ItemOverrides(
						null === $row['title_override'] ? null : (string) $row['title_override'],
						null === $row['caption_override'] ? null : (string) $row['caption_override'],
						null === $row['alt_override'] ? null : (string) $row['alt_override']
					)
				),
				$this->decode_settings( $row['settings'] )
			);
		}
		return $result;
	}

	/**
	 * @param mixed $json Stored JSON (NULL or empty when the item has no settings).
	 * @return array<string, mixed>
	 */
	private function decode_settings( mixed $json ): array {
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
}
