<?php
/**
 * Bounded sequential reader over a local file handle.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Import;

use SWGallery\Core\Contract\RemoteStreamInterface;

final class LocalFileStream implements RemoteStreamInterface {

	/** Upper bound for a single read, keeps memory bounded regardless of the caller's request. */
	public const MAX_CHUNK = 1048576;

	/** @var resource|null */
	private $handle;

	/**
	 * @param mixed $handle An open, readable stream resource (ownership passes to this object).
	 * @throws \InvalidArgumentException When no stream resource is given.
	 */
	public function __construct( $handle ) {
		if ( ! is_resource( $handle ) ) {
			throw new \InvalidArgumentException( 'A stream resource is required.' );
		}
		$this->handle = $handle;
	}

	public function read( int $length ): string {
		if ( null === $this->handle || $length < 1 ) {
			return '';
		}
		$chunk = fread( $this->handle, min( $length, self::MAX_CHUNK ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- bounded read of a validated handle.
		return is_string( $chunk ) ? $chunk : '';
	}

	public function isEof(): bool {
		return null === $this->handle || feof( $this->handle );
	}

	public function close(): void {
		if ( null !== $this->handle ) {
			fclose( $this->handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- handle owned by this object.
			$this->handle = null;
		}
	}

	public function __destruct() {
		$this->close();
	}
}
