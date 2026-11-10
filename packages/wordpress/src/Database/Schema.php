<?php
/**
 * Versioned schema of `{prefix}swg_gallery_items`. Tables are created only for features that need them.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Database;

final class Schema {

	public const CURRENT = 1;
	public const OPTION  = 'swg_schema_version';

	public static function items_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'swg_gallery_items';
	}

	/** Cheap check on normal requests; DDL only runs when the stored version is behind. */
	public static function maybe_migrate(): void {
		if ( (int) get_option( self::OPTION, 0 ) < self::CURRENT ) {
			self::migrate();
		}
	}

	/** Repeatable migration. */
	public static function migrate(): void {
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
		update_option( self::OPTION, self::CURRENT, false );
	}
}
