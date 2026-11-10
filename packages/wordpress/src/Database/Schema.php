<?php
/**
 * Versioned schema of `{prefix}swg_gallery_items`. Tables are created only for features that need them.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Database;

final class Schema {

	/** Highest step version of steps(); a test keeps both in sync. */
	public const CURRENT = 1;
	public const OPTION  = 'swg_schema_version';

	public static function items_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'swg_gallery_items';
	}

	/** Cheap check on normal requests; DDL only runs when the stored version is behind. */
	public static function maybe_migrate(): void {
		$migrator = self::migrator();
		if ( ! $migrator->is_current() ) {
			$migrator->run();
		}
	}

	/**
	 * Repeatable migration of the current site: runs every step again (activation, new site, repair).
	 *
	 * @return bool Whether the schema is at the current version afterwards.
	 */
	public static function migrate(): bool {
		return self::migrator()->run( true );
	}

	/**
	 * Ordered steps; later versions append here. Every step must be repeatable.
	 *
	 * @return array<int, callable():void> Target version => step.
	 */
	public static function steps(): array {
		return array(
			1 => array( self::class, 'create_items_table' ),
		);
	}

	private static function migrator(): Migrator {
		return new Migrator( self::steps() );
	}

	/**
	 * Step 1: the item table (dbDelta is repeatable).
	 *
	 * @throws \RuntimeException When the table is not usable afterwards.
	 */
	public static function create_items_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::items_table();
		$charset = $wpdb->get_charset_collate();
		// dbDelta format: two spaces after PRIMARY KEY, one definition per line.
		$sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  gallery_id BIGINT UNSIGNED NOT NULL,
  attachment_id BIGINT UNSIGNED NOT NULL,
  position BIGINT UNSIGNED NOT NULL DEFAULT 0,
  title_override TEXT NULL,
  caption_override TEXT NULL,
  alt_override TEXT NULL,
  settings LONGTEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY gallery_attachment (gallery_id,attachment_id),
  KEY gallery_position (gallery_id,position,id),
  KEY attachment_id (attachment_id)
) {$charset};";

		dbDelta( $sql );

		// dbDelta reports nothing: verify the table is usable so a failure does not advance the version.
		$suppress = $wpdb->suppress_errors( true );
		$usable   = false !== $wpdb->query( "SELECT 1 FROM `{$table}` LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifier from prefix and constant.
		$wpdb->suppress_errors( $suppress );
		if ( ! $usable ) {
			throw new \RuntimeException( 'The item table could not be created.' );
		}
	}
}
