<?php
/**
 * Creates the schema of sites that are added to a multisite network.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Database;

use SWGallery\WordPress\Bootstrap\RegistrarInterface;

final class SchemaRegistrar implements RegistrarInterface {

	public function register(): void {
		add_action( 'wp_initialize_site', array( $this, 'initialize_site' ), 20 );
		add_filter( 'wpmu_drop_tables', array( $this, 'drop_site_tables' ), 10, 2 );
	}

	/**
	 * A deleted site takes its own item table with it (the site and its galleries no longer exist).
	 *
	 * @param array<int|string, string> $tables  Tables WordPress drops.
	 * @param int                       $site_id Site being deleted.
	 * @return array<int|string, string>
	 */
	public function drop_site_tables( array $tables, int $site_id ): array {
		global $wpdb;
		$tables[] = $wpdb->get_blog_prefix( $site_id ) . 'swg_gallery_items';
		return $tables;
	}

	/**
	 * Only when the plugin is active for the network; each site gets its own prefixed table.
	 *
	 * @param \WP_Site $site New site.
	 */
	public function initialize_site( \WP_Site $site ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active_for_network( plugin_basename( SWGALLERY_FILE ) ) ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		try {
			Schema::migrate();
		} finally {
			restore_current_blog();
		}
	}
}
