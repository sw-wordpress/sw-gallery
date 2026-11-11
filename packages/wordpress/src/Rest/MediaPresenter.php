<?php
/**
 * REST representation of an attachment (Media Overview, picker, upload response).
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Rest;

/**
 * Exposes only what the admin UI needs: no file system paths and no file names besides the public URLs. The post must
 * already be known to be an image the actor may use.
 */
final class MediaPresenter {

	/**
	 * @param \WP_Post $post           Image attachment.
	 * @param int      $used_in_count  Number of live galleries that contain it (one grouped query for the page).
	 * @return array<string, mixed>
	 */
	public function present( \WP_Post $post, int $used_in_count ): array {
		$meta  = wp_get_attachment_metadata( $post->ID );
		$thumb = wp_get_attachment_image_src( $post->ID, 'medium' );
		$full  = wp_get_attachment_url( $post->ID );

		return array(
			'id'              => $post->ID,
			'title'           => $post->post_title,
			'alt'             => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
			'caption'         => $post->post_excerpt,
			'mimeType'        => $post->post_mime_type,
			'width'           => is_array( $meta ) ? max( 0, (int) ( $meta['width'] ?? 0 ) ) : 0,
			'height'          => is_array( $meta ) ? max( 0, (int) ( $meta['height'] ?? 0 ) ) : 0,
			'thumbnailUrl'    => is_array( $thumb ) ? $thumb[0] : ( is_string( $full ) ? $full : null ),
			'fullUrl'         => is_string( $full ) ? $full : null,
			'uploadedAt'      => $this->iso_utc( $post ),
			'uploadedBy'      => (int) $post->post_author,
			'usedInGalleries' => max( 0, $used_in_count ),
		);
	}

	private function iso_utc( \WP_Post $post ): string {
		$gmt = $post->post_date_gmt;
		if ( '' === $gmt || str_starts_with( $gmt, '0000-00-00' ) ) {
			$gmt = get_gmt_from_date( $post->post_date );
		}
		$time = strtotime( $gmt . ' UTC' );
		return gmdate( 'Y-m-d\TH:i:s\Z', false === $time ? 0 : $time );
	}
}
