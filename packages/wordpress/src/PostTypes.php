<?php
/**
 * Registration of the gallery post type and its meta.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress;

use SWGallery\Core\Domain\Gallery\GallerySettings;
use SWGallery\Core\Domain\Gallery\SortMode;

final class PostTypes {

	public const GALLERY             = 'swg_gallery';
	public const META_REV            = '_swg_revision';
	public const META_LAYOUT         = '_swg_layout';
	public const META_SETTINGS       = '_swg_settings';
	public const META_SORT_MODE      = '_swg_sort_mode';
	public const META_SCHEMA_VERSION = '_swg_schema_version';

	public static function register(): void {
		register_post_type(
			self::GALLERY,
			array(
				'labels'              => array(
					'name'          => __( 'Galleries', 'sw-gallery' ),
					'singular_name' => __( 'Gallery', 'sw-gallery' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'supports'            => array( 'title', 'editor', 'author', 'thumbnail' ),
			)
		);

		self::register_meta();
	}

	/**
	 * Registers every gallery meta key of SPECIFICATION 3.1 with a precise schema. `show_in_rest` stays false on
	 * purpose (decision "REST goes through our own controllers"); the auth callbacks still protect generic meta APIs.
	 */
	private static function register_meta(): void {
		$can_edit = static fn( $allowed, $meta_key, $post_id ): bool => current_user_can( 'edit_post', (int) $post_id );
		$common   = array(
			'single'       => true,
			'show_in_rest' => false,
		);

		register_post_meta(
			self::GALLERY,
			self::META_REV,
			$common + array(
				'type'              => 'integer',
				'default'           => 0,
				'description'       => 'Optimistic concurrency counter, written only by the revision store.',
				'sanitize_callback' => 'absint',
				'auth_callback'     => static fn(): bool => false,
			)
		);
		register_post_meta(
			self::GALLERY,
			self::META_LAYOUT,
			$common + array(
				'type'              => 'string',
				'default'           => GallerySettings::DEFAULT_LAYOUT,
				'description'       => 'Layout id of the gallery.',
				'sanitize_callback' => array( self::class, 'sanitize_layout' ),
				'auth_callback'     => $can_edit,
			)
		);
		register_post_meta(
			self::GALLERY,
			self::META_SETTINGS,
			$common + array(
				'type'              => 'string',
				'default'           => '{}',
				'description'       => 'Layout-specific settings as a JSON object; unknown keys are preserved.',
				'sanitize_callback' => array( self::class, 'sanitize_settings' ),
				'auth_callback'     => $can_edit,
			)
		);
		register_post_meta(
			self::GALLERY,
			self::META_SORT_MODE,
			$common + array(
				'type'              => 'string',
				'default'           => SortMode::Manual->value,
				'description'       => 'Item sort mode (manual, date_asc, date_desc, title_asc, title_desc).',
				'sanitize_callback' => array( self::class, 'sanitize_sort_mode' ),
				'auth_callback'     => $can_edit,
			)
		);
		register_post_meta(
			self::GALLERY,
			self::META_SCHEMA_VERSION,
			$common + array(
				'type'              => 'integer',
				'default'           => GallerySettings::SCHEMA_VERSION,
				'description'       => 'Version of the settings schema the gallery was saved with.',
				'sanitize_callback' => array( self::class, 'sanitize_schema_version' ),
				'auth_callback'     => $can_edit,
			)
		);
	}

	/**
	 * @param mixed $value Raw layout id.
	 */
	public static function sanitize_layout( $value ): string {
		$layout = sanitize_key( is_scalar( $value ) ? (string) $value : '' );
		return 1 === preg_match( GallerySettings::LAYOUT_ID_PATTERN, $layout ) ? $layout : GallerySettings::DEFAULT_LAYOUT;
	}

	/**
	 * Keeps only a valid JSON object; anything else becomes an empty object.
	 *
	 * @param mixed $value Raw meta value.
	 */
	public static function sanitize_settings( $value ): string {
		if ( ! is_string( $value ) ) {
			return '{}';
		}
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $value : '{}';
	}

	/**
	 * @param mixed $value Raw sort mode.
	 */
	public static function sanitize_sort_mode( $value ): string {
		$mode = is_string( $value ) ? SortMode::tryFrom( $value ) : null;
		return ( $mode ?? SortMode::Manual )->value;
	}

	/**
	 * @param mixed $value Raw schema version.
	 */
	public static function sanitize_schema_version( $value ): int {
		return max( 1, is_scalar( $value ) ? (int) $value : 1 );
	}
}
