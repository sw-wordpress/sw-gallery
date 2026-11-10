<?php
/**
 * Hooks of the plugin foundation: post types, schema migration, translations.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Bootstrap;

use SWGallery\WordPress\Database\Schema;
use SWGallery\WordPress\PostTypes;

final class CoreRegistrar implements RegistrarInterface {

	public function register(): void {
		add_action( 'init', array( PostTypes::class, 'register' ) );
		add_action( 'plugins_loaded', array( Schema::class, 'maybe_migrate' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'sw-gallery', false, dirname( plugin_basename( SWGALLERY_FILE ) ) . '/languages' );
	}
}
