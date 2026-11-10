<?php
/**
 * Transaction runner against the real database: rollback, nesting and the non-transactional fallback.
 *
 * @package sw-gallery
 */

use SWGallery\Tests\Support\RecordingLogger;
use SWGallery\WordPress\Database\Schema;
use SWGallery\WordPress\Infrastructure\WpTransactionRunner;

class WpTransactionRunnerTest extends SwgTestCase {

	private function row_count(): int {
		global $wpdb;
		$table = Schema::items_table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB
	}

	private function insert_row( int $attachment_id ): void {
		global $wpdb;
		$wpdb->insert(
			Schema::items_table(),
			array(
				'gallery_id'    => 1,
				'attachment_id' => $attachment_id,
				'position'      => 1,
				'created_at'    => '2026-01-01 00:00:00',
				'updated_at'    => '2026-01-01 00:00:00',
			)
		);
	}

	public function test_commit_keeps_the_writes_and_returns_the_result(): void {
		$runner = new WpTransactionRunner();

		$result = $runner->run(
			function (): string {
				$this->insert_row( 1 );
				return 'done';
			}
		);

		$this->assertSame( 'done', $result );
		$this->assertSame( 1, $this->row_count() );
		$this->assertFalse( WpTransactionRunner::in_transaction() );
	}

	public function test_a_throwing_work_is_rolled_back_and_the_exception_is_rethrown_unchanged(): void {
		$runner   = new WpTransactionRunner();
		$expected = new \DomainException( 'boom' );

		try {
			$runner->run(
				function () use ( $expected ): void {
					$this->insert_row( 1 );
					throw $expected;
				}
			);
			$this->fail( 'The exception must propagate.' );
		} catch ( \DomainException $e ) {
			$this->assertSame( $expected, $e );
		}

		$this->assertSame( 0, $this->row_count() );
		$this->assertFalse( WpTransactionRunner::in_transaction() );
	}

	public function test_nested_runs_join_the_outer_transaction(): void {
		$runner = new WpTransactionRunner();

		try {
			$runner->run(
				function () use ( $runner ): void {
					$this->insert_row( 1 );
					$runner->run(
						function () use ( $runner ): void {
							$this->assertTrue( WpTransactionRunner::in_transaction() );
							$this->insert_row( 2 );
							$runner->run( fn() => $this->insert_row( 3 ) );
						}
					);
					$this->assertSame( 3, $this->row_count(), 'Inner runs did not commit or reset the depth.' );
					throw new \RuntimeException( 'late failure' );
				}
			);
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'late failure', $e->getMessage() );
		}

		$this->assertSame( 0, $this->row_count(), 'The outer rollback undid inner writes.' );
	}

	public function test_an_inner_failure_rolls_back_everything_of_the_outer_run(): void {
		$runner = new WpTransactionRunner();

		try {
			$runner->run(
				function () use ( $runner ): void {
					$this->insert_row( 1 );
					$runner->run(
						function (): void {
							$this->insert_row( 2 );
							throw new \RuntimeException( 'inner' );
						}
					);
				}
			);
			$this->fail( 'The inner exception must reach the caller.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'inner', $e->getMessage() );
		}

		$this->assertSame( 0, $this->row_count() );
		$this->assertFalse( WpTransactionRunner::in_transaction() );
	}

	public function test_a_non_transactional_engine_runs_the_work_without_rollback_and_warns_once(): void {
		global $wpdb;
		$this->use_real_ddl();
		$table = $wpdb->prefix . 'swg_test_myisam';
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "CREATE TABLE `{$table}` (id INT NOT NULL) ENGINE=MyISAM" ); // phpcs:ignore WordPress.DB
		$logger = new RecordingLogger();
		$runner = new WpTransactionRunner( $logger, $table );

		try {
			$runner->run(
				static function () use ( $wpdb, $table ): void {
					$wpdb->query( "INSERT INTO `{$table}` (id) VALUES (1)" ); // phpcs:ignore WordPress.DB
					throw new \RuntimeException( 'half done' );
				}
			);
			$this->fail( 'The exception must propagate.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'half done', $e->getMessage() );
		}
		$this->assertSame( 2, $runner->run( static fn(): int => 2 ) );

		$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ), 'No rollback without transactions.' ); // phpcs:ignore WordPress.DB
		$this->assertSame( array( 'swg_no_transactions' ), $logger->codes(), 'Warned once per runner.' );
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB
	}

	public function test_a_failing_start_is_reported(): void {
		$deny = static fn( string $query ): string => 'START TRANSACTION' === $query ? 'SELECT * FROM swg_missing_table_for_start' : $query;
		add_filter( 'query', $deny );
		global $wpdb;
		$wpdb->suppress_errors( true );
		try {
			( new WpTransactionRunner() )->run( static fn(): int => 1 );
			$this->fail( 'A failed START TRANSACTION must raise an exception.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'The transaction could not be started.', $e->getMessage() );
		} finally {
			$wpdb->suppress_errors( false );
			remove_filter( 'query', $deny );
		}
		$this->assertFalse( WpTransactionRunner::in_transaction() );
	}

	public function test_a_failing_commit_rolls_back_and_raises(): void {
		global $wpdb;
		$deny = static fn( string $query ): string => 'COMMIT' === $query ? 'SELECT * FROM swg_missing_table_for_commit' : $query;
		add_filter( 'query', $deny );
		$wpdb->suppress_errors( true );
		try {
			( new WpTransactionRunner() )->run( fn() => $this->insert_row( 1 ) );
			$this->fail( 'A failed COMMIT must raise an exception.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'The transaction could not be committed.', $e->getMessage() );
		} finally {
			$wpdb->suppress_errors( false );
			remove_filter( 'query', $deny );
		}
		$this->assertSame( 0, $this->row_count() );
	}
}
