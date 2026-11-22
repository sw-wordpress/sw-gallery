<?php
/**
 * Shared view/update rules of a single gallery for every entry point (REST, MCP abilities).
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the framework independent core's value objects expose camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Gallery;

use SWGallery\Core\Application\Service\GalleryService;
use SWGallery\Core\Contract\GalleryRepositoryInterface;
use SWGallery\Core\Contract\RevisionStoreInterface;
use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Gallery\Gallery;
use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Core\Domain\Gallery\HoverEffect;
use SWGallery\Core\Domain\Gallery\Revision;
use SWGallery\Core\Domain\Gallery\SortMode;
use SWGallery\Core\Exception\AccessDeniedException;
use SWGallery\Core\Exception\GalleryNotFoundException;
use SWGallery\WordPress\Repository\WpdbGalleryRelations;
use SWGallery\WordPress\Rest\ErrorMapper;
use SWGallery\WordPress\Rest\GalleryPresenter;
use SWGallery\WordPress\Security\WpGalleryAccessPolicy;

/**
 * One implementation of "may this actor see/edit this gallery" and "apply these fields". The REST controller and the
 * MCP abilities are thin callers: they validate their input shape and call this class, so the rules (404 for hidden
 * galleries, publish right, cover rights, revision check) cannot drift between entry points.
 */
final class GalleryEditor {

	public const CODE_CANNOT_PUBLISH = 'swg_cannot_publish';
	public const CODE_INVALID_COVER  = 'swg_invalid_cover';
	public const CODE_NO_CHANGES     = 'swg_no_changes';

	/** Fields a partial update may carry (`ownerId` is accepted here for REST; the MCP abilities never pass it). */
	public const UPDATE_FIELDS = array( 'title', 'description', 'status', 'ownerId', 'coverAttachmentId', 'layout', 'sortMode', 'perPage', 'hover', 'settings' );

	public function __construct(
		private readonly GalleryService $service,
		private readonly GalleryRepositoryInterface $galleries,
		private readonly RevisionStoreInterface $revisions,
		private readonly WpdbGalleryRelations $relations,
		private readonly WpGalleryAccessPolicy $access,
		private readonly GalleryPresenter $presenter
	) {
	}

	/**
	 * @throws GalleryNotFoundException When missing or not viewable (the same answer, so existence does not leak).
	 */
	public function viewable( Actor $actor, int $id ): Gallery {
		$gallery = $this->galleries->find( $id );
		if ( null === $gallery || ! $this->access->canView( $actor, $gallery ) ) {
			throw new GalleryNotFoundException();
		}
		return $gallery;
	}

	/**
	 * The gallery the actor may edit (404 for a missing or not viewable gallery, see {@see self::viewable()}).
	 *
	 * @throws AccessDeniedException When viewable but not editable.
	 */
	public function editable( Actor $actor, int $id ): Gallery {
		$gallery = $this->viewable( $actor, $id );
		if ( ! $this->access->canEdit( $actor, $gallery ) ) {
			throw new AccessDeniedException();
		}
		return $gallery;
	}

	/**
	 * @return array<string, mixed> Presented gallery (see GalleryPresenter).
	 * @throws GalleryNotFoundException When missing or not viewable.
	 */
	public function view( Actor $actor, int $id ): array {
		$gallery = $this->viewable( $actor, $id );
		return $this->present( $actor, $gallery, $this->revisions->current( $gallery->id )->value );
	}

	/**
	 * Partial update. `$fields` holds only the submitted keys of {@see self::UPDATE_FIELDS} (a key with a `null` value
	 * is submitted); values were validated against the schema of the calling entry point.
	 *
	 * @param Actor                $actor            Actor.
	 * @param int                  $id               Gallery id.
	 * @param int                  $expected         Revision the caller last saw.
	 * @param array<string, mixed> $fields           Submitted fields.
	 * @return array<string, mixed>|\WP_Error Presented gallery, or a 400/403/422 error that is not a domain exception.
	 */
	public function update( Actor $actor, int $id, int $expected, array $fields ): array|\WP_Error {
		$current = $this->editable( $actor, $id );
		if ( array() === array_intersect( self::UPDATE_FIELDS, array_keys( $fields ) ) ) {
			return ErrorMapper::bad_request( self::CODE_NO_CHANGES, __( 'The request does not change anything.', 'sw-gallery' ) );
		}
		$changed = $this->apply( $actor, $current, $fields );
		if ( $changed instanceof \WP_Error ) {
			return $changed;
		}
		if ( self::needs_publish_right( $changed->status ) && array_key_exists( 'status', $fields ) && ! $this->access->canPublish( $actor, $current ) ) {
			return self::cannot_publish();
		}
		$result = $this->service->update( $actor, $changed, new Revision( $expected ) );
		return $this->present( $actor, $result->gallery, $result->revision->value );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function present( Actor $actor, Gallery $gallery, int $revision ): array {
		$counts = $this->relations->count_by_gallery( array( $gallery->id ) );
		return $this->presenter->present( $actor, $gallery, $revision, $counts[ $gallery->id ] ?? 0 );
	}

	public static function needs_publish_right( GalleryStatus $status ): bool {
		return GalleryStatus::Publish === $status || GalleryStatus::Private === $status;
	}

	public static function cannot_publish(): \WP_Error {
		return new \WP_Error(
			self::CODE_CANNOT_PUBLISH,
			__( 'You are not allowed to publish galleries.', 'sw-gallery' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Applies the submitted fields; returns a 422 error for an unusable cover.
	 *
	 * @param Actor                $actor   Actor.
	 * @param Gallery              $current Stored gallery.
	 * @param array<string, mixed> $fields  Submitted fields.
	 */
	private function apply( Actor $actor, Gallery $current, array $fields ): Gallery|\WP_Error {
		$changed = $current;
		if ( array_key_exists( 'title', $fields ) ) {
			$changed = $changed->withTitle( self::text( $fields['title'] ) );
		}
		if ( array_key_exists( 'description', $fields ) ) {
			$changed = $changed->withDescription( self::text( $fields['description'] ) );
		}
		if ( array_key_exists( 'status', $fields ) ) {
			$status  = is_string( $fields['status'] ) ? GalleryStatus::tryFrom( $fields['status'] ) : null;
			$changed = $changed->withStatus( $status ?? $current->status );
		}
		if ( array_key_exists( 'ownerId', $fields ) ) {
			// Authorisation (403) and validity (422 swg_invalid_owner) of a changed owner are decided by the service.
			$changed = $changed->withOwner( absint( $fields['ownerId'] ) );
		}
		if ( array_key_exists( 'coverAttachmentId', $fields ) ) {
			$cover = null === $fields['coverAttachmentId'] ? null : absint( $fields['coverAttachmentId'] );
			if ( null !== $cover && $cover !== $current->coverAttachmentId && ! $this->access->canUseAttachment( $actor, $cover ) ) {
				return ErrorMapper::unprocessable( self::CODE_INVALID_COVER, __( 'The cover must be an image you may use.', 'sw-gallery' ) );
			}
			$changed = $changed->withCover( $cover );
		}

		$settings = $current->settings;
		if ( array_key_exists( 'layout', $fields ) ) {
			$settings = $settings->withLayout( self::text( $fields['layout'] ) );
		}
		if ( array_key_exists( 'sortMode', $fields ) ) {
			$settings = $settings->withSortMode( SortMode::from( self::text( $fields['sortMode'] ) ) );
		}
		if ( array_key_exists( 'perPage', $fields ) ) {
			$settings = $settings->withPerPage( is_numeric( $fields['perPage'] ) ? (int) $fields['perPage'] : null );
		}
		if ( array_key_exists( 'hover', $fields ) ) {
			$settings = $settings->withHover( HoverEffect::fromArray( $fields['hover'] ) );
		}
		if ( array_key_exists( 'settings', $fields ) ) {
			$values = array();
			foreach ( (array) $fields['settings'] as $key => $value ) {
				$values[ (string) $key ] = $value;
			}
			$settings = $settings->withValues( $values );
		}
		return $changed->withSettings( $settings );
	}

	private static function text( mixed $value ): string {
		return is_string( $value ) ? $value : '';
	}
}
