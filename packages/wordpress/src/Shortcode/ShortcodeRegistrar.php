<?php
/**
 * Registers the gallery shortcode.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Shortcode;

use SWGallery\WordPress\Bootstrap\RegistrarInterface;
use SWGallery\WordPress\Infrastructure\Services;

final class ShortcodeRegistrar implements RegistrarInterface {

	public function register(): void {
		add_shortcode( GalleryShortcode::TAG, array( $this, 'render' ) );
	}

	/**
	 * Resolves the handler per call so it always shares the request's services.
	 *
	 * @param array<mixed>|string $atts Raw attributes.
	 */
	public function render( array|string $atts = array() ): string {
		return ( new GalleryShortcode( Services::embed_renderer(), Services::embed_arguments() ) )->render( $atts );
	}
}
