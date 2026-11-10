<?php
/**
 * Registration of the gallery post type and its meta.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress;

final class PostTypes {

	public const GALLERY     = 'swg_gallery';
	public const META_REV    = '_swg_revision';
	public const META_LAYOUT = '_swg_layout';

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
				'supports'            => array( 'title', 'editor', 'author', 'thumbnail', 'revisions' ),
			)
		);

		register_post_meta(
			self::GALLERY,
			self::META_REV,
			array(
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
				'auth_callback'     => static fn(): bool => false,
			)
		);
		register_post_meta(
			self::GALLERY,
			self::META_LAYOUT,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => 'grid',
				'show_in_rest'      => false,
				'sanitize_callback' => 'sanitize_key',
				'auth_callback'     => static fn( $allowed, $meta_key, $post_id ): bool => current_user_can( 'edit_post', (int) $post_id ),
			)
		);
	}
}
