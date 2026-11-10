<?php
/**
 * Base class of the WordPress integration tests: WP_UnitTestCase when the test database is reachable, otherwise a
 * test case that skips every test.
 *
 * @package sw-gallery
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile, Universal.Files.SeparateFunctionsFromOO

if ( SWG_WP_DB_AVAILABLE ) {
	abstract class SwgTestCase extends WP_UnitTestCase {

		public function set_up(): void {
			parent::set_up();
			// The test case unregisters meta keys between tests; register them like the `init` hook does in production.
			if ( ! registered_meta_key_exists( 'post', \SWGallery\WordPress\PostTypes::META_REV, \SWGallery\WordPress\PostTypes::GALLERY ) ) {
				unregister_post_type( \SWGallery\WordPress\PostTypes::GALLERY );
				\SWGallery\WordPress\PostTypes::register();
			}
		}

		public function tear_down(): void {
			$this->use_real_ddl();
			\SWGallery\WordPress\Database\Schema::migrate();
			global $wpdb;
			$table = \SWGallery\WordPress\Database\Schema::items_table();
			$wpdb->query( "TRUNCATE TABLE `{$table}`" ); // phpcs:ignore WordPress.DB
			parent::tear_down();
		}

		/**
		 * The WordPress test case turns CREATE/DROP TABLE into temporary-table statements. Tests that verify real DDL
		 * switch that off; DDL commits implicitly, so tear_down() restores the table and removes its rows.
		 */
		protected function use_real_ddl(): void {
			remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
			remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}

		protected function items_table_exists(): bool {
			global $wpdb;
			$table = \SWGallery\WordPress\Database\Schema::items_table();
			return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB
		}

		protected function drop_items_table(): void {
			global $wpdb;
			$this->use_real_ddl();
			$table = \SWGallery\WordPress\Database\Schema::items_table();
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB
		}
	}
} else {
	abstract class SwgTestCase extends \PHPUnit\Framework\TestCase {

		protected function setUp(): void {
			parent::setUp();
			self::markTestSkipped( 'Test database unreachable: start it with bin/test-db.sh start.' );
		}
	}
}
