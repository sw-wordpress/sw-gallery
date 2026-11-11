<?php
/**
 * Bulk operations on the gallery item relations (`swg_gallery_items`) that do not belong to the single-item repository:
 * copying and removing whole memberships, grouped counts and integrity queries for the repair service.
 * No method touches an attachment or a gallery post; relations are the only thing that is ever deleted.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Repository;

use SWGallery\WordPress\Database\Schema;
use SWGallery\WordPress\PostTypes;

final class WpdbGalleryRelations {

	/** Rows removed per DELETE statement, so one call never holds long locks. */
	public const DELETE_BATCH = 1000;

	/** Safety stop for the delete loops (DELETE_BATCH * MAX_BATCHES rows per call and key). */
	private const MAX_BATCHES = 1000;

	/**
	 * Copies the membership of one gallery to another, including positions, overrides and item settings. New rows get
	 * fresh timestamps. The attachments themselves are only referenced.
	 *
	 * @return int Number of copied relations.
	 * @throws \RuntimeException When the copy fails (the caller cleans up its target).
	 */
	public function copy_membership( int $source_gallery_id, int $target_gallery_id ): int {
		global $wpdb;
		$table  = Schema::items_table();
		$now    = gmdate( 'Y-m-d H:i:s' );
		$copied = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (gallery_id, attachment_id, position, title_override, caption_override, alt_override, settings, created_at, updated_at) SELECT %d, attachment_id, position, title_override, caption_override, alt_override, settings, %s, %s FROM {$table} WHERE gallery_id = %d ORDER BY position ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
				$target_gallery_id,
				$now,
				$now,
				$source_gallery_id
			)
		);
		if ( false === $copied ) {
			throw new \RuntimeException( 'The gallery items could not be copied.' );
		}
		return (int) $copied;
	}

	/**
	 * Removes every relation of a gallery (idempotent).
	 *
	 * @return int Number of removed relations.
	 */
	public function delete_by_gallery( int $gallery_id ): int {
		return $this->delete_where( 'gallery_id', $gallery_id );
	}

	/**
	 * Removes every relation of an attachment in all galleries (idempotent).
	 *
	 * @return int Number of removed relations.
	 */
	public function delete_by_attachment( int $attachment_id ): int {
		return $this->delete_where( 'attachment_id', $attachment_id );
	}

	/**
	 * Galleries that contain an attachment.
	 *
	 * @return list<int>
	 */
	public function gallery_ids_with_attachment( int $attachment_id, int $limit = 1000 ): array {
		global $wpdb;
		$table = Schema::items_table();
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT gallery_id FROM {$table} WHERE attachment_id = %d ORDER BY gallery_id ASC LIMIT %d", $attachment_id, max( 1, $limit ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
		return array_values( array_map( 'intval', (array) $ids ) );
	}

	/**
	 * Number of items per gallery in one grouped query. Galleries without items are reported as 0.
	 *
	 * @param array<int> $gallery_ids At most 1000 ids are considered.
	 * @return array<int, int> Gallery id => item count.
	 */
	public function count_by_gallery( array $gallery_ids ): array {
		global $wpdb;
		$gallery_ids = $this->clean_ids( $gallery_ids, 1000 );
		if ( array() === $gallery_ids ) {
			return array();
		}
		$counts       = array_fill_keys( $gallery_ids, 0 );
		$table        = Schema::items_table();
		$placeholders = implode( ',', array_fill( 0, count( $gallery_ids ), '%d' ) );
		$sql          = "SELECT gallery_id, COUNT(*) AS item_count FROM {$table} WHERE gallery_id IN ({$placeholders}) GROUP BY gallery_id"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix, placeholders generated.
		$rows         = $wpdb->get_results( $wpdb->prepare( $sql, $gallery_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row['gallery_id'] ] = (int) $row['item_count'];
		}
		return $counts;
	}

	/**
	 * Number of live galleries (not trashed) that contain each attachment, in one grouped query. Attachments that are
	 * used nowhere are reported as 0. The count is global: it is not filtered by what the caller may see.
	 *
	 * @param array<int> $attachment_ids At most 1000 ids are considered.
	 * @return array<int, int> Attachment id => number of galleries.
	 */
	public function count_galleries_by_attachment( array $attachment_ids ): array {
		global $wpdb;
		$attachment_ids = $this->clean_ids( $attachment_ids, 1000 );
		if ( array() === $attachment_ids ) {
			return array();
		}
		$counts       = array_fill_keys( $attachment_ids, 0 );
		$table        = Schema::items_table();
		$placeholders = implode( ',', array_fill( 0, count( $attachment_ids ), '%d' ) );
		$sql          = "SELECT i.attachment_id, COUNT(DISTINCT i.gallery_id) AS gallery_count FROM {$table} i INNER JOIN {$wpdb->posts} g ON g.ID = i.gallery_id AND g.post_type = %s AND g.post_status <> 'trash' WHERE i.attachment_id IN ({$placeholders}) GROUP BY i.attachment_id"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from prefix and core, placeholders generated.
		$rows         = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( PostTypes::GALLERY ), $attachment_ids ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row['attachment_id'] ] = (int) $row['gallery_count'];
		}
		return $counts;
	}

	/**
	 * Number of relation rows, counted only up to a cap so the cost stays bounded on huge tables.
	 *
	 * @param int $cap Highest count that is still determined exactly (1 to 1000000).
	 * @return array{count: int, capped: bool} `capped` means the real number is higher than `count`.
	 */
	public function count_rows_bounded( int $cap ): array {
		global $wpdb;
		$cap   = max( 1, min( 1000000, $cap ) );
		$table = Schema::items_table();
		$found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM (SELECT 1 FROM {$table} LIMIT %d) AS bounded", $cap + 1 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
		return array(
			'count'  => min( $found, $cap ),
			'capped' => $found > $cap,
		);
	}

	/**
	 * Relations whose gallery or attachment no longer exists, in id order (keyset pagination).
	 *
	 * @param int $after_id Only rows with a greater id are returned (0 starts at the beginning).
	 * @param int $limit    Page size, 1 to 5000.
	 * @return list<array{id: int, gallery_id: int, attachment_id: int, reason: string}> Reason is `gallery_missing` or `attachment_missing`.
	 */
	public function find_orphans( int $after_id, int $limit ): array {
		global $wpdb;
		$table   = Schema::items_table();
		$rows    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.id, i.gallery_id, i.attachment_id, (g.ID IS NULL) AS gallery_missing FROM {$table} i LEFT JOIN {$wpdb->posts} g ON g.ID = i.gallery_id AND g.post_type = %s LEFT JOIN {$wpdb->posts} a ON a.ID = i.attachment_id AND a.post_type = 'attachment' WHERE i.id > %d AND (g.ID IS NULL OR a.ID IS NULL) ORDER BY i.id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from prefix and core.
				PostTypes::GALLERY,
				max( 0, $after_id ),
				max( 1, min( 5000, $limit ) )
			),
			ARRAY_A
		);
		$orphans = array();
		foreach ( (array) $rows as $row ) {
			$orphans[] = array(
				'id'            => (int) $row['id'],
				'gallery_id'    => (int) $row['gallery_id'],
				'attachment_id' => (int) $row['attachment_id'],
				'reason'        => 1 === (int) $row['gallery_missing'] ? 'gallery_missing' : 'attachment_missing',
			);
		}
		return $orphans;
	}

	/**
	 * Deletes relations by their own ids (used by the repair service after it identified them).
	 *
	 * @param array<int> $ids At most 5000 ids are considered.
	 * @return int Number of removed relations.
	 */
	public function delete_by_ids( array $ids ): int {
		global $wpdb;
		$ids = $this->clean_ids( $ids, 5000 );
		if ( array() === $ids ) {
			return 0;
		}
		$table        = Schema::items_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = "DELETE FROM {$table} WHERE id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix, placeholders generated.
		$deleted      = $wpdb->query( $wpdb->prepare( $sql, $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * Duplicate (gallery, attachment) rows, which the unique key normally prevents (broken or missing index).
	 *
	 * @param int $limit Number of duplicate groups, 1 to 5000.
	 * @return list<array{gallery_id: int, attachment_id: int, keep_id: int, extra_ids: list<int>}> The lowest id of each group is kept.
	 */
	public function find_duplicates( int $limit ): array {
		global $wpdb;
		$table      = Schema::items_table();
		$groups     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT gallery_id, attachment_id, MIN(id) AS keep_id, GROUP_CONCAT(id ORDER BY id ASC) AS all_ids FROM {$table} GROUP BY gallery_id, attachment_id HAVING COUNT(*) > 1 ORDER BY keep_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
				max( 1, min( 5000, $limit ) )
			),
			ARRAY_A
		);
		$duplicates = array();
		foreach ( (array) $groups as $group ) {
			$keep         = (int) $group['keep_id'];
			$duplicates[] = array(
				'gallery_id'    => (int) $group['gallery_id'],
				'attachment_id' => (int) $group['attachment_id'],
				'keep_id'       => $keep,
				'extra_ids'     => array_values(
					array_filter(
						array_map( 'intval', explode( ',', (string) $group['all_ids'] ) ),
						static fn( int $id ): bool => $id !== $keep
					)
				),
			);
		}
		return $duplicates;
	}

	/**
	 * Gallery covers (featured image) that point to an attachment that no longer exists, in meta id order.
	 *
	 * @param int $after_meta_id Only meta rows with a greater id are returned.
	 * @param int $limit         Page size, 1 to 5000.
	 * @return list<array{meta_id: int, gallery_id: int, attachment_id: int}>
	 */
	public function find_dangling_covers( int $after_meta_id, int $limit ): array {
		global $wpdb;
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.meta_id, m.post_id, m.meta_value FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} g ON g.ID = m.post_id AND g.post_type = %s LEFT JOIN {$wpdb->posts} a ON a.ID = CAST(m.meta_value AS UNSIGNED) AND a.post_type = 'attachment' WHERE m.meta_key = '_thumbnail_id' AND m.meta_id > %d AND a.ID IS NULL ORDER BY m.meta_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table names.
				PostTypes::GALLERY,
				max( 0, $after_meta_id ),
				max( 1, min( 5000, $limit ) )
			),
			ARRAY_A
		);
		$covers = array();
		foreach ( (array) $rows as $row ) {
			$covers[] = array(
				'meta_id'       => (int) $row['meta_id'],
				'gallery_id'    => (int) $row['post_id'],
				'attachment_id' => (int) $row['meta_value'],
			);
		}
		return $covers;
	}

	/**
	 * @param array<mixed> $ids   Raw ids.
	 * @param int          $limit Maximum number of ids kept.
	 * @return list<int> Unique positive ids.
	 */
	private function clean_ids( array $ids, int $limit ): array {
		$clean = array();
		foreach ( array_slice( $ids, 0, $limit ) as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$clean[ $id ] = $id;
			}
		}
		return array_values( $clean );
	}

	/**
	 * Deletes in bounded batches until nothing is left (or the safety stop is reached).
	 *
	 * @param string $column `gallery_id` or `attachment_id` (allowlisted).
	 */
	private function delete_where( string $column, int $value ): int {
		global $wpdb;
		if ( ! in_array( $column, array( 'gallery_id', 'attachment_id' ), true ) || $value <= 0 ) {
			return 0;
		}
		$table = Schema::items_table();
		$total = 0;
		for ( $i = 0; $i < self::MAX_BATCHES; ++$i ) {
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$column} = %d LIMIT %d", $value, self::DELETE_BATCH ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from prefix, column allowlisted above.
			if ( ! is_int( $deleted ) || $deleted < 1 ) {
				break;
			}
			$total += $deleted;
			if ( $deleted < self::DELETE_BATCH ) {
				break;
			}
		}
		return $total;
	}
}
