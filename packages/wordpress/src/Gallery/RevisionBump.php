<?php
/**
 * Best-effort revision bump for changes made outside the gallery services (attachment deleted, repair run).
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Gallery;

use SWGallery\Core\Exception\RevisionConflictException;
use SWGallery\WordPress\Hooks\GalleryChangedNotifier;
use SWGallery\WordPress\PostTypes;
use SWGallery\WordPress\Repository\PostMetaRevisionStore;

/**
 * Moves the `_swg_revision` of a gallery so open editors see a conflict and fragment caches keyed by revision are
 * invalidated, and announces the change with `swg_gallery_changed` (once per successful call; core events fire it
 * themselves and never pass through here). A lost race is retried once and then ignored: someone else bumped it, which has the same effect.
 */
final class RevisionBump {

	private PostMetaRevisionStore $store;

	private GalleryChangedNotifier $notifier;

	public function __construct( ?PostMetaRevisionStore $store = null, ?GalleryChangedNotifier $notifier = null ) {
		$this->store    = $store ?? new PostMetaRevisionStore();
		$this->notifier = $notifier ?? new GalleryChangedNotifier();
	}

	/**
	 * @return bool Whether the gallery exists and its revision moved (by this call or a concurrent one).
	 */
	public function bump( int $gallery_id ): bool {
		if ( $gallery_id <= 0 || PostTypes::GALLERY !== get_post_type( $gallery_id ) ) {
			return false;
		}
		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			try {
				$this->store->compareAndIncrement( $gallery_id, $this->store->current( $gallery_id ) );
				$this->notifier->notify( $gallery_id, 'revision_bump' );
				return true;
			} catch ( RevisionConflictException $e ) {
				continue;
			}
		}
		$this->notifier->notify( $gallery_id, 'revision_bump' );
		return true;
	}
}
