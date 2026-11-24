<?php
/**
 * Search gallery titles and descriptions without indexing password-protected content.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Repository;

/**
 * A collection can include a protected title for an actor who cannot edit the gallery. Restrict matching on
 * descriptions at the database boundary so result counts and pagination cannot disclose protected text.
 */
final class GallerySearchQuery extends \WP_Query {

	/**
	 * @param array<string, mixed> $query_vars Parsed WordPress query variables.
	 * @return string SQL search clause.
	 */
	protected function parse_search( &$query_vars ): string {
		global $wpdb;
		$term = str_replace( array( "\r", "\n" ), '', stripslashes( (string) $query_vars['s'] ) );
		$like = '%' . $wpdb->esc_like( $term ) . '%';

		return $wpdb->prepare(
			" AND (%i.post_title LIKE %s OR (%i.post_password = '' AND %i.post_content LIKE %s)) ",
			$wpdb->posts,
			$like,
			$wpdb->posts,
			$wpdb->posts,
			$like
		);
	}
}
