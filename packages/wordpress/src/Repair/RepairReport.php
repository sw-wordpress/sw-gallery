<?php
/**
 * Structured result of one consistency repair run.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Repair;

final class RepairReport {

	public const ORPHAN_GALLERY_ROWS    = 'orphan_gallery_rows';
	public const ORPHAN_ATTACHMENT_ROWS = 'orphan_attachment_rows';
	public const DANGLING_COVERS        = 'dangling_covers';
	public const DUPLICATE_ROWS         = 'duplicate_rows';

	/**
	 * @param bool                               $applied    Whether fixes were applied (false = dry run).
	 * @param int                                $batch_size Batch size used.
	 * @param array<string, int>                 $found      Problems found per category.
	 * @param array<string, int>                 $fixed      Problems fixed per category (all 0 in a dry run).
	 * @param bool                               $complete   False when a safety limit stopped the scan early.
	 * @param list<array{type: string, id: int}> $samples    Up to {@see self::MAX_SAMPLES} affected ids (relation row id, or gallery id for covers).
	 */
	public function __construct(
		public readonly bool $applied,
		public readonly int $batch_size,
		public readonly array $found,
		public readonly array $fixed,
		public readonly bool $complete,
		public readonly array $samples
	) {
	}

	public const MAX_SAMPLES = 20;

	/** @return list<string> */
	public static function categories(): array {
		return array( self::ORPHAN_GALLERY_ROWS, self::ORPHAN_ATTACHMENT_ROWS, self::DANGLING_COVERS, self::DUPLICATE_ROWS );
	}

	public function total_found(): int {
		return array_sum( $this->found );
	}

	public function total_fixed(): int {
		return array_sum( $this->fixed );
	}

	/**
	 * @return array{mode: string, batch_size: int, complete: bool, found: array<string, int>, fixed: array<string, int>, samples: list<array{type: string, id: int}>}
	 */
	public function to_array(): array {
		return array(
			'mode'       => $this->applied ? 'apply' : 'dry-run',
			'batch_size' => $this->batch_size,
			'complete'   => $this->complete,
			'found'      => $this->found,
			'fixed'      => $this->fixed,
			'samples'    => $this->samples,
		);
	}
}
