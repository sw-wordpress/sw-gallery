<?php
/**
 * M5-05: upgrade from an older table layout, interrupted migrations, the downgrade guard (stored schema newer than the
 * code), prefixes, concurrent activation, new multisite sites and safe logging.
 *
 * HOW THE NEXT REAL MIGRATION IS ADDED (the schema has one version so far): append `N => array( self::class, 'step_name' )`
 * to `Schema::steps()`, raise `Schema::CURRENT` to N, make the step repeatable (check `SHOW COLUMNS`/`SHOW INDEX` before
 * `ALTER`, or use dbDelta), then copy `test_a_legacy_layout_is_upgraded_in_place` for the previous layout and add the
 * layout to `legacy_table()`. `MigratorTest::test_upgrade_from_a_v1_installation_...` shows the synthetic form.
 *
 * @package sw-gallery
 */

use SWGallery\Tests\Support\RestFixtures;
use SWGallery\WordPress\Database\Migrator;
use SWGallery\WordPress\Database\Schema;
use SWGallery\WordPress\Database\SchemaRegistrar;
use SWGallery\WordPress\Infrastructure\Services;
use SWGallery\WordPress\Infrastructure\WpLogger;
use SWGallery\WordPress\Lifecycle;
use SWGallery\WordPress\Repository\WpdbGalleryItemRepository;

class MigrationUpgradeTest extends SwgTestCase {

	use RestFixtures;

	/** @var list<int> */
	private array $blogs = array();

	public function set_up(): void {
		parent::set_up();
		$this->bootRest();
		Services::logger()->clear();
	}

	public function tear_down(): void {
		foreach ( $this->blogs as $blog ) {
			wp_delete_site( $blog );
		}
		$this->blogs = array();
		update_option( Schema::OPTION, Schema::CURRENT, false );
		delete_option( Migrator::LOCK_OPTION );
		delete_site_option( 'active_sitewide_plugins' );
		Services::logger()->clear();
		$this->shutdownRest();
		parent::tear_down();
	}

	/**
	 * The layout of a hypothetical earlier release: no override and settings columns, only the primary key.
	 *
	 * @return array<int, array<string, mixed>> The rows it holds.
	 */
	private function legacy_table(): array {
		global $wpdb;
		$this->drop_items_table();
		$table = Schema::items_table();
		$wpdb->query( "CREATE TABLE `{$table}` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, gallery_id BIGINT UNSIGNED NOT NULL, attachment_id BIGINT UNSIGNED NOT NULL, position BIGINT UNSIGNED NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY  (id)) ENGINE=InnoDB" ); // phpcs:ignore WordPress.DB
		$rows = array();
		foreach ( array( array( 5, 501, 1 ), array( 5, 502, 2 ), array( 6, 501, 1 ) ) as [ $gallery, $attachment, $position ] ) {
			$wpdb->insert(
				$table,
				array(
					'gallery_id'    => $gallery,
					'attachment_id' => $attachment,
					'position'      => $position,
					'created_at'    => '2025-01-02 03:04:05',
					'updated_at'    => '2025-01-02 03:04:05',
				)
			);
			$rows[] = array( $gallery, $attachment, $position );
		}
		delete_option( Schema::OPTION );
		return $rows;
	}

	/** @return list<string> */
	private function columns(): array {
		global $wpdb;
		return array_map( 'strval', (array) $wpdb->get_col( 'SHOW COLUMNS FROM `' . Schema::items_table() . '`' ) ); // phpcs:ignore WordPress.DB
	}

	public function test_a_legacy_layout_is_upgraded_in_place(): void {
		global $wpdb;
		$rows  = $this->legacy_table();
		$table = Schema::items_table();
		$this->assertNotContains( 'settings', $this->columns() );

		Schema::maybe_migrate();

		$this->assertSame( Schema::CURRENT, (int) get_option( Schema::OPTION ) );
		$this->assertEqualsCanonicalizing( array( 'id', 'gallery_id', 'attachment_id', 'position', 'created_at', 'updated_at', 'title_override', 'caption_override', 'alt_override', 'settings' ), $this->columns() );
		$names = array_unique( array_map( static fn( array $i ): string => (string) $i['Key_name'], (array) $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A ) ) ); // phpcs:ignore WordPress.DB
		sort( $names );
		$this->assertSame( array( 'PRIMARY', 'attachment_id', 'gallery_attachment', 'gallery_position' ), $names );
		$stored = array_map( static fn( array $r ): array => array( (int) $r['gallery_id'], (int) $r['attachment_id'], (int) $r['position'] ), (array) $wpdb->get_results( "SELECT gallery_id, attachment_id, position FROM `{$table}` ORDER BY id", ARRAY_A ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( $rows, $stored, 'No row lost or changed.' );
		$this->assertNull( $wpdb->get_var( "SELECT settings FROM `{$table}` LIMIT 1" ), 'New columns are NULL = inherit.' ); // phpcs:ignore WordPress.DB
		$this->assertTrue( Schema::migrate(), 'A second run changes nothing.' );
		$this->assertSame( 3, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ) ); // phpcs:ignore WordPress.DB
	}

	public function test_an_interrupted_migration_is_not_reported_done_and_completes_on_retry(): void {
		global $wpdb;
		$this->use_real_ddl();
		Schema::migrate();
		$table = Schema::items_table();
		$wpdb->insert(
			$table,
			array(
				'gallery_id'    => 9,
				'attachment_id' => 90,
				'position'      => 1,
				'created_at'    => '2025-01-02 03:04:05',
				'updated_at'    => '2025-01-02 03:04:05',
			)
		);
		$option = 'swg_test_interrupted_version';
		$fail   = true;
		$add    = static function ( string $column ) use ( $wpdb, $table ): void {
			if ( null === $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column ) ) ) { // phpcs:ignore WordPress.DB
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN {$column} VARCHAR(10) NULL" ); // phpcs:ignore WordPress.DB
			}
		};
		$steps    = array(
			1 => static function (): void {},
			2 => static function () use ( $add, &$fail ): void {
				$add( 'half_a' );
				if ( $fail ) {
					throw new \RuntimeException( 'Crash in ' . ABSPATH . 'secret/path.php' );
				}
				$add( 'half_b' );
			},
		);
		$migrator = new Migrator( $steps, $option );
		try {
			$this->assertFalse( $migrator->run() );
			$this->assertSame( 1, $migrator->stored_version(), 'The interrupted step is not recorded as done.' );
			$this->assertFalse( $migrator->is_current() );
			$this->assertContains( 'half_a', $this->columns(), 'The partial work is there ...' );
			$this->assertNotContains( 'half_b', $this->columns() );
			$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ) ); // phpcs:ignore WordPress.DB
			$this->assertFalse( get_option( Migrator::LOCK_OPTION ), 'The lock is released.' );

			$fail = false;
			$this->assertTrue( $migrator->run(), '... and the repeatable step completes it without an error.' );
			$this->assertSame( 2, $migrator->stored_version() );
			$this->assertContains( 'half_b', $this->columns() );
			$this->assertSame( '90', $wpdb->get_var( "SELECT attachment_id FROM `{$table}`" ) ); // phpcs:ignore WordPress.DB
		} finally {
			foreach ( array( 'half_a', 'half_b' ) as $column ) {
				if ( in_array( $column, $this->columns(), true ) ) {
					$wpdb->query( "ALTER TABLE `{$table}` DROP COLUMN {$column}" ); // phpcs:ignore WordPress.DB
				}
			}
			delete_option( $option );
		}
	}

	public function test_failed_migrations_log_only_the_version_and_the_exception_class(): void {
		$migrator = new Migrator(
			array(
				1 => static function (): void {
					throw new \RuntimeException( 'Cannot write ' . ABSPATH . 'wp-content/uploads/x.sql password=hunter2' );
				},
			),
			'swg_test_log_version'
		);
		$this->assertFalse( $migrator->run() );

		$log = (string) wp_json_encode( Services::logger()->recent() );
		$this->assertStringContainsString( 'swg_migration_failed', $log );
		$this->assertStringNotContainsString( 'hunter2', $log );
		$this->assertStringNotContainsString( 'wp-content', $log );
		$this->assertStringNotContainsString( rtrim( ABSPATH, '/' ), $log );
		delete_option( 'swg_test_log_version' );
	}

	public function test_a_schema_newer_than_the_code_is_not_migrated_and_not_altered(): void {
		global $wpdb;
		$this->use_real_ddl();
		Schema::migrate();
		$table = Schema::items_table();
		update_option( Schema::OPTION, Schema::CURRENT + 1, false );
		$columns = $this->columns();
		$ddl     = array();
		$spy     = static function ( string $query ) use ( &$ddl ): string {
			if ( 1 === preg_match( '/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i', $query ) ) {
				$ddl[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $spy );
		try {
			Schema::maybe_migrate();
			$this->assertFalse( Schema::migrate(), 'A forced run (activation, repair) refuses as well.' );
			Lifecycle::activate();
		} finally {
			remove_filter( 'query', $spy );
		}

		$this->assertSame( array(), $ddl, 'No DDL ran against the newer schema.' );
		$this->assertSame( $columns, $this->columns() );
		$this->assertSame( Schema::CURRENT + 1, (int) get_option( Schema::OPTION ), 'The newer version marker is never lowered.' );
		$this->assertSame( 'swg_schema_newer', Services::logger()->recent()[0]['code'] );
		$this->assertTrue( Schema::newer_than_code() );
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ) ); // phpcs:ignore WordPress.DB
		update_option( Schema::OPTION, Schema::CURRENT, false );
	}

	public function test_writes_are_refused_with_503_while_reads_keep_working_when_the_schema_is_newer(): void {
		$admin   = $this->makeUser( 'administrator' );
		$gallery = $this->makeGallery( $admin );
		$images  = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$images[] = $this->makeAttachment( $admin );
		}
		$revision = Services::revisions()->current( $gallery )->value;
		$ok       = $this->call( 'POST', '/galleries/' . $gallery . '/items', array( 'attachmentIds' => array( $images[0] ), 'expectedRevision' => $revision ), $admin );
		$this->assertSame( 200, $ok->get_status() );
		$revision = Services::revisions()->current( $gallery )->value;

		update_option( Schema::OPTION, Schema::CURRENT + 1, false );
		try {
			$write = $this->call( 'POST', '/galleries/' . $gallery . '/items', array( 'attachmentIds' => array( $images[1] ), 'expectedRevision' => $revision ), $admin );
			$this->assertSame( 503, $write->get_status() );
			$this->assertSame( 'swg_schema_newer', $write->get_data()['code'] );
			$this->assertStringNotContainsString( ABSPATH, (string) wp_json_encode( $write->get_data() ) );
			$patch = $this->call( 'PATCH', '/galleries/' . $gallery, array( 'title' => 'Changed', 'expectedRevision' => $revision ), $admin );
			$this->assertSame( 503, $patch->get_status() );
			$order = $this->call( 'PUT', '/galleries/' . $gallery . '/order', array( 'itemIds' => Services::items()->orderedIds( $gallery ), 'expectedRevision' => $revision ), $admin );
			$this->assertSame( 503, $order->get_status() );

			$this->assertSame( 1, Services::items()->count( $gallery ), 'Nothing was written.' );
			$this->assertSame( $revision, Services::revisions()->current( $gallery )->value, 'The revision did not move.' );
			$read = $this->call( 'GET', '/galleries/' . $gallery . '/items', array(), $admin );
			$this->assertSame( 200, $read->get_status() );
			$this->assertCount( 1, $read->get_data() );

			$status = $this->call( 'GET', '/status', array(), $admin );
			$this->assertSame( 200, $status->get_status() );
			$schema = $status->get_data()['schema'];
			$this->assertTrue( $schema['newerThanCode'] );
			$this->assertFalse( $schema['migrationPending'] );
			$this->assertSame( Schema::CURRENT + 1, $schema['stored'] );
		} finally {
			update_option( Schema::OPTION, Schema::CURRENT, false );
		}
		$after = $this->call( 'POST', '/galleries/' . $gallery . '/items', array( 'attachmentIds' => array( $images[1] ), 'expectedRevision' => $revision ), $admin );
		$this->assertSame( 200, $after->get_status(), 'Writing works again once the code is current.' );
	}

	public function test_a_custom_table_prefix_gets_its_own_table_and_version(): void {
		global $wpdb;
		$this->use_real_ddl();
		$old = $wpdb->prefix;
		// Only the prefix of the item table changes (set_prefix() would also re-point the options table).
		$wpdb->prefix = 'swgx_';
		$table        = Schema::items_table();
		try {
			$this->assertSame( 'swgx_swg_gallery_items', $table );
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB
			$this->assertTrue( Schema::migrate() );
			$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ); // phpcs:ignore WordPress.DB
		} finally {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB
			$wpdb->prefix = $old;
		}
		$this->assertTrue( $this->items_table_exists(), 'The real table of the install is untouched.' );
	}

	public function test_a_held_lock_defers_the_activation_migration_without_error(): void {
		$this->use_real_ddl();
		$this->drop_items_table();
		delete_option( Schema::OPTION );
		add_option( Migrator::LOCK_OPTION, time() + 60, '', false );

		$this->assertFalse( Schema::migrate(), 'A concurrent run backs off.' );
		Lifecycle::activate();
		$this->assertFalse( $this->items_table_exists(), 'The lock holder owns the DDL.' );
		$this->assertFalse( get_option( Schema::OPTION ) );

		delete_option( Migrator::LOCK_OPTION );
		$this->assertTrue( Schema::migrate() );
		$this->assertTrue( $this->items_table_exists() );
	}

	public function test_a_site_created_while_the_plugin_is_network_active_gets_its_table_and_version(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		global $wpdb;
		$this->use_real_ddl();
		( new SchemaRegistrar() )->register();
		update_site_option( 'active_sitewide_plugins', array( plugin_basename( SWGALLERY_FILE ) => time() ) );

		$blog          = self::factory()->blog->create();
		$this->blogs[] = $blog;

		$table = $wpdb->get_blog_prefix( $blog ) . 'swg_gallery_items';
		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), 'wp_initialize_site created the table.' ); // phpcs:ignore WordPress.DB
		$this->assertNotSame( Schema::items_table(), $table );
		switch_to_blog( $blog );
		$this->assertSame( Schema::CURRENT, (int) get_option( Schema::OPTION ) );
		$this->assertSame( $table, Schema::items_table() );
		restore_current_blog();
	}

	public function test_migrating_one_site_never_touches_the_rows_of_another(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		global $wpdb;
		$this->use_real_ddl();
		Schema::migrate();
		$repository = new WpdbGalleryItemRepository();
		$repository->append( 3, 30 );
		$blog          = self::factory()->blog->create();
		$this->blogs[] = $blog;

		switch_to_blog( $blog );
		$wpdb->query( 'DROP TABLE IF EXISTS `' . Schema::items_table() . '`' ); // phpcs:ignore WordPress.DB
		delete_option( Schema::OPTION );
		$this->assertTrue( Schema::migrate() );
		( new WpdbGalleryItemRepository() )->append( 4, 40 );
		restore_current_blog();

		$this->assertSame( 1, $repository->count( 3 ), 'Own rows stay.' );
		$this->assertSame( 0, $repository->count( 4 ), 'Foreign rows are not visible.' );
	}
}
