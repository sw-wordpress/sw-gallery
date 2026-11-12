<?php
/**
 * Public PHP API of sw-gallery.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

use SWGallery\WordPress\Api\GalleryApi;

if ( ! function_exists( 'swg_render_gallery' ) ) {
	/**
	 * Renders a gallery and returns its HTML. This function does not echo.
	 *
	 * The markup comes from the same renderer and the same access policy as the block and the shortcode: a draft,
	 * private or password-protected gallery shows exactly what the shortcode would show to the current user, and an
	 * unknown or inaccessible gallery returns an empty string (editors get a short diagnostic). The returned string is
	 * escaped and validated; print it as is (`echo swg_render_gallery( 123 );`) and do not run it through further
	 * escaping functions. Frontend assets are enqueued automatically when the markup is printed on the page.
	 *
	 * Supported `$args` (everything else is ignored, invalid values are dropped):
	 * - `layout` (string): id of a registered layout.
	 * - `columns` (int 1..12) and `gap` (int 0..64): presentation overrides of the grid.
	 * - `page` (int >= 1): page to show; the request argument `swg_page_<id>` takes precedence.
	 * - `eager` (bool, default true): set to false to lazy-load every image (for galleries far below the fold).
	 *
	 * @since 0.1.0
	 *
	 * @param int                  $gallery_id Gallery id (positive integer).
	 * @param array<string, mixed> $args       Optional presentation overrides, see above.
	 * @return string Escaped gallery HTML, or an empty string when nothing may be shown.
	 */
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- the `swg_` function name is the public contract (SPECIFICATION 7); the sniff only knows the longer plugin prefix.
	function swg_render_gallery( int $gallery_id, array $args = array() ): string {
		return GalleryApi::render( $gallery_id, $args );
	}
}
