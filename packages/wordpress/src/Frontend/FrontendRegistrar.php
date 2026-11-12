<?php
/**
 * Frontend hooks: asset enqueue and admin bar.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Frontend;

use SWGallery\WordPress\Bootstrap\RegistrarInterface;
use SWGallery\WordPress\Infrastructure\Services;

/**
 * The callbacks resolve their collaborators from the service holder when they run, so they always share the
 * request's collector with the output surfaces.
 */
final class FrontendRegistrar implements RegistrarInterface {

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ), 5 );
		add_action( 'wp_print_footer_scripts', array( $this, 'enqueue_collected' ), 1 );
		add_action( 'wp_footer', array( $this, 'print_build_notice' ), 5 );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 90 );
	}

	public function enqueue_scripts(): void {
		Services::frontend_assets()->on_enqueue_scripts();
	}

	public function enqueue_collected(): void {
		Services::frontend_assets()->enqueue_collected();
	}

	public function print_build_notice(): void {
		Services::frontend_assets()->print_build_notice();
	}

	/**
	 * @param \WP_Admin_Bar $bar The admin bar.
	 */
	public function admin_bar( \WP_Admin_Bar $bar ): void {
		( new AdminBar( Services::asset_collector(), Services::galleries(), Services::access() ) )->add_nodes( $bar );
	}
}
