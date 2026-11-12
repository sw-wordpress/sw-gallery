<?php
/**
 * The media epoch: one token that changes whenever an attachment a gallery may show changes.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Rendering;

/**
 * Part of every fragment cache key (CODE-STYLE 4). The gallery revision does not move when an attachment is edited
 * in the Media Library (alt text, title, caption, regenerated sub-sizes, replaced file, deletion), so those events
 * change this token instead and every cached fragment misses once.
 *
 * The token is random, not a counter: two requests that bump concurrently cannot both end on the same value a cache
 * entry was written under. Writes are bounded per request: the first change writes at once, further changes in the
 * same request are merged into one more write at `shutdown` (a bulk edit costs at most two writes).
 */
final class MediaEpoch {

	public const OPTION = 'swg_media_epoch';

	private bool $written  = false;
	private bool $dirty    = false;
	private bool $deferred = false;

	/** The current token ('0' before the first change). */
	public function current(): string {
		$value = get_option( self::OPTION, '0' );
		return is_string( $value ) && '' !== $value ? $value : '0';
	}

	/** Records a media change; see the class description for the write budget. */
	public function bump(): void {
		if ( ! $this->written ) {
			$this->write();
			$this->written = true;
			return;
		}
		$this->dirty = true;
		if ( ! $this->deferred ) {
			$this->deferred = true;
			add_action( 'shutdown', array( $this, 'flush' ) );
		}
	}

	/** Writes the merged change of the rest of the request (`shutdown`). */
	public function flush(): void {
		if ( $this->dirty ) {
			$this->dirty = false;
			$this->write();
		}
	}

	private function write(): void {
		update_option( self::OPTION, bin2hex( random_bytes( 6 ) ), false );
	}
}
