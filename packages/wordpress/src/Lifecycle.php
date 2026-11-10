<?php
/**
 * Activation, deactivation and uninstall handling.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress;

use SWGallery\WordPress\Database\Schema;

final class Lifecycle {

	public const OPTION_DELETE_ON_UNINSTALL = 'swg_delete_data_on_uninstall';

	public static function activate(): void {
		Schema::migrate();
	}

	/** Deactivation leaves all content and tables unchanged. */
	public static function deactivate(): void {
	}

	/**
	 * Preserves everything unless the administrator explicitly opted in. Attachments are never deleted.
	 */
	public static function uninstall(): void {
		global $wpdb;
		if ( '1' !== (string) get_option( self::OPTION_DELETE_ON_UNINSTALL, '0' ) ) {
			return;
		}

		$galleries = get_posts(
			array(
				'post_type'      => PostTypes::GALLERY,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);
		foreach ( $galleries as $gallery_id ) {
			wp_delete_post( (int) $gallery_id, true );
		}
		$table = Schema::items_table();
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifier built from $wpdb->prefix and a constant.
		delete_option( Schema::OPTION );
		delete_option( self::OPTION_DELETE_ON_UNINSTALL );
	}
}
