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
use SWGallery\Core\Domain\Gallery\GallerySettings;
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
		$can_edit         = $this->access->canEdit( $actor, $gallery );
		$can_view_content = ! $gallery->passwordProtected || ( $can_edit && $this->access->canView( $actor, $gallery ) );
		$settings         = $can_view_content ? $gallery->settings : new GallerySettings();
		$cover            = $can_view_content ? $gallery->coverAttachmentId : null;
		return array(
			'id'                => $gallery->id,
			'title'             => $gallery->title,
			'description'       => $can_view_content ? $gallery->description : '',
			'status'            => $gallery->status->value,
			'ownerId'           => $gallery->ownerId,
			'coverAttachmentId' => $cover,
			'coverUrl'          => $this->cover_url( $actor, $cover ),
			'layout'            => $settings->layout,
			'sortMode'          => $settings->sortMode->value,
			'perPage'           => $settings->perPage,
			'hover'             => $settings->hover?->toArray(),
			'settings'          => (object) $settings->values,
			'passwordProtected' => $gallery->passwordProtected,
			'modified'          => $gallery->modifiedAt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' ),
			'revision'          => $revision,
			'itemCount'         => $can_view_content ? $item_count : 0,
			'canEdit'           => $can_edit,
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
