<?php
/**
 * The `[sw_gallery id="123"]` shortcode.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Shortcode;

use SWGallery\Core\Application\Render\EntryPoint;
use SWGallery\WordPress\Frontend\EmbedArguments;
use SWGallery\WordPress\Frontend\EmbedRenderer;

/**
 * Strict attribute handling: `id` (required positive integer) and the allowlisted presentation overrides of
 * {@see EmbedArguments} (`layout`, `columns`, `gap`, `page`). Unknown attributes are ignored, invalid values are
 * dropped and never echoed. The handler returns its markup (never echoes), so it nests safely in `do_shortcode()`,
 * block content and widgets. Access and markup come from the one renderer.
 */
final class GalleryShortcode {

	public const TAG = 'sw_gallery';

	/** Classes of the shortcode wrapper (see wrap()). */
	public const WRAPPER_CLASSES = 'swg-shortcode is-layout-constrained';

	private const ID_PATTERN = '/^[1-9]\d{0,9}$/';

	public function __construct(
		private readonly EmbedRenderer $embeds,
		private readonly EmbedArguments $arguments
	) {
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array<mixed>|string $atts Raw attributes (a string when none were given).
	 */
	public function render( array|string $atts = array() ): string {
		$atts = is_array( $atts ) ? $atts : array();
		$id   = $this->gallery_id( $atts['id'] ?? null );
		if ( null === $id ) {
			return $this->invalid_id_notice();
		}
		// Feeds get the plain, dependency free markup instead of the interactive gallery.
		$feed = is_feed();
		$html = $this->embeds->render( $id, $feed ? EntryPoint::Feed : EntryPoint::Shortcode, $this->arguments->options( $id, $atts ) );
		return $feed || '' === $html ? $html : $this->wrap( $html );
	}

	/**
	 * FU-23: the one deliberate difference between the surfaces. Block themes constrain direct children of a
	 * `is-layout-constrained` container to the theme's content width (core's own layout rule); classic themes do not
	 * define the class, so the wrapper is inert there. To change the width, set core's custom property
	 * `--wp--style--global--content-size` on `.swg-shortcode` (docs/rendering.md). The block has its own wrapper
	 * (alignment support) and is not wrapped again.
	 */
	private function wrap( string $html ): string {
		return '<div class="' . self::WRAPPER_CLASSES . '">' . $html . '</div>';
	}

	/**
	 * @param mixed $value Untrusted `id` attribute.
	 */
	private function gallery_id( mixed $value ): ?int {
		$value = is_string( $value ) ? trim( $value ) : $value;
		if ( is_string( $value ) && 1 === preg_match( self::ID_PATTERN, $value ) ) {
			return (int) $value;
		}
		return null;
	}

	/** Visitors see nothing; users who can edit posts learn what is wrong (the value is never echoed). */
	private function invalid_id_notice(): string {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}
		return sprintf(
			'<p class="swg-diagnostic" role="note">%s</p>',
			esc_html__( 'Gallery diagnostic (visible to editors only): the shortcode needs a valid gallery id, for example [sw_gallery id="123"].', 'sw-gallery' )
		);
	}
}
