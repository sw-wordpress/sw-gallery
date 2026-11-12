<?php
/**
 * Activation, deactivation and uninstall handling.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress;

use SWGallery\WordPress\Database\Migrator;
use SWGallery\WordPress\Database\Schema;
use SWGallery\WordPress\Infrastructure\WpLogger;
use SWGallery\WordPress\Rendering\MediaEpoch;
use SWGallery\WordPress\Settings\PluginSettings;

final class Lifecycle {

	public const OPTION_DELETE_ON_UNINSTALL = 'swg_delete_data_on_uninstall';

	/** Galleries deleted per query during uninstall, and sites handled per query on multisite. */
	public const BATCH_SIZE = 100;

	/** @param bool $network_wide Whether the plugin was activated for the whole network (multisite). */
	public static function activate( bool $network_wide = false ): void {
		if ( $network_wide && is_multisite() ) {
			self::for_each_site( array( Schema::class, 'migrate' ) );
			return;
		}
		Schema::migrate();
	}

	/** Deactivation leaves all content and tables unchanged. */
	public static function deactivate(): void {
	}

	/**
	 * Preserves everything unless the administrator explicitly opted in (per site on multisite).
	 * Attachments are never deleted.
	 */
	public static function uninstall(): void {
		if ( is_multisite() ) {
			self::for_each_site( array( self::class, 'uninstall_site' ) );
			return;
		}
		self::uninstall_site();
	}

	/** Removes the data of the current site when its administrator opted in. */
	public static function uninstall_site(): void {
		global $wpdb;
		if ( '1' !== (string) get_option( self::OPTION_DELETE_ON_UNINSTALL, '0' ) ) {
			return;
		}

		self::delete_galleries();

		$table = Schema::items_table();
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- identifier built from $wpdb->prefix and a constant.

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table name.
				$wpdb->esc_like( '_transient_swg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_swg_' ) . '%'
			)
		);
		foreach ( array( Schema::OPTION, Migrator::LOCK_OPTION, WpLogger::OPTION, PluginSettings::OPTION, MediaEpoch::OPTION, self::OPTION_DELETE_ON_UNINSTALL ) as $option ) {
			delete_option( $option );
		}
	}

	/** Deletes galleries in bounded batches; stops when a batch makes no progress. */
	private static function delete_galleries(): void {
		$previous = array();
		$full     = false;
		do {
			$ids = get_posts(
				array(
					'post_type'      => PostTypes::GALLERY,
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => self::BATCH_SIZE,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'no_found_rows'  => true,
				)
			);
			if ( array() === $ids || $ids === $previous ) {
				return;
			}
			foreach ( $ids as $gallery_id ) {
				wp_delete_post( (int) $gallery_id, true );
			}
			$previous = $ids;
			$full     = count( $ids ) >= self::BATCH_SIZE;
		} while ( $full );
	}

	/**
	 * Runs a callback in the context of every site of the network, in bounded batches.
	 *
	 * @param callable():mixed $callback Called with the site switched.
	 */
	private static function for_each_site( callable $callback ): void {
		$offset = 0;
		$full   = false;
		do {
			$sites = get_sites(
				array(
					'fields'  => 'ids',
					'number'  => self::BATCH_SIZE,
					'offset'  => $offset,
					'orderby' => 'id',
					'order'   => 'ASC',
				)
			);
			foreach ( $sites as $site_id ) {
				switch_to_blog( (int) $site_id );
				try {
					$callback();
				} finally {
					restore_current_blog();
				}
			}
			$offset += self::BATCH_SIZE;
			$full    = count( $sites ) >= self::BATCH_SIZE;
		} while ( $full );
	}
}
