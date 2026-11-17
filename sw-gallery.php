<?php
/**
 * Plugin Name:       SW Gallery
 * Update URI:        https://github.com/sw-wordpress/sw-gallery
 * Plugin URI:        https://github.com/sw-wordpress/sw-gallery
 * Description:       Gallery management and presentation on top of the WordPress Media Library.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Stefan Warnat
 * Author URI:        https://stefanwarnat.de
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sw-gallery
 * Domain Path:       /languages
 *
 * @package sw-gallery
 */

defined( 'ABSPATH' ) || exit;

define( 'SWGALLERY_VERSION', '0.1.0' );
define( 'SWGALLERY_FILE', __FILE__ );
define( 'SWGALLERY_DIR', plugin_dir_path( __FILE__ ) );
define( 'SWGALLERY_URL', plugin_dir_url( __FILE__ ) );
define( 'SWGALLERY_MIN_PHP', '8.2' );

if ( version_compare( PHP_VERSION, SWGALLERY_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version */
						__( 'sw-gallery requires PHP %1$s or newer. You are running PHP %2$s.', 'sw-gallery' ),
						SWGALLERY_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
	);
	return;
}

// SWGALLERY_AUTOLOADER may be defined by the test environment.
$swgallery_autoload = defined( 'SWGALLERY_AUTOLOADER' ) ? SWGALLERY_AUTOLOADER : SWGALLERY_DIR . 'vendor/autoload.php';
if ( ! is_readable( $swgallery_autoload ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'sw-gallery: the vendor directory is missing. Install the release package or run "composer install".', 'sw-gallery' )
			);
		}
	);
	return;
}
$swgallery_loader = require $swgallery_autoload;
$swgallery_loader->addPsr4( 'SWGallery\\Core\\', SWGALLERY_DIR . 'packages/core/src/' );
$swgallery_loader->addPsr4( 'SWGallery\\WordPress\\', SWGALLERY_DIR . 'packages/wordpress/src/' );
unset( $swgallery_autoload, $swgallery_loader );

register_activation_hook( __FILE__, array( \SWGallery\WordPress\Lifecycle::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \SWGallery\WordPress\Lifecycle::class, 'deactivate' ) );

\SWGallery\WordPress\Plugin::instance()->boot();
