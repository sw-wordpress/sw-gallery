<?php
/**
 * Versioned migration runner: ordering, retry after failure, lock and upgrade from a v1 installation.
 *
 * @package sw-gallery
 */

use SWGallery\WordPress\Database\Migrator;
use SWGallery\WordPress\Database\Schema;
use SWGallery\WordPress\Infrastructure\Services;
use SWGallery\WordPress\Infrastructure\WpLogger;

class MigratorTest extends SwgTestCase {

	private const TEST_OPTION = 'swg_test_schema_version';

	public function tear_down(): void {
		delete_option( self::TEST_OPTION );
		delete_option( Migrator::LOCK_OPTION );
		delete_option( WpLogger::OPTION );
		Services::reset();
		parent::tear_down();
	}

	public function test_current_in_schema_matches_the_highest_step(): void {
		$this->assertSame( Schema::CURRENT, max( array_keys( Schema::steps() ) ) );
	}

	public function test_steps_run_in_version_order_and_the_version_advances(): void {
		$ran      = array();
		$migrator = new Migrator(
			array(
				3 => static function () use ( &$ran ): void {
					$ran[] = 3;
				},
				2 => static function () use ( &$ran ): void {
					$ran[] = 2;
				},
			),
			self::TEST_OPTION
		);
		$this->assertSame( 3, $migrator->target_version() );
		$this->assertFalse( $migrator->is_current() );

		$this->assertTrue( $migrator->run() );

		$this->assertSame( array( 2, 3 ), $ran );
		$this->assertSame( 3, $migrator->stored_version() );
		$this->assertTrue( $migrator->is_current() );
		$this->assertFalse( get_option( Migrator::LOCK_OPTION ), 'The lock is released.' );
	}

	public function test_running_twice_changes_nothing(): void {
		$count    = 0;
		$migrator = new Migrator(
			array(
				1 => static function () use ( &$count ): void {
					++$count;
				},
			),
			self::TEST_OPTION
		);

		$migrator->run();
		$migrator->run();

		$this->assertSame( 1, $count, 'Steps at or below the stored version are skipped.' );
		$migrator->run( true );
		$this->assertSame( 2, $count, 'Force repeats the steps.' );
	}

	public function test_an_empty_step_list_is_current(): void {
		$migrator = new Migrator( array(), self::TEST_OPTION );
		$this->assertSame( 0, $migrator->target_version() );
		$this->assertTrue( $migrator->is_current() );
	}

	public function test_a_failing_step_keeps_the_version_logs_and_is_retried_next_time(): void {
		$fail     = true;
		$ran      = array();
		$migrator = new Migrator(
			array(
				1 => static function () use ( &$ran ): void {
					$ran[] = 1;
				},
				2 => static function () use ( &$fail, &$ran ): void {
					$ran[] = 2;
					if ( $fail ) {
						throw new \RuntimeException( 'secret detail' );
					}
				},
				3 => static function () use ( &$ran ): void {
					$ran[] = 3;
				},
			),
			self::TEST_OPTION
		);

		$this->assertFalse( $migrator->run() );
		$this->assertSame( 1, $migrator->stored_version(), 'No bump for the failed step.' );
		$this->assertSame( array( 1, 2 ), $ran, 'Later steps do not run.' );
		$this->assertFalse( get_option( Migrator::LOCK_OPTION ), 'The lock is released after a failure.' );
		$entry = Services::logger()->recent()[0];
		$this->assertSame( 'swg_migration_failed', $entry['code'] );
		$this->assertSame(
			array(
				'version'   => 2,
				'exception' => \RuntimeException::class,
			),
			$entry['context']
		);

		$fail = false;
		$ran  = array();
		$this->assertTrue( $migrator->run() );
		$this->assertSame( array( 2, 3 ), $ran );
		$this->assertSame( 3, $migrator->stored_version() );
	}

	public function test_a_held_lock_blocks_a_concurrent_run_until_it_expires(): void {
		$count    = 0;
		$migrator = new Migrator(
			array(
				1 => static function () use ( &$count ): void {
					++$count;
				},
			),
			self::TEST_OPTION
		);
		add_option( Migrator::LOCK_OPTION, time() + 30, '', false );

		$this->assertFalse( $migrator->run(), 'Another request is migrating.' );
		$this->assertSame( 0, $count );
		$this->assertNotFalse( get_option( Migrator::LOCK_OPTION ), 'A foreign lock is not released.' );

		update_option( Migrator::LOCK_OPTION, time() - 1, false );
		$this->assertTrue( $migrator->run(), 'A stale lock is taken over.' );
		$this->assertSame( 1, $count );
		$this->assertFalse( get_option( Migrator::LOCK_OPTION ) );
	}

	public function test_a_run_that_waited_for_the_lock_does_not_repeat_finished_steps(): void {
		$count    = 0;
		$migrator = new Migrator(
			array(
				1 => static function () use ( &$count ): void {
					++$count;
				},
			),
			self::TEST_OPTION
		);
		// Simulate: stored version was behind when checked, but another request finished meanwhile.
		update_option( self::TEST_OPTION, 1, false );

		$this->assertTrue( $migrator->run() );
		$this->assertSame( 0, $count );
	}

	public function test_normal_requests_do_not_run_ddl_when_the_version_is_current(): void {
		update_option( Schema::OPTION, Schema::CURRENT );
		$queries = array();
		$spy     = static function ( string $query ) use ( &$queries ): string {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $spy );

		Schema::maybe_migrate();

		remove_filter( 'query', $spy );
		foreach ( $queries as $query ) {
			$this->assertDoesNotMatchRegularExpression( '/^\s*(CREATE|ALTER|DROP)\b/i', $query );
		}
		$this->assertFalse( get_option( Migrator::LOCK_OPTION ), 'No lock was taken.' );
	}

	public function test_upgrade_from_a_v1_installation_preserves_data_and_runs_the_new_step(): void {
		global $wpdb;
		$this->use_real_ddl();
		Schema::migrate();
		$table = Schema::items_table();
		$wpdb->insert(
			$table,
			array(
				'gallery_id'     => 7,
				'attachment_id'  => 70,
				'position'       => 1,
				'title_override' => '',
				'settings'       => '{"fit":"cover"}',
				'created_at'     => '2026-01-01 00:00:00',
				'updated_at'     => '2026-01-01 00:00:00',
			)
		);
		update_option( Schema::OPTION, 1, false );

		// Version 2 (test only) adds a column the way a real step would.
		$steps    = Schema::steps();
		$steps[2] = static function () use ( $wpdb, $table ): void {
			$has = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'badge' ) ); // phpcs:ignore WordPress.DB
			if ( null === $has ) {
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN badge VARCHAR(20) NULL" ); // phpcs:ignore WordPress.DB
			}
		};
		$migrator = new Migrator( $steps );

		$this->assertFalse( $migrator->is_current() );
		$this->assertTrue( $migrator->run() );
		$this->assertTrue( $migrator->run( true ), 'Repeatable.' );

		$this->assertSame( 2, (int) get_option( Schema::OPTION ) );
		$row = $wpdb->get_row( "SELECT * FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB
		$this->assertSame( '70', $row['attachment_id'] );
		$this->assertSame( '', $row['title_override'], 'The blank override survived.' );
		$this->assertSame( '{"fit":"cover"}', $row['settings'] );
		$this->assertNull( $row['badge'] );

		$wpdb->query( "ALTER TABLE `{$table}` DROP COLUMN badge" ); // phpcs:ignore WordPress.DB
		update_option( Schema::OPTION, Schema::CURRENT, false );
	}

	public function test_a_table_that_cannot_be_created_fails_the_step_and_keeps_the_version(): void {
		global $wpdb;
		$this->drop_items_table();
		delete_option( Schema::OPTION );
		$block = static fn( string $query ): string => str_starts_with( ltrim( $query ), 'CREATE TABLE' ) ? 'SELECT 1' : $query;
		add_filter( 'query', $block, 20 );
		$wpdb->suppress_errors( true );
		try {
			$this->assertFalse( Schema::migrate() );
		} finally {
			$wpdb->suppress_errors( false );
			remove_filter( 'query', $block, 20 );
		}

		$this->assertFalse( get_option( Schema::OPTION ) );
		$this->assertSame( 'swg_migration_failed', Services::logger()->recent()[0]['code'] );
		$this->assertTrue( Schema::migrate(), 'The retry succeeds.' );
	}
}
