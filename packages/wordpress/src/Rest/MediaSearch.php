<?php
/**
 * Permission-filtered attachment search of the Media Overview and the picker.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Rest;

use SWGallery\WordPress\Media\AllowedMimeTypes;

/**
 * Decision D11 as a database predicate, so paging and totals are exact: the actor sees the allowed image types that
 * `read_post` lets them read. WordPress resolves an attachment's read right from its effective status: own uploads
 * are always readable; attachments of a public parent are readable by every user with Media Library access; private
 * parents need `read_private_posts`; unattached uploads carry the public `inherit` status and are readable by every
 * user with Media Library access; attachments of a draft parent need `edit_others_posts`. Users who hold both
 * `edit_others_posts` and `read_private_posts` therefore see every image. The result is a conservative subset for
 * unusual constellations (e.g. attachments of a trashed or deleted parent are listed only for such users or the owner).
 * Callers still re-check each row with the access policy.
 */
final class MediaSearch {

	/**
	 * @param int    $user_id     The actor (a positive user id).
	 * @param string $search      Search text (title, caption, description).
	 * @param int    $page        Page, starting at 1.
	 * @param int    $per_page    Page size, 1 to 100.
	 * @param string $orderby     `date` or `title`.
	 * @param string $order       `asc` or `desc`.
	 * @param int    $uploaded_by Restricts to one uploader (0 = everybody).
	 * @return array{posts: list<\WP_Post>, total: int}
	 */
	public function search( int $user_id, string $search, int $page, int $per_page, string $orderby, string $order, int $uploaded_by ): array {
		$direction = 'asc' === $order ? 'ASC' : 'DESC';
		$sort      = 'title' === $orderby ? 'title' : 'date';
		$args      = array(
			'post_type'              => 'attachment',
			'post_status'            => array( 'inherit', 'private' ),
			'post_mime_type'         => AllowedMimeTypes::all(),
			'posts_per_page'         => max( 1, min( 100, $per_page ) ),
			'paged'                  => max( 1, $page ),
			'orderby'                => array(
				$sort => $direction,
				'ID'  => $direction,
			),
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
		);
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		if ( $uploaded_by > 0 ) {
			$args['author'] = $uploaded_by;
		}

		$where = $this->visibility_clause( $user_id );
		$query = $this->query( $args, $where );
		$total = (int) $query->found_posts;
		if ( 0 === $total && $page > 1 ) {
			// WordPress reports no total for a page past the end; ask for the first row to learn it.
			$total = (int) $this->query(
				array(
					'posts_per_page' => 1,
					'paged'          => 1,
					'fields'         => 'ids',
				) + $args,
				$where
			)->found_posts;
		}

		$posts = array();
		foreach ( is_array( $query->posts ) ? $query->posts : array() as $post ) {
			if ( $post instanceof \WP_Post ) {
				$posts[] = $post;
			}
		}
		$this->prime_parents( $posts );

		return array(
			'posts' => $posts,
			'total' => $total,
		);
	}

	/**
	 * @param array<string, mixed> $args  Query arguments.
	 * @param string               $where Visibility SQL appended to the WHERE clause.
	 */
	private function query( array $args, string $where ): \WP_Query {
		$hook = static fn( string $sql ): string => $sql . $where;
		add_filter( 'posts_where', $hook );
		try {
			return new \WP_Query( $args );
		} finally {
			remove_filter( 'posts_where', $hook );
		}
	}

	/**
	 * Loads the parents of the page's attachments in one query, so the per-row access checks (which look at the
	 * parent's status) do not query one by one.
	 *
	 * @param array<\WP_Post> $posts Attachments of the page.
	 */
	public function prime_parents( array $posts ): void {
		$parents = array();
		foreach ( $posts as $post ) {
			if ( $post->post_parent > 0 ) {
				$parents[ $post->post_parent ] = $post->post_parent;
			}
		}
		if ( array() !== $parents ) {
			_prime_post_caches( array_values( $parents ), false, false );
		}
	}

	/**
	 * SQL appended to the WHERE clause (starts with AND); empty for users who may read every image.
	 */
	private function visibility_clause( int $user_id ): string {
		global $wpdb;
		$private = user_can( $user_id, 'read_private_posts' );
		if ( $private && user_can( $user_id, 'edit_others_posts' ) ) {
			return '';
		}
		$statuses = array_values( get_post_stati( array( 'public' => true ) ) );
		if ( $private ) {
			$statuses = array_merge( $statuses, array_values( get_post_stati( array( 'private' => true ) ) ) );
		}
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$own_private  = $private ? " OR {$wpdb->posts}.post_status = 'private'" : '';
		$sql          = "AND ( {$wpdb->posts}.post_author = %d{$own_private} OR ( {$wpdb->posts}.post_status = 'inherit' AND ( {$wpdb->posts}.post_parent = 0 OR EXISTS ( SELECT 1 FROM {$wpdb->posts} AS swg_parent WHERE swg_parent.ID = {$wpdb->posts}.post_parent AND swg_parent.post_status IN ({$placeholders}) ) ) ) )"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table names, placeholders generated.
		return ' ' . $wpdb->prepare( $sql, array_merge( array( $user_id ), $statuses ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared here.
	}
}
