<?php
/**
 * Allowlist for caption markup.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Rendering\View;

/**
 * Captions come from attachment metadata or gallery overrides (untrusted). Interactive markup keeps a tiny inline
 * allowlist (links, emphasis, line breaks); simplified markup (feeds, excerpts) reduces captions to plain text.
 */
final class CaptionPolicy {

	/**
	 * The `wp_kses` allowlist of interactive captions: no attributes except a link's `href`/`rel`, no block elements.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed_html(): array {
		return array(
			'a'      => array(
				'href' => true,
				'rel'  => true,
			),
			'em'     => array(),
			'strong' => array(),
			'br'     => array(),
		);
	}

	/** Caption for the interactive markup (escaped inline HTML). */
	public static function html( string $caption ): string {
		return wp_kses( $caption, self::allowed_html() );
	}

	/** Caption for the simplified markup (plain text, escaped). */
	public static function text( string $caption ): string {
		return esc_html( wp_strip_all_tags( $caption ) );
	}
}
