<?php
/**
 * Builds the JSON shape of a gallery (docs/rest-api.md).
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the framework independent core's value objects expose camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Rest;

use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Gallery\Gallery;
use SWGallery\WordPress\Security\WpGalleryAccessPolicy;

/**
 * Uses camelCase keys only. The cover URL is a medium-size WordPress image URL and only present when the actor may use the
 * attachment. `canEdit`, `canDelete` and `canPublish` are UX hints; the server enforces every write again.
 */
final class GalleryPresenter {

	public function __construct( private readonly WpGalleryAccessPolicy $access ) {
	}

	/**
	 * @param Actor   $actor      The requesting actor.
	 * @param Gallery $gallery    The gallery.
	 * @param int     $revision   Its current revision.
	 * @param int     $item_count Number of items (from one grouped query for lists).
	 * @return array<string, mixed>
	 */
	public function present( Actor $actor, Gallery $gallery, int $revision, int $item_count ): array {
		return array(
			'id'                => $gallery->id,
			'title'             => $gallery->title,
			'description'       => $gallery->description,
			'status'            => $gallery->status->value,
			'ownerId'           => $gallery->ownerId,
			'coverAttachmentId' => $gallery->coverAttachmentId,
			'coverUrl'          => $this->cover_url( $actor, $gallery->coverAttachmentId ),
			'layout'            => $gallery->settings->layout,
			'sortMode'          => $gallery->settings->sortMode->value,
			'perPage'           => $gallery->settings->perPage,
			'hover'             => $gallery->settings->hover?->toArray(),
			'settings'          => (object) $gallery->settings->values,
			'passwordProtected' => $gallery->passwordProtected,
			'modified'          => $gallery->modifiedAt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' ),
			'revision'          => $revision,
			'itemCount'         => $item_count,
			'canEdit'           => $this->access->canEdit( $actor, $gallery ),
			'canDelete'         => $this->access->canDelete( $actor, $gallery ),
			'canPublish'        => $this->access->canPublish( $actor, $gallery ),
		);
	}

	private function cover_url( Actor $actor, ?int $attachment_id ): ?string {
		if ( null === $attachment_id || ! $this->access->canUseAttachment( $actor, $attachment_id ) ) {
			return null;
		}
		$url = wp_get_attachment_image_url( $attachment_id, 'medium' );
		return is_string( $url ) && '' !== $url ? $url : null;
	}
}
