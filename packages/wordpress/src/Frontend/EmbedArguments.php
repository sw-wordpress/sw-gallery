<?php
/**
 * Strict allowlist of the per-embed presentation overrides (shortcode attributes and PHP API arguments).
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Frontend;

use SWGallery\Core\Contract\LayoutRegistryInterface;
use SWGallery\Core\Domain\Gallery\GallerySettings;

/**
 * Turns untrusted attributes into renderer options. Allowed: `layout` (a registered layout id), `columns` (1..12),
 * `gap` (0..64), `per_page` (1..200), `page` (>= 1) and, for the PHP API only, `eager` (bool). Unknown keys are ignored, invalid values are
 * dropped silently and never echoed. The page of the request (`swg_page_<id>`) wins over the `page` attribute.
 */
final class EmbedArguments {

	public const MAX_PAGE = 100000;

	public function __construct( private readonly LayoutRegistryInterface $layouts ) {
	}

	/**
	 * @param int                       $gallery_id  Gallery id (names the page query argument).
	 * @param array<mixed>              $raw         Untrusted attributes.
	 * @param bool                      $allow_eager Whether `eager` is accepted (PHP API).
	 * @param array<string, mixed>|null $query       Request query (defaults to `$_GET`; injectable for tests).
	 * @return array<string, mixed>
	 */
	public function options( int $gallery_id, array $raw, bool $allow_eager = false, ?array $query = null ): array {
		$options = array();

		$layout = $raw['layout'] ?? null;
		if ( is_string( $layout ) ) {
			$layout = trim( $layout );
			if ( 1 === preg_match( GallerySettings::LAYOUT_ID_PATTERN, $layout ) && $this->layouts->has( $layout ) ) {
				$options['layout'] = $layout;
			}
		}

		$settings = array();
		$columns  = self::int_between( $raw['columns'] ?? null, 1, 12 );
		if ( null !== $columns ) {
			$settings['columns'] = $columns;
		}
		$gap = self::int_between( $raw['gap'] ?? null, 0, 64 );
		if ( null !== $gap ) {
			$settings['gap'] = $gap;
		}
		if ( array() !== $settings ) {
			$options['settings'] = $settings;
		}

		$per_page = self::int_between( $raw['per_page'] ?? null, GallerySettings::MIN_PER_PAGE, GallerySettings::MAX_PER_PAGE );
		if ( null !== $per_page ) {
			$options['per_page'] = $per_page;
		}

		$page = $this->requested_page( $gallery_id, $query ) ?? self::int_between( $raw['page'] ?? null, 1, self::MAX_PAGE );
		if ( null !== $page ) {
			$options['page'] = $page;
		}

		if ( $allow_eager ) {
			$eager = self::bool_value( $raw['eager'] ?? null );
			if ( null !== $eager ) {
				$options['eager'] = $eager;
			}
		}
		return $options;
	}

	/**
	 * Page number of the current request (`swg_page_<galleryId>`), read-only link paging (decision D5).
	 *
	 * @param int                       $gallery_id Gallery id.
	 * @param array<string, mixed>|null $query      Request query override.
	 */
	public function requested_page( int $gallery_id, ?array $query = null ): ?int {
		$key = 'swg_page_' . $gallery_id;
		// Read-only pagination of public content; no state change, so a nonce does not apply.
		$query ??= $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$value   = $query[ $key ] ?? null;
		if ( is_string( $value ) ) {
			return self::int_between( wp_unslash( $value ), 1, self::MAX_PAGE );
		}
		return is_int( $value ) ? self::int_between( $value, 1, self::MAX_PAGE ) : null;
	}

	/**
	 * @param mixed $value Untrusted value: an int or a string of digits.
	 */
	private static function int_between( mixed $value, int $min, int $max ): ?int {
		if ( is_string( $value ) ) {
			$value = trim( $value );
			if ( 1 !== preg_match( '/^\d{1,10}$/', $value ) ) {
				return null;
			}
			$value = (int) $value;
		}
		return is_int( $value ) && $value >= $min && $value <= $max ? $value : null;
	}

	/**
	 * @param mixed $value Untrusted value.
	 */
	private static function bool_value( mixed $value ): ?bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( 0 === $value || '0' === $value || 'false' === $value ) {
			return false;
		}
		return 1 === $value || '1' === $value || 'true' === $value ? true : null;
	}
}
