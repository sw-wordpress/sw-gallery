<?php
/**
 * Runs ordered, versioned schema steps. Each step must be repeatable; the stored version only advances after a
 * step succeeded, so a failing step is retried on the next request.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Database;

use SWGallery\Core\Logging\LogLevel;
use SWGallery\WordPress\Infrastructure\Services;

final class Migrator {

	/** Short-lived, non-autoloaded lock option holding its expiry timestamp. */
	public const LOCK_OPTION = 'swg_migration_lock';
	public const LOCK_TTL    = 60;

	/**
	 * @param array<int, callable():void> $steps Target version => step; run in ascending order.
	 * @param string                      $option Option holding the stored schema version.
	 */
	public function __construct( private readonly array $steps, private readonly string $option = Schema::OPTION ) {
	}

	public function target_version(): int {
		return array() === $this->steps ? 0 : max( array_keys( $this->steps ) );
	}

	public function stored_version(): int {
		return (int) get_option( $this->option, 0 );
	}

	/** Cheap check without any DDL. */
	public function is_current(): bool {
		return $this->stored_version() >= $this->target_version();
	}

	/**
	 * @param bool $force Re-run every step from the beginning (activation, repair); steps are repeatable.
	 * @return bool Whether the schema is at the target version afterwards. False when a step failed or another
	 *              request holds the lock; the next request retries.
	 */
	public function run( bool $force = false ): bool {
		if ( ! $this->acquire_lock() ) {
			return false;
		}
		try {
			// Re-read: another request may have finished while this one waited for the lock.
			wp_cache_delete( $this->option, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			$version = $force ? 0 : $this->stored_version();

			$steps = $this->steps;
			ksort( $steps );
			foreach ( $steps as $target => $step ) {
				if ( $target <= $version ) {
					continue;
				}
				try {
					$step();
				} catch ( \Throwable $e ) {
					Services::logger()->log(
						LogLevel::Error,
						'swg_migration_failed',
						array(
							'version'   => $target,
							'exception' => $e::class,
						)
					);
					return false;
				}
				update_option( $this->option, $target, false );
				$version = $target;
			}
			return $version >= $this->target_version();
		} finally {
			delete_option( self::LOCK_OPTION );
		}
	}

	private function acquire_lock(): bool {
		$expires = time() + self::LOCK_TTL;
		if ( add_option( self::LOCK_OPTION, $expires, '', false ) ) {
			return true;
		}
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		if ( (int) get_option( self::LOCK_OPTION, 0 ) < time() ) {
			// Stale lock of a crashed request: take it over (delete is atomic, add_option then decides the winner).
			delete_option( self::LOCK_OPTION );
			return (bool) add_option( self::LOCK_OPTION, $expires, '', false );
		}
		return false;
	}
}
