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
use SWGallery\Core\Logging\LogLevel;
use SWGallery\WordPress\Bootstrap\RegistrarInterface;
use SWGallery\WordPress\Infrastructure\Services;

/**
 * - Attachment edited, updated, trashed, restored, deleted, sub-sizes regenerated, alt text or file changed: the
 *   {@see \SWGallery\WordPress\Rendering\MediaEpoch} moves, so every cached gallery fragment misses once. (Gallery,
 *   item and settings changes need no hook here: they bump the gallery revision, which is part of the cache key.)
 * - Every persisted gallery change (core events, see docs/hooks.md) fires `swg_gallery_changed( int $gallery_id )` so
 *   page caches and CDN plugins can purge the pages that embed the gallery.
 */
final class CacheInvalidationRegistrar implements RegistrarInterface {

	/** Attachment meta keys that change what a gallery shows. */
	private const MEDIA_META_KEYS = array( '_wp_attachment_image_alt', '_wp_attached_file' );

	public function register(): void {
		foreach ( array( 'edit_attachment', 'attachment_updated', 'delete_attachment' ) as $hook ) {
			add_action( $hook, array( $this, 'on_attachment' ) );
		}
		foreach ( array( 'trashed_post', 'untrashed_post', 'deleted_post' ) as $hook ) {
			add_action( $hook, array( $this, 'on_post_status' ), 10, 2 );
		}
		add_filter( 'wp_update_attachment_metadata', array( $this, 'on_metadata' ) );
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
	 * Trash, restore and permanent deletion of posts: only attachments matter.
	 *
	 * @param int|string        $post_id Post id.
	 * @param \WP_Post|null|int $post    The post (core passes it since 5.5; older callers omit it).
	 */
	public function on_post_status( $post_id, $post = null ): void {
		$type = $post instanceof \WP_Post ? $post->post_type : get_post_type( (int) $post_id );
		if ( 'attachment' === $type ) {
			Services::media_epoch()->bump();
		}
	}

	/**
	 * Filter `wp_update_attachment_metadata`: regenerated sub-sizes. The value passes through unchanged.
	 *
	 * @param mixed $data Attachment metadata.
	 * @return mixed
	 */
	public function on_metadata( $data ) {
		Services::media_epoch()->bump();
		return $data;
	}

	/**
	 * Meta hooks (`added_`, `updated_`, `deleted_post_meta`): only the alt text and the file path matter.
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
		try {
			do_action( 'swg_gallery_changed', $gallery_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- "swg_" is the documented plugin prefix (see phpcs-wordpress.xml.dist).
		} catch ( \Throwable $e ) {
			Services::logger()->log(
				LogLevel::Error,
				'swg_listener_failed',
				array(
					'event'     => $event->name(),
					'hook'      => 'swg_gallery_changed',
					'exception' => $e::class,
				)
			);
		}
	}
}
