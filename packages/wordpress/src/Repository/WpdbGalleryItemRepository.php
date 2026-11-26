<?php
/**
 * `$wpdb` implementation of the gallery membership repository.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Repository;

use SWGallery\Core\Application\Render\RenderItemPage;
use SWGallery\Core\Contract\GalleryItemRepositoryInterface;
use SWGallery\Core\Contract\ItemEditStoreInterface;
use SWGallery\Core\Contract\RenderItemSourceInterface;
use SWGallery\Core\Domain\Gallery\GalleryItem;
use SWGallery\Core\Domain\Gallery\ItemOverrides;
use SWGallery\Core\Domain\Gallery\SortMode;
use SWGallery\WordPress\Database\Schema;
use SWGallery\WordPress\Media\AllowedMimeTypes;
use SWGallery\WordPress\Infrastructure\WpTransactionRunner;

final class WpdbGalleryItemRepository implements GalleryItemRepositoryInterface, ItemEditStoreInterface, RenderItemSourceInterface {

	/** Upper bound of the stored JSON of one item's settings, in bytes. */
	public const MAX_SETTINGS_BYTES = 65536;

	/** Rows per `CASE` UPDATE of applyOrder() (keeps one statement far below max_allowed_packet). */
	public const ORDER_CHUNK = 1000;

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

		return $this->hydrate( (array) $rows );
	}

	/**
	 * One page for the renderer: the items table joined with the attachment posts, so only usable images (existing,
	 * not trashed, allowed MIME type) are counted and returned, ordered in SQL and cut with LIMIT/OFFSET. Relations of
	 * unusable attachments stay untouched. Two queries (page, total) regardless of the page size.
	 */
	public function findRenderPage( int $galleryId, SortMode $sortMode, int $offset, int $limit, bool $publicOnly = false ): RenderItemPage {
		global $wpdb;
		$table = Schema::items_table();
		$mimes = AllowedMimeTypes::all();
		$in    = implode( ',', array_fill( 0, count( $mimes ), '%s' ) );
		// Identifiers come from this allowlist, never from input.
		$order = match ( $sortMode ) {
			SortMode::Manual    => 'i.position ASC, i.id ASC',
			SortMode::DateAsc   => 'p.post_date_gmt ASC, i.id ASC',
			SortMode::DateDesc  => 'p.post_date_gmt DESC, i.id ASC',
			SortMode::TitleAsc  => 'p.post_title ASC, i.id ASC',
			SortMode::TitleDesc => 'p.post_title DESC, i.id ASC',
		};
		$from = "FROM {$table} i INNER JOIN {$wpdb->posts} p ON p.ID = i.attachment_id AND p.post_type = 'attachment' AND p.post_status = 'inherit' AND p.post_mime_type IN ({$in}) WHERE i.gallery_id = %d";

		if ( $publicOnly ) {
			// Inherited attachments are public when unattached or attached to a published, unprotected parent.
			$from .= " AND p.post_password = '' AND (p.post_parent = 0 OR NOT EXISTS (SELECT 1 FROM {$wpdb->posts} parent WHERE parent.ID = p.post_parent AND (parent.post_status <> 'publish' OR parent.post_password <> '')))";
		}

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$from}", array_merge( $mimes, array( $galleryId ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table name from prefix, placeholders generated.
		if ( 0 === $total ) {
			return new RenderItemPage( array(), 0 );
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the placeholders are generated, the arguments are merged below.
				"SELECT i.id, i.gallery_id, i.attachment_id, i.position, i.title_override, i.caption_override, i.alt_override {$from} ORDER BY {$order} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table name from prefix, order from an allowlist, placeholders generated.
				array_merge( $mimes, array( $galleryId, max( 1, min( 500, $limit ) ), max( 0, $offset ) ) )
			),
			ARRAY_A
		);

		return new RenderItemPage( $this->hydrate( (array) $rows ), $total );
	}

	/**
	 * @param array<int, mixed> $rows Result rows (ARRAY_A).
	 * @return list<GalleryItem>
	 */
	private function hydrate( array $rows ): array {
		$items = array();
		foreach ( $rows as $row ) {
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

	/**
	 * Stores the new order: one SELECT of the current positions, then one `CASE` UPDATE per ORDER_CHUNK rows whose
	 * position actually changes (M2-07, measured in docs/performance.md). A single move touches only the span between
	 * the old and the new place; positions become contiguous (1..n) as before.
	 */
	public function applyOrder( int $galleryId, array $itemIds ): void {
		global $wpdb;
		$table = Schema::items_table();
		// Joins the caller's transaction when there is one; a stand-alone call is atomic by itself.
		( $this->transactions ?? new WpTransactionRunner() )->run(
			static function () use ( $wpdb, $table, $galleryId, $itemIds ): void {
				$stored  = $wpdb->get_results( $wpdb->prepare( "SELECT id, position FROM {$table} WHERE gallery_id = %d", $galleryId ), OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
				$changed = array();
				foreach ( $itemIds as $index => $item_id ) {
					$position = $index + 1;
					$row      = $stored[ $item_id ] ?? null;
					if ( null === $row || (int) $row->position !== $position ) {
						$changed[ (int) $item_id ] = $position;
					}
				}
				foreach ( array_chunk( $changed, self::ORDER_CHUNK, true ) as $chunk ) {
					$cases = '';
					foreach ( $chunk as $item_id => $position ) {
						$cases .= $wpdb->prepare( ' WHEN %d THEN %d', $item_id, $position );
					}
					$ids    = implode( ',', array_map( 'intval', array_keys( $chunk ) ) );
					$result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET position = CASE id{$cases} END WHERE gallery_id = %d AND id IN ({$ids})", $galleryId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from prefix, ids are integers and the CASE arms were prepared above.
					if ( false === $result ) {
						throw new \RuntimeException( 'The order could not be stored.' );
					}
				}
			}
		);
	}

	/** NULL columns mean "inherit", empty strings are intentional blanks; both round-trip exactly. */
	public function saveOverrides( int $galleryId, int $itemId, ItemOverrides $overrides ): bool {
		return $this->saveItemEdit( $galleryId, $itemId, $overrides, null );
	}

	public function itemSettings( int $galleryId, int $itemId ): array {
		global $wpdb;
		$table = Schema::items_table();
		$json  = $wpdb->get_var( $wpdb->prepare( "SELECT settings FROM {$table} WHERE id = %d AND gallery_id = %d", $itemId, $galleryId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
		return $this->decode_settings( $json );
	}

	/**
	 * One page in stored manual order including each item's settings: one query per page, independent of the page size
	 * (`findPage()` omits the settings and `itemSettings()` reads one item, which would cost one query per row).
	 *
	 * @param int $galleryId Gallery id.
	 * @param int $offset    Zero-based offset.
	 * @param int $limit     Page size (1..500).
	 * @return list<ItemRecord>
	 */
	public function findPageWithSettings( int $galleryId, int $offset, int $limit ): array {
		global $wpdb;
		$table = Schema::items_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, gallery_id, attachment_id, position, title_override, caption_override, alt_override, settings FROM {$table} WHERE gallery_id = %d ORDER BY position ASC, id ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
				$galleryId,
				max( 1, min( 500, $limit ) ),
				max( 0, $offset )
			),
			ARRAY_A
		);
		return $this->records( (array) $rows );
	}

	/**
	 * A single item including its settings, or null when it is not part of the gallery.
	 *
	 * @param int $galleryId Gallery id.
	 * @param int $itemId    Item id.
	 */
	public function findWithSettings( int $galleryId, int $itemId ): ?ItemRecord {
		global $wpdb;
		$table = Schema::items_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, gallery_id, attachment_id, position, title_override, caption_override, alt_override, settings FROM {$table} WHERE gallery_id = %d AND id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
				$galleryId,
				$itemId
			),
			ARRAY_A
		);
		return is_array( $row ) ? $this->records( array( $row ) )[0] : null;
	}

	/**
	 * @param array<int, mixed> $rows Result rows (ARRAY_A) including the settings column.
	 * @return list<ItemRecord>
	 */
	private function records( array $rows ): array {
		$records = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$records[] = new ItemRecord( $this->hydrate( array( $row ) )[0], $this->decode_settings( $row['settings'] ?? null ) );
			}
		}
		return $records;
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

	public function saveItemSettings( int $galleryId, int $itemId, array $settings ): bool {
		return $this->saveItemEdit( $galleryId, $itemId, null, $settings );
	}

	public function saveItemEdit( int $galleryId, int $itemId, ?ItemOverrides $overrides, ?array $settings ): bool {
		global $wpdb;
		$data = array( 'updated_at' => gmdate( 'Y-m-d H:i:s' ) );
		if ( null !== $overrides ) {
			$data['title_override']   = $overrides->title;
			$data['caption_override'] = $overrides->caption;
			$data['alt_override']     = $overrides->alt;
		}
		if ( null !== $settings ) {
			$data['settings'] = $this->encode_settings( $settings );
		}

		$result = $wpdb->update(
			Schema::items_table(),
			$data,
			array(
				'id'         => $itemId,
				'gallery_id' => $galleryId,
			),
			array_fill( 0, count( $data ), '%s' ),
			array( '%d', '%d' )
		);
		return $this->updated_or_exists( $result, $galleryId, $itemId );
	}

	/**
	 * @param array<string, mixed> $settings Settings to store.
	 * @throws \InvalidArgumentException Settings cannot be encoded as JSON.
	 * @throws \LengthException Settings exceed the storage limit.
	 */
	private function encode_settings( array $settings ): ?string {
		if ( array() === $settings ) {
			return null;
		}
		$json = wp_json_encode( $settings );
		if ( false === $json ) {
			throw new \InvalidArgumentException( 'The item settings are not JSON compatible.' );
		}
		if ( strlen( $json ) > self::MAX_SETTINGS_BYTES ) {
			throw new \LengthException( 'The item settings are too large.' );
		}
		return $json;
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
