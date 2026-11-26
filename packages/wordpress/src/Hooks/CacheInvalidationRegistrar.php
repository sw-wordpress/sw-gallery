<?php
/**
 * Cache invalidation: the media epoch for attachment changes and the public `swg_gallery_changed` action.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Hooks;

use SWGallery\Core\Event\EventInterface;
use SWGallery\Core\Event\VetoableEventInterface;
use SWGallery\WordPress\Bootstrap\RegistrarInterface;
use SWGallery\WordPress\Infrastructure\Services;

/**
 * - Attachment edited, updated, trashed, restored, deleted, sub-sizes regenerated, alt text or file changed: the
 *   {@see \SWGallery\WordPress\Rendering\MediaEpoch} moves, so every cached gallery fragment misses once. (Gallery,
 *   item and settings changes also bump the gallery revision.) Parent visibility changes invalidate immediately.
 * - Every persisted gallery change (core events, see docs/hooks.md) fires `swg_gallery_changed( int $gallery_id )` so
 *   page caches and CDN plugins can purge the pages that embed the gallery.
 */
final class CacheInvalidationRegistrar implements RegistrarInterface {

	/** Attachment meta keys that change what a gallery shows. */
	private const MEDIA_META_KEYS = array( '_wp_attachment_image_alt', '_wp_attached_file', '_wp_attachment_metadata' );

	public function register(): void {
		add_action( 'edit_attachment', array( $this, 'on_attachment' ) );
		add_action( 'attachment_updated', array( $this, 'on_attachment_updated' ), 10, 3 );
		foreach ( array( 'trashed_post', 'untrashed_post', 'deleted_post' ) as $hook ) {
			add_action( $hook, array( $this, 'on_post_status' ), 10, 2 );
		}
		add_action( 'post_updated', array( $this, 'on_parent_updated' ), 10, 3 );
		add_action( 'transition_post_status', array( $this, 'on_parent_status' ), 10, 2 );
		foreach ( array( 'added_post_meta', 'updated_post_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'on_meta' ), 10, 3 );
		}
		add_action( 'deleted_post_meta', array( $this, 'on_meta' ), 10, 3 );

		Services::events()->listen( EventInterface::class, array( $this, 'on_event' ) );
	}

	/** Any attachment hook; the id argument is not needed, the epoch is global. */
	public function on_attachment(): void {
		Services::media_epoch()->bump();
	}

	/**
	 * Trash, restore and deletion can change the visibility of attachments, including those of a parent post.
	 *
	 * @param int|string        $post_id Post id.
	 * @param \WP_Post|null|int $post    The post (core passes it since 5.5; older callers omit it).
	 */
	public function on_post_status( $post_id, $post = null ): void {
		$type = $post instanceof \WP_Post ? $post->post_type : get_post_type( (int) $post_id );
		if ( false !== $type ) {
			Services::media_epoch()->bump( true );
		}
	}

	/**
	 * Inherited media visibility changes with the parent's status and password.
	 *
	 * @param int      $post_id Parent id.
	 * @param \WP_Post $after   Saved post.
	 * @param \WP_Post $before  Previous post.
	 */
	public function on_parent_updated( int $post_id, \WP_Post $after, \WP_Post $before ): void {
		if ( $after->post_password !== $before->post_password ) {
			Services::media_epoch()->bump( true );
		}
	}

	/**
	 * Status transitions include scheduled publication.
	 *
	 * @param string $after  Saved status.
	 * @param string $before Previous status.
	 */
	public function on_parent_status( string $after, string $before ): void {
		if ( 'new' !== $before && $after !== $before ) {
			Services::media_epoch()->bump( true );
		}
	}

	/**
	 * Invalidate for changes to the attachment's own visibility or its parent.
	 *
	 * @param int      $post_id Attachment id.
	 * @param \WP_Post $after   Saved attachment.
	 * @param \WP_Post $before  Previous attachment.
	 */
	public function on_attachment_updated( int $post_id, \WP_Post $after, \WP_Post $before ): void {
		if ( $after->post_parent !== $before->post_parent || $after->post_status !== $before->post_status ) {
			Services::media_epoch()->bump( true );
			return;
		}

		$this->on_parent_updated( $post_id, $after, $before );
	}

	/**
	 * Meta hooks (`added_`, `updated_`, `deleted_post_meta`): listen after the attachment metadata has been stored.
	 *
	 * @param mixed  $meta_id    Meta id(s), unused.
	 * @param mixed  $object_id  Post id, unused.
	 * @param string $meta_key   Meta key.
	 */
	public function on_meta( $meta_id, $object_id, $meta_key ): void {
		unset( $meta_id, $object_id );
		if ( in_array( $meta_key, self::MEDIA_META_KEYS, true ) ) {
			Services::media_epoch()->bump();
		}
	}

	/**
	 * Core event listener: fires `swg_gallery_changed` once per persisted gallery change. A failing listener is
	 * isolated and logged (event name and exception class only), like every other hook of this plugin.
	 */
	public function on_event( EventInterface $event ): void {
		if ( $event instanceof VetoableEventInterface ) {
			return;
		}
		$gallery_id = $event->payload()['gallery_id'] ?? null;
		if ( ! is_int( $gallery_id ) || $gallery_id <= 0 ) {
			return;
		}
		( new GalleryChangedNotifier() )->notify( $gallery_id, $event->name() );
	}
}
