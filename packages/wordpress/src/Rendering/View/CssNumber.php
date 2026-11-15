<?php
/**
 * Locale independent decimals for custom properties.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Rendering\View;

/**
 * Only bounded finite floats go through here; the result matches `-?\d+(\.\d+)?` and never depends on the locale.
 */
final class CssNumber {

	/**
	 * Decimal with at most `$decimals` decimals and no trailing zeros; non-finite input is 0.
	 *
	 * @param float $value    Number.
	 * @param int   $decimals Decimals (0..6).
	 */
	public static function format( float $value, int $decimals = 3 ): string {
		if ( ! is_finite( $value ) ) {
			return '0';
		}
		$text = sprintf( '%.' . max( 0, min( 6, $decimals ) ) . 'F', $value );
		if ( str_contains( $text, '.' ) ) {
			$text = rtrim( rtrim( $text, '0' ), '.' );
		}
		return '' === $text || '-0' === $text ? '0' : $text;
	}
}
