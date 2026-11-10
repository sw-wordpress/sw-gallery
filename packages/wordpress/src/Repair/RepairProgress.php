<?php
/**
 * Mutable counters of one repair run; turned into the immutable {@see RepairReport} at the end.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Repair;

final class RepairProgress {

	/** @var array<string, int> */
	private array $found;

	/** @var array<string, int> */
	private array $fixed;

	/** @var list<array{type: string, id: int}> */
	private array $samples = array();

	private bool $complete = true;

	public function __construct() {
		$this->found = array_fill_keys( RepairReport::categories(), 0 );
		$this->fixed = $this->found;
	}

	/**
	 * Counts one problem and remembers its id as a sample (the number of samples is bounded).
	 */
	public function found( string $category, int $id ): void {
		++$this->found[ $category ];
		if ( count( $this->samples ) < RepairReport::MAX_SAMPLES ) {
			$this->samples[] = array(
				'type' => $category,
				'id'   => $id,
			);
		}
	}

	public function fixed( string $category ): void {
		++$this->fixed[ $category ];
	}

	/** Marks the scan as stopped early by a safety limit. */
	public function incomplete(): void {
		$this->complete = false;
	}

	public function report( bool $applied, int $batch_size ): RepairReport {
		return new RepairReport( $applied, $batch_size, $this->found, $this->fixed, $this->complete, $this->samples );
	}
}
