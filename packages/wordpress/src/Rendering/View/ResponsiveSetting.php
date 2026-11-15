<?php
/**
 * Reads responsive layout settings (plain value or object per breakpoint) and writes validated CSS custom properties.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Rendering\View;

/**
 * Shared by the layouts added after the grid. Only clamped integers ever leave this class, so nothing from the settings
 * can reach a style attribute as text. Breakpoint widths are the media queries of `assets/frontend/src/layouts.css`.
 */
final class ResponsiveSetting {

	public const BREAKPOINTS = array( 'base', 'sm', 'md', 'lg', 'xl' );

	/** Minimum viewport widths in pixels. */
	public const BREAKPOINT_WIDTHS = array(
		'sm' => 576,
		'md' => 768,
		'lg' => 1024,
		'xl' => 1280,
	);

	/**
	 * Clamped integer or the fallback.
	 *
	 * @param mixed $value   Raw setting.
	 * @param int   $fallback Fallback.
	 * @param int   $min     Lower bound.
	 * @param int   $max     Upper bound.
	 */
	public static function integer( mixed $value, int $fallback, int $min, int $max ): int {
		return is_int( $value ) ? max( $min, min( $max, $value ) ) : $fallback;
	}

	/**
	 * Value per breakpoint that is set explicitly; `base` is always present.
	 *
	 * @param mixed $value   Raw setting (integer or object keyed by breakpoint).
	 * @param int   $fallback Fallback for `base`.
	 * @param int   $min     Lower bound.
	 * @param int   $max     Upper bound.
	 * @return non-empty-array<string, int>
	 */
	public static function integers( mixed $value, int $fallback, int $min, int $max ): array {
		$result = array( 'base' => $fallback );
		if ( is_int( $value ) ) {
			$result['base'] = self::integer( $value, $fallback, $min, $max );
		} elseif ( is_array( $value ) ) {
			foreach ( self::BREAKPOINTS as $breakpoint ) {
				if ( isset( $value[ $breakpoint ] ) && is_int( $value[ $breakpoint ] ) ) {
					$result[ $breakpoint ] = self::integer( $value[ $breakpoint ], $fallback, $min, $max );
				}
			}
		}
		return $result;
	}

	/**
	 * Value at every breakpoint: a breakpoint without its own value inherits the previous one (`base` first).
	 *
	 * @param mixed $value    Raw setting (integer or object keyed by breakpoint).
	 * @param int   $fallback Fallback for `base`.
	 * @param int   $min      Lower bound.
	 * @param int   $max      Upper bound.
	 * @return non-empty-array<string, int>
	 */
	public static function effective( mixed $value, int $fallback, int $min, int $max ): array {
		$set       = self::integers( $value, $fallback, $min, $max );
		$current   = $set['base'];
		$effective = array( 'base' => $current );
		foreach ( self::BREAKPOINTS as $breakpoint ) {
			$current                  = $set[ $breakpoint ] ?? $current;
			$effective[ $breakpoint ] = $current;
		}
		return $effective;
	}

	/**
	 * Custom property declarations, e.g. `--swg-columns:2;--swg-columns-md:4;` (trailing semicolon). The base value is
	 * always written so the stylesheet default never depends on the theme.
	 *
	 * @param mixed  $value    Raw setting.
	 * @param string $property Property name without breakpoint suffix, e.g. `--swg-columns`.
	 * @param string $unit     Unit appended to the number, empty or `px`.
	 * @param int    $fallback  Fallback for `base`.
	 * @param int    $min      Lower bound.
	 * @param int    $max      Upper bound.
	 */
	public static function declarations( mixed $value, string $property, string $unit, int $fallback, int $min, int $max ): string {
		$css = '';
		foreach ( self::integers( $value, $fallback, $min, $max ) as $breakpoint => $number ) {
			$css .= sprintf( '%s%s:%d%s;', $property, 'base' === $breakpoint ? '' : '-' . $breakpoint, $number, $unit );
		}
		return $css;
	}

	/**
	 * `sizes` attribute (largest breakpoint first) from a CSS length per breakpoint.
	 *
	 * @param array<string, string> $expressions CSS length per breakpoint (`base` required).
	 */
	public static function sizes( array $expressions ): string {
		$sizes = array();
		foreach ( array_reverse( self::BREAKPOINT_WIDTHS, true ) as $breakpoint => $min_width ) {
			if ( isset( $expressions[ $breakpoint ] ) ) {
				$sizes[] = sprintf( '(min-width: %dpx) %s', $min_width, $expressions[ $breakpoint ] );
			}
		}
		$sizes[] = $expressions['base'];
		return implode( ', ', $sizes );
	}
}
