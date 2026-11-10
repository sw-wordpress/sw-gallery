<?php
/**
 * Plugin bootstrap: registers hooks through dedicated registrars.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress;

use SWGallery\WordPress\Database\Schema;

final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		return self::$instance ??= new self();
	}

	public function boot(): void {
		add_action( 'init', array( PostTypes::class, 'register' ) );
		add_action( 'plugins_loaded', array( Schema::class, 'maybe_migrate' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'sw-gallery', false, dirname( plugin_basename( SWGALLERY_FILE ) ) . '/languages' );
	}
}
