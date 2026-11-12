<?php
/**
 * Server-side validation of the attributes of the `sw-gallery/gallery` block.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Block;

use SWGallery\Core\Domain\Gallery\GallerySettings;

/**
 * Block attributes come from post content, which anyone with edit rights (or a crafted REST request) controls, so
 * every value is untrusted. Only the gallery id and four presentation overrides exist; anything invalid is dropped
 * silently and never echoed. The result feeds the render pipeline, which validates the overrides once more against
 * the layout schema (D1: one renderer).
 */
final class BlockAttributes {

	public const MIN_COLUMNS = 1;
	public const MAX_COLUMNS = 12;
	public const MIN_GAP     = 0;
	public const MAX_GAP     = 64;

	/**
	 * The gallery id; 0 when missing or invalid.
	 *
	 * @param array<mixed> $attributes Raw block attributes.
	 */
	public static function gallery_id( array $attributes ): int {
		return self::integer( $attributes['galleryId'] ?? null, 1, 2147483647 ) ?? 0;
	}

	/**
	 * The HTML anchor (`id`) of the block wrapper: a conservative subset of valid ids, otherwise null.
	 *
	 * @param array<mixed> $attributes Raw block attributes.
	 */
	public static function anchor( array $attributes ): ?string {
		$anchor = $attributes['anchor'] ?? null;
		return is_string( $anchor ) && 1 === preg_match( '/^[A-Za-z][A-Za-z0-9_:.-]{0,99}$/', $anchor ) ? $anchor : null;
	}

	/**
	 * Render options for `GalleryRenderer`: `layout`, `eager` and the `settings` overrides `columns` and `gap`.
	 *
	 * @param array<mixed> $attributes Raw block attributes.
	 * @return array<string, mixed>
	 */
	public static function options( array $attributes ): array {
		$options = array();

		$layout = $attributes['layout'] ?? null;
		if ( is_string( $layout ) && 1 === preg_match( GallerySettings::LAYOUT_ID_PATTERN, $layout ) ) {
			$options['layout'] = $layout;
		}

		$settings = array();
		$columns  = self::integer( $attributes['columns'] ?? null, self::MIN_COLUMNS, self::MAX_COLUMNS );
		if ( null !== $columns ) {
			$settings['columns'] = $columns;
		}
		$gap = self::integer( $attributes['gap'] ?? null, self::MIN_GAP, self::MAX_GAP );
		if ( null !== $gap ) {
			$settings['gap'] = $gap;
		}
		if ( array() !== $settings ) {
			$options['settings'] = $settings;
		}

		if ( is_bool( $attributes['eager'] ?? null ) ) {
			$options['eager'] = $attributes['eager'];
		}
		return $options;
	}

	/**
	 * A strict integer in range: integers and plain digit strings only (no floats, signs, exponents or whitespace).
	 *
	 * @param mixed $value Raw value.
	 */
	private static function integer( mixed $value, int $min, int $max ): ?int {
		if ( is_string( $value ) && 1 === preg_match( '/^(0|[1-9][0-9]{0,9})$/', $value ) ) {
			$value = (int) $value;
		}
		if ( ! is_int( $value ) || $value < $min || $value > $max ) {
			return null;
		}
		return $value;
	}
}
