<?php
/**
 * `$wpdb` implementation of the core transaction runner.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Infrastructure;

use SWGallery\Core\Contract\LoggerInterface;
use SWGallery\Core\Contract\TransactionRunnerInterface;
use SWGallery\Core\Logging\LogLevel;
use SWGallery\WordPress\Database\Schema;

final class WpTransactionRunner implements TransactionRunnerInterface {

	/** @var int Nesting depth of the single database connection of this request; shared by every instance. */
	private static int $depth = 0;

	/** @var bool|null Cached engine check (null: not checked yet). */
	private ?bool $transactional = null;
	private bool $warned         = false;

	/**
	 * @param LoggerInterface|null $logger Receives the one-time warning of the non-transactional fallback.
	 * @param string|null          $table Table whose storage engine decides whether transactions exist (default: the item table).
	 */
	public function __construct(
		private readonly ?LoggerInterface $logger = null,
		private readonly ?string $table = null
	) {
	}

	/** Whether a transaction is currently open (for joining callers such as the repository). */
	public static function in_transaction(): bool {
		return self::$depth > 0;
	}

	/**
	 * Inner failures propagate to the outermost run(), which rolls everything back.
	 *
	 * @template T
	 * @param callable(): T $work
	 * @return T
	 * @throws \RuntimeException When the transaction cannot be started or committed.
	 * @throws \Throwable Whatever the work throws, after the rollback.
	 */
	public function run( callable $work ): mixed {
		global $wpdb;

		if ( self::$depth > 0 ) {
			// Nested call: join the outer transaction, which owns commit and rollback.
			++self::$depth;
			try {
				return $work();
			} finally {
				--self::$depth;
			}
		}

		if ( ! $this->supports_transactions() ) {
			$this->warn_once();
			return $work();
		}

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw new \RuntimeException( 'The transaction could not be started.' );
		}
		self::$depth = 1;
		try {
			$result = $work();
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'The transaction could not be committed.' );
			}
			return $result;
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		} finally {
			self::$depth = 0;
		}
	}

	private function supports_transactions(): bool {
		if ( null !== $this->transactional ) {
			return $this->transactional;
		}
		global $wpdb;
		$table  = $this->table ?? Schema::items_table();
		$engine = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$table
			)
		);
		// A table that is not listed yet (not created, or a temporary table) is assumed transactional.
		$this->transactional = null === $engine || 'innodb' === strtolower( (string) $engine );
		return $this->transactional;
	}

	private function warn_once(): void {
		if ( $this->warned ) {
			return;
		}
		$this->warned = true;
		$this->logger?->log( LogLevel::Warning, 'swg_no_transactions', array( 'component' => 'transaction_runner' ) );
	}
}
