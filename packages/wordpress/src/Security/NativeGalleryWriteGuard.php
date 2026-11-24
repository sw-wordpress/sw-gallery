<?php
/**
 * Protects gallery writes from native WordPress post editing routes.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Security;

use SWGallery\WordPress\PostTypes;

/**
 * The plugin service owns gallery authorization and revisions. Deny native post management at the permission
 * check, then reject native saves and lifecycle operations that do not call the object capability checks.
 */
final class NativeGalleryWriteGuard {

	public function register(): void {
		add_filter( 'map_meta_cap', array( $this, 'deny_native_management' ), PHP_INT_MAX, 4 );
		add_filter( 'wp_insert_post_empty_content', array( $this, 'reject_native_save' ), PHP_INT_MAX, 2 );
		add_filter( 'pre_trash_post', array( $this, 'reject_native_removal' ), PHP_INT_MAX, 2 );
		add_filter( 'pre_untrash_post', array( $this, 'reject_native_removal' ), PHP_INT_MAX, 2 );
		add_filter( 'pre_delete_post', array( $this, 'reject_native_removal' ), PHP_INT_MAX, 2 );
	}

	/**
	 * @param string[]     $required Capabilities required by WordPress.
	 * @phpstan-param list<string> $required
	 * @param string       $cap      The requested capability.
	 * @param int          $user_id  WordPress user being checked.
	 * @param array<mixed> $args     Arguments including the stored post ID.
	 * @return list<string>
	 */
	public function deny_native_management( array $required, string $cap, int $user_id, array $args ): array {
		if ( ! in_array( $cap, array( 'edit_post', 'delete_post', 'edit_page', 'delete_page' ), true )
			|| ! $this->is_native_request() ) {
			return $required;
		}

		$post = $args[0] ?? 0;
		$id   = $post instanceof \WP_Post ? $post->ID : ( is_numeric( $post ) ? (int) $post : 0 );
		return $id > 0 && PostTypes::GALLERY === get_post_type( $id ) ? array( 'do_not_allow' ) : $required;
	}

	/**
	 * @param bool                 $reject  Whether WordPress already rejected this post.
	 * @param array<string, mixed> $postarr Native post data before persistence.
	 */
	public function reject_native_save( bool $reject, array $postarr ): bool {
		if ( $reject || ! $this->is_native_request() ) {
			return $reject;
		}

		$id = (int) ( $postarr['ID'] ?? 0 );
		return PostTypes::GALLERY === ( $postarr['post_type'] ?? '' )
			|| ( $id > 0 && PostTypes::GALLERY === get_post_type( $id ) );
	}

	/**
	 * @param mixed    $result A prior short-circuit result, or null to continue.
	 * @param \WP_Post $post   Stored post that WordPress would modify.
	 */
	public function reject_native_removal( mixed $result, \WP_Post $post ): mixed {
		return PostTypes::GALLERY === $post->post_type && $this->is_native_request() ? false : $result;
	}

	private function is_native_request(): bool {
		global $pagenow;
		return ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
			|| wp_doing_ajax()
			|| ( is_admin() && in_array( $pagenow, array( 'post.php', 'post-new.php', 'edit.php' ), true ) );
	}
}
