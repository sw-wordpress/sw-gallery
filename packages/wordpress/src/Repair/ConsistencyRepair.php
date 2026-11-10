<?php
/**
 * Finds and fixes inconsistent gallery data. Runs only on explicit request (WP-CLI), never on a normal request.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Repair;

use SWGallery\WordPress\Gallery\RevisionBump;
use SWGallery\WordPress\Repository\WpdbGalleryRelations;

/**
 * Repairs, in bounded batches:
 * - relation rows whose gallery post or attachment no longer exists,
 * - gallery covers pointing to an attachment that no longer exists,
 * - duplicate (gallery, attachment) rows (the lowest id, i.e. the oldest, is kept).
 *
 * Only relation rows and the stale cover reference are ever removed; attachments and gallery posts are never touched.
 * A dry run (the default) changes nothing and only reports.
 */
final class ConsistencyRepair {

	public const DEFAULT_BATCH_SIZE = 500;
	public const MIN_BATCH_SIZE     = 10;
	public const MAX_BATCH_SIZE     = 5000;

	/** Safety stop per phase, so a run always terminates (batch size * this limit rows). */
	private const MAX_ITERATIONS = 10000;

	private RevisionBump $revisions;

	public function __construct( private readonly WpdbGalleryRelations $relations, ?RevisionBump $revisions = null ) {
		$this->revisions = $revisions ?? new RevisionBump();
	}

	/**
	 * @param bool $apply      False reports only (dry run); true removes what was found.
	 * @param int  $batch_size Rows per batch; clamped to MIN_BATCH_SIZE..MAX_BATCH_SIZE.
	 */
	public function run( bool $apply = false, int $batch_size = self::DEFAULT_BATCH_SIZE ): RepairReport {
		$batch_size = max( self::MIN_BATCH_SIZE, min( self::MAX_BATCH_SIZE, $batch_size ) );
		$progress   = new RepairProgress();

		$this->duplicates( $progress, $apply, $batch_size );
		$this->orphans( $progress, $apply, $batch_size );
		$this->covers( $progress, $apply, $batch_size );

		return $progress->report( $apply, $batch_size );
	}

	private function duplicates( RepairProgress $progress, bool $apply, int $batch_size ): void {
		$groups = $this->relations->find_duplicates( $batch_size );
		if ( ! $apply ) {
			foreach ( $groups as $group ) {
				foreach ( $group['extra_ids'] as $id ) {
					$progress->found( RepairReport::DUPLICATE_ROWS, $id );
				}
			}
			// A dry run cannot page through groups (nothing is removed), so a full page means "possibly more".
			if ( count( $groups ) >= $batch_size ) {
				$progress->incomplete();
			}
			return;
		}
		for ( $i = 0; $i < self::MAX_ITERATIONS && array() !== $groups; ++$i ) {
			$extra = array();
			foreach ( $groups as $group ) {
				foreach ( $group['extra_ids'] as $id ) {
					$extra[] = $id;
					$progress->found( RepairReport::DUPLICATE_ROWS, $id );
				}
			}
			$removed = $this->relations->delete_by_ids( $extra );
			for ( $n = 0; $n < $removed; ++$n ) {
				$progress->fixed( RepairReport::DUPLICATE_ROWS );
			}
			if ( 0 === $removed ) {
				$progress->incomplete();
				return;
			}
			$groups = $this->relations->find_duplicates( $batch_size );
		}
		if ( array() !== $groups ) {
			$progress->incomplete();
		}
	}

	private function orphans( RepairProgress $progress, bool $apply, int $batch_size ): void {
		$after = 0;
		for ( $i = 0; $i < self::MAX_ITERATIONS; ++$i ) {
			$rows = $this->relations->find_orphans( $after, $batch_size );
			if ( array() === $rows ) {
				return;
			}
			$ids        = array();
			$categories = array();
			foreach ( $rows as $row ) {
				$category     = 'gallery_missing' === $row['reason'] ? RepairReport::ORPHAN_GALLERY_ROWS : RepairReport::ORPHAN_ATTACHMENT_ROWS;
				$ids[]        = $row['id'];
				$categories[] = $category;
				$after        = max( $after, $row['id'] );
				$progress->found( $category, $row['id'] );
			}
			if ( $apply ) {
				// The database reports how many rows went away; attribute them to the categories in order.
				$removed = $this->relations->delete_by_ids( $ids );
				foreach ( array_slice( $categories, 0, $removed ) as $category ) {
					$progress->fixed( $category );
				}
			}
		}
		$progress->incomplete();
	}

	private function covers( RepairProgress $progress, bool $apply, int $batch_size ): void {
		$after = 0;
		for ( $i = 0; $i < self::MAX_ITERATIONS; ++$i ) {
			$covers = $this->relations->find_dangling_covers( $after, $batch_size );
			if ( array() === $covers ) {
				return;
			}
			foreach ( $covers as $cover ) {
				$after = max( $after, $cover['meta_id'] );
				$progress->found( RepairReport::DANGLING_COVERS, $cover['gallery_id'] );
				// Value-specific delete: a cover changed meanwhile is left alone.
				if ( $apply && delete_post_meta( $cover['gallery_id'], '_thumbnail_id', $cover['attachment_id'] ) ) {
					$progress->fixed( RepairReport::DANGLING_COVERS );
					$this->revisions->bump( $cover['gallery_id'] );
				}
			}
		}
		$progress->incomplete();
	}
}
