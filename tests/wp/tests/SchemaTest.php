<?php
/**
 * Schema migration against a real database.
 *
 * @package sw-gallery
 */

use SWGallery\WordPress\Database\Schema;

class SchemaTest extends SwgTestCase {

	public function test_items_table_uses_the_site_prefix(): void {
		global $wpdb;
		$this->assertSame( $wpdb->prefix . 'swg_gallery_items', Schema::items_table() );
	}

	public function test_migrate_creates_the_table_with_columns_and_indexes(): void {
		global $wpdb;
		$this->drop_items_table();
		$this->assertFalse( $this->items_table_exists() );

		Schema::migrate();

		$this->assertTrue( $this->items_table_exists() );
		$table   = Schema::items_table();
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" ); // phpcs:ignore WordPress.DB
		$this->assertSame(
			array( 'id', 'gallery_id', 'attachment_id', 'position', 'title_override', 'caption_override', 'alt_override', 'settings', 'created_at', 'updated_at' ),
			$columns
		);
		$indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB
		$unique  = array();
		foreach ( $indexes as $index ) {
			if ( '0' === (string) $index['Non_unique'] ) {
				$unique[ $index['Key_name'] ][] = $index['Column_name'];
			}
		}
		$this->assertSame( array( 'gallery_id', 'attachment_id' ), $unique['gallery_attachment'] );
		$this->assertSame( Schema::CURRENT, (int) get_option( Schema::OPTION ) );
	}

	public function test_migrate_is_idempotent_and_keeps_rows(): void {
		global $wpdb;
		$this->use_real_ddl();
		Schema::migrate();
		$table = Schema::items_table();
		$wpdb->insert(
			$table,
			array(
				'gallery_id'    => 1,
				'attachment_id' => 2,
				'position'      => 1,
				'created_at'    => '2026-01-01 00:00:00',
				'updated_at'    => '2026-01-01 00:00:00',
			)
		);
		$before = $wpdb->get_results( "SHOW CREATE TABLE `{$table}`", ARRAY_N ); // phpcs:ignore WordPress.DB

		Schema::migrate();
		Schema::migrate();

		$this->assertSame( $before, $wpdb->get_results( "SHOW CREATE TABLE `{$table}`", ARRAY_N ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ) ); // phpcs:ignore WordPress.DB
	}

	public function test_maybe_migrate_runs_only_when_the_stored_version_is_behind(): void {
		$this->drop_items_table();

		update_option( Schema::OPTION, Schema::CURRENT );
		Schema::maybe_migrate();
		$this->assertFalse( $this->items_table_exists(), 'Current schema version: no DDL.' );

		update_option( Schema::OPTION, 0 );
		Schema::maybe_migrate();
		$this->assertTrue( $this->items_table_exists() );
		$this->assertSame( Schema::CURRENT, (int) get_option( Schema::OPTION ) );

		delete_option( Schema::OPTION );
		$this->drop_items_table();
		Schema::maybe_migrate();
		$this->assertTrue( $this->items_table_exists(), 'Missing version option migrates.' );
	}
}
