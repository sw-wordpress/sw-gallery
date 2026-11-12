<?php
/**
 * Gallery access policy backed by WordPress capabilities (decisions D1, D4, D11).
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the framework independent core's value objects expose camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Security;

use SWGallery\Core\Contract\GalleryAccessPolicyInterface;
use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Access\RenderAccess;
use SWGallery\Core\Domain\Access\StatusAccessRules;
use SWGallery\Core\Domain\Gallery\Gallery;
use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\WordPress\Media\AllowedMimeTypes;
use SWGallery\WordPress\PostTypes;

/**
 * Every answer is computed for the passed-in actor with `user_can()` against the stored post, so ownership and status
 * are those of the database (an in-memory entity cannot grant itself rights) and the current request user is never
 * consulted. The galleries use the `post` capability type with `map_meta_cap`: owners manage their own galleries,
 * foreign ones need `edit_others_posts` / `delete_others_posts`, published ones `edit_published_posts` and so on.
 */
final class WpGalleryAccessPolicy implements GalleryAccessPolicyInterface {

	public function canCreate( Actor $actor ): bool {
		return $this->can( $actor, $this->capabilities()->create_posts );
	}

	public function embedAccess( Actor $actor, Gallery $gallery, bool $passwordUnlocked = false ): RenderAccess {
		return StatusAccessRules::forEmbed(
			$gallery->status,
			$gallery->passwordProtected,
			$passwordUnlocked,
			$this->can( $actor, 'read_post', $gallery->id ),
			$this->canEdit( $actor, $gallery )
		);
	}

	/**
	 * Authorised preview (FU-13): users who can edit the stored gallery see it in every non-trashed status (draft,
	 * pending, future, private, published) and without the password; everybody else, and every trashed gallery, gets
	 * nothing. Only the preview entry point asks this; the public embeds use embedAccess().
	 */
	public function previewAccess( Actor $actor, Gallery $gallery ): RenderAccess {
		if ( GalleryStatus::Trash === $gallery->status || ! $this->canEdit( $actor, $gallery ) ) {
			return RenderAccess::Nothing;
		}
		return RenderAccess::Render;
	}

	/**
	 * The admin app is for users who can write posts; within it a gallery is visible when the user may read it
	 * (own drafts, published, private only for owners or users with `read_private_posts`).
	 */
	public function canView( Actor $actor, Gallery $gallery ): bool {
		return $this->can( $actor, 'edit_posts' ) && $this->can( $actor, 'read_post', $gallery->id );
	}

	public function canEdit( Actor $actor, Gallery $gallery ): bool {
		return $this->can( $actor, 'edit_post', $gallery->id );
	}

	/** Trash, restore and permanent delete all need `delete_post`. */
	public function canDelete( Actor $actor, Gallery $gallery ): bool {
		return $this->can( $actor, 'delete_post', $gallery->id );
	}

	/**
	 * Publishing is stricter than editing: contributors may only keep drafts or pending galleries. Not part of the core
	 * interface; the adapter's REST layer must call it when a request changes the status to `publish`.
	 */
	public function canPublish( Actor $actor, Gallery $gallery ): bool {
		return $this->canEdit( $actor, $gallery ) && $this->can( $actor, 'publish_post', $gallery->id );
	}

	/**
	 * Creating a gallery directly as `publish` or `private` (FU-10): a new gallery has no id yet, so the right is the
	 * post type's `publish_posts` capability. Updates use {@see self::canPublish()}.
	 */
	public function canPublishNew( Actor $actor ): bool {
		return $this->can( $actor, $this->capabilities()->publish_posts ?? 'publish_posts' );
	}

	/**
	 * Decision D11: a usable image is an existing, non-trashed attachment with an allowed raster MIME type that the
	 * actor can read in the Media Library (access to the library plus `read_post` on the attachment, which keeps other
	 * users' private uploads out).
	 */
	public function canUseAttachment( Actor $actor, int $attachmentId ): bool {
		$attachment = $this->attachment( $attachmentId );
		if ( null === $attachment || 'trash' === $attachment->post_status ) {
			return false;
		}
		return AllowedMimeTypes::is_allowed( (string) $attachment->post_mime_type )
			&& $this->can( $actor, 'upload_files' )
			&& $this->can( $actor, 'read_post', $attachmentId );
	}

	/** Changing global attachment metadata needs `edit_post` on the attachment itself. */
	public function canEditAttachment( Actor $actor, int $attachmentId ): bool {
		return null !== $this->attachment( $attachmentId ) && $this->can( $actor, 'edit_post', $attachmentId );
	}

	/**
	 * Whether the visitor of the current request supplied the gallery's password (core `post_password_required()` logic
	 * with its cookie). This is about the visitor's cookie, not about an actor; pass the result as `$passwordUnlocked`.
	 */
	public function isPasswordUnlocked( Gallery $gallery ): bool {
		return ! $gallery->passwordProtected || ! post_password_required( $gallery->id );
	}

	private function can( Actor $actor, string $capability, int $object_id = 0 ): bool {
		if ( $actor->isAnonymous() ) {
			// Visitors hold no capability; published galleries are decided by embedAccess() alone.
			return false;
		}
		return $object_id > 0
			? user_can( $actor->userId, $capability, $object_id )
			: user_can( $actor->userId, $capability );
	}

	private function capabilities(): \stdClass {
		$type = get_post_type_object( PostTypes::GALLERY );
		return null === $type ? (object) array(
			'create_posts'  => 'edit_posts',
			'publish_posts' => 'publish_posts',
		) : $type->cap;
	}

	private function attachment( int $attachment_id ): ?\WP_Post {
		if ( $attachment_id <= 0 ) {
			return null;
		}
		$post = get_post( $attachment_id );
		return ( $post instanceof \WP_Post && 'attachment' === $post->post_type ) ? $post : null;
	}
}
