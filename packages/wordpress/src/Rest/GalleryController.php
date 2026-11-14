<?php
/**
 * Gallery routes of swg/v1: list, create, read, update, trash/delete, duplicate, restore.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the framework independent core's value objects expose camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Rest;

use SWGallery\Core\Application\Service\GalleryService;
use SWGallery\Core\Contract\GalleryRepositoryInterface;
use SWGallery\Core\Contract\RevisionStoreInterface;
use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Gallery\Gallery;
use SWGallery\Core\Domain\Gallery\GalleryQuery;
use SWGallery\Core\Domain\Gallery\GalleryResult;
use SWGallery\Core\Domain\Gallery\GallerySettings;
use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Core\Domain\Gallery\NewGallery;
use SWGallery\Core\Domain\Gallery\SortMode;
use SWGallery\Core\Exception\AccessDeniedException;
use SWGallery\Core\Exception\GalleryNotFoundException;
use SWGallery\Core\Exception\InvalidStateException;
use SWGallery\WordPress\Repository\WpdbGalleryRelations;
use SWGallery\WordPress\Security\WpGalleryAccessPolicy;
use SWGallery\WordPress\Settings\PluginSettings;

/**
 * Thin HTTP adapter over {@see GalleryService}. Every `{id}` route first loads the gallery and answers 404 when the
 * actor may not view it (existence does not leak); a viewer without the needed right gets 403 from the service.
 * The status `publish`/`private` rule (FU-03) lives here because the service has no publish check.
 */
final class GalleryController extends AbstractController {

	public const CODE_CANNOT_PUBLISH = 'swg_cannot_publish';
	public const CODE_INVALID_COVER  = 'swg_invalid_cover';
	public const CODE_NO_CHANGES     = 'swg_no_changes';

	private const WRITABLE_STATUSES = array( 'draft', 'pending', 'publish', 'private' );
	private const LIST_STATUSES     = array( 'draft', 'pending', 'publish', 'private', 'trash' );
	private const PATCH_FIELDS      = array( 'title', 'description', 'status', 'ownerId', 'coverAttachmentId', 'layout', 'sortMode', 'settings' );
	private const ID_PATTERN        = '(?P<id>[1-9]\d{0,9})';

	public function __construct(
		ErrorMapper $errors,
		private readonly GalleryService $service,
		private readonly GalleryRepositoryInterface $galleries,
		private readonly RevisionStoreInterface $revisions,
		private readonly WpdbGalleryRelations $relations,
		private readonly WpGalleryAccessPolicy $access,
		private readonly GalleryPresenter $presenter,
		private readonly PluginSettings $settings
	) {
		parent::__construct( $errors );
		$this->rest_base = 'galleries';
	}

	public function register_routes(): void {
		$id_arg = array(
			'description'       => 'Gallery id.',
			'type'              => 'integer',
			'minimum'           => 1,
			'sanitize_callback' => 'absint',
		);
		$base   = '/' . $this->rest_base;
		$item   = $base . '/' . self::ID_PATTERN;

		register_rest_route(
			self::NAMESPACE_V1,
			$base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->collection_args(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->with_settings_limits( $this->get_endpoint_args_for_item_schema( \WP_REST_Server::CREATABLE ) ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			$item,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => $this->validated( array( 'id' => $id_arg ) ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->validated(
						array(
							'id'               => $id_arg,
							'expectedRevision' => $this->expected_revision_arg(),
						)
					) + $this->with_settings_limits( $this->get_endpoint_args_for_item_schema( \WP_REST_Server::EDITABLE ) ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
					'args'                => $this->validated(
						array(
							'id'               => $id_arg,
							'expectedRevision' => $this->expected_revision_arg(),
							'force'            => array(
								'description' => 'Delete permanently instead of moving to the trash (attachments are never touched).',
								'type'        => 'boolean',
								'default'     => false,
							),
						)
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			$item . '/duplicate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'duplicate_item' ),
				'permission_callback' => array( $this, 'create_item_permissions_check' ),
				'args'                => $this->validated(
					array(
						'id'    => $id_arg,
						'title' => array(
							'description'       => 'Title of the copy (default: "<title> (copy)").',
							'type'              => 'string',
							'minLength'         => 1,
							'maxLength'         => 200,
							'sanitize_callback' => array( self::class, 'sanitize_line' ),
						),
					)
				),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			$item . '/restore',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restore_item' ),
				'permission_callback' => array( $this, 'delete_item_permissions_check' ),
				'args'                => $this->validated(
					array(
						'id'               => $id_arg,
						'expectedRevision' => $this->expected_revision_arg(),
					)
				),
			)
		);
	}

	/**
	 * Coarse check: the admin app is for users who can write posts (matches the access policy's view rule).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return true|\WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		return $this->require_capability( 'edit_posts' );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return true|\WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		return $this->require_capability( 'edit_posts' );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return true|\WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		return $this->require_allowed( $this->access->canCreate( $this->actor() ) );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return true|\WP_Error
	 */
	public function update_item_permissions_check( $request ) {
		return $this->require_capability( 'edit_posts' );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return true|\WP_Error
	 */
	public function delete_item_permissions_check( $request ) {
		return $this->require_capability( 'delete_posts' );
	}

	/**
	 * Lists galleries. Visibility rule: users with `edit_others_posts` and `read_private_posts` see galleries of all
	 * owners; everybody else only ever sees their own (the `owner` filter is then forced). Listing the trash needs
	 * `delete_posts`, and `delete_others_posts` to see foreign trashed galleries.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public function get_items( $request ): \WP_REST_Response|\WP_Error {
		return $this->guard(
			function () use ( $request ): \WP_REST_Response {
				$actor    = $this->actor();
				$statuses = $this->requested_statuses( $request );
				$trash    = in_array( GalleryStatus::Trash, $statuses, true );
				if ( $trash && ! current_user_can( 'delete_posts' ) ) {
					throw new AccessDeniedException();
				}
				$sees_all = current_user_can( 'edit_others_posts' ) && current_user_can( 'read_private_posts' )
					&& ( ! $trash || current_user_can( 'delete_others_posts' ) );
				$owner    = $request->has_param( 'owner' ) ? absint( $request->get_param( 'owner' ) ) : null;
				$owner    = $sees_all ? $owner : $actor->userId;
				$search   = $request->get_param( 'search' );
				$page     = max( 1, (int) $request->get_param( 'page' ) );
				$per_page = max( 1, (int) $request->get_param( 'per_page' ) );

				$term   = is_string( $search ) && '' !== $search ? $search : null;
				$result = $this->galleries->search( new GalleryQuery( $term, $statuses, $owner, ( $page - 1 ) * $per_page, $per_page ) );
				$total  = $result->total;
				if ( array() === $result->items && $page > 1 ) {
					// WP_Query reports no total for an offset past the end; ask again so the client can step back.
					$total = $this->galleries->search( new GalleryQuery( $term, $statuses, $owner, 0, 1 ) )->total;
				}
				$ids    = array_map( static fn( Gallery $gallery ): int => $gallery->id, $result->items );
				$counts = $this->relations->count_by_gallery( $ids );
				$data   = array();
				foreach ( $result->items as $gallery ) {
					$data[] = $this->presenter->present( $actor, $gallery, $this->revisions->current( $gallery->id )->value, $counts[ $gallery->id ] ?? 0 );
				}
				return $this->with_pagination_headers( new \WP_REST_Response( $data, 200 ), $total, $per_page );
			}
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public function get_item( $request ): \WP_REST_Response|\WP_Error {
		return $this->guard(
			function () use ( $request ): \WP_REST_Response {
				$actor   = $this->actor();
				$gallery = $this->viewable( $actor, (int) $request->get_param( 'id' ) );
				return new \WP_REST_Response( $this->present( $actor, $gallery, $this->revisions->current( $gallery->id )->value ), 200 );
			}
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public function create_item( $request ): \WP_REST_Response|\WP_Error {
		return $this->guard(
			function () use ( $request ): \WP_REST_Response|\WP_Error {
				$actor  = $this->actor();
				$status = $this->status_param( $request ) ?? GalleryStatus::Draft;
				if ( $this->needs_publish_right( $status ) && ! $this->access->canPublishNew( $actor ) ) {
					return $this->cannot_publish();
				}
				$settings = new GallerySettings(
					$this->string_param( $request, 'layout' ) ?? $this->settings->get()['defaultLayout'],
					SortMode::from( $this->string_param( $request, 'sortMode' ) ?? SortMode::Manual->value ),
					$this->settings_param( $request )
				);
				$result   = $this->service->create(
					$actor,
					new NewGallery(
						(string) $this->string_param( $request, 'title' ),
						$actor->userId,
						$this->string_param( $request, 'description' ) ?? '',
						$status,
						$settings
					)
				);
				return $this->created( $actor, $result );
			}
		);
	}

	/**
	 * Partial update; `settings` replaces the whole values map. Setting `status` to publish/private needs the publish
	 * right (403 `swg_cannot_publish`), a changed cover must be an attachment the actor may use (422).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public function update_item( $request ): \WP_REST_Response|\WP_Error {
		return $this->guard(
			function () use ( $request ): \WP_REST_Response|\WP_Error {
				$actor   = $this->actor();
				$current = $this->viewable( $actor, (int) $request->get_param( 'id' ) );
				if ( ! $this->access->canEdit( $actor, $current ) ) {
					throw new AccessDeniedException();
				}
				if ( array() === array_intersect( self::PATCH_FIELDS, array_keys( $request->get_params() ) ) ) {
					return ErrorMapper::bad_request( self::CODE_NO_CHANGES, __( 'The request does not change anything.', 'sw-gallery' ) );
				}
				$changed = $this->apply_changes( $actor, $current, $request );
				if ( $changed instanceof \WP_Error ) {
					return $changed;
				}
				if ( $this->needs_publish_right( $changed->status ) && $request->has_param( 'status' ) && ! $this->access->canPublish( $actor, $current ) ) {
					return $this->cannot_publish();
				}
				$result = $this->service->update( $actor, $changed, $this->expected_revision( $request ) );
				return new \WP_REST_Response( $this->present( $actor, $result->gallery, $result->revision->value ), 200 );
			}
		);
	}

	/**
	 * Moves the gallery to the trash; `force=true` deletes it permanently. Attachments are never touched.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public function delete_item( $request ): \WP_REST_Response|\WP_Error {
		return $this->guard(
			function () use ( $request ): \WP_REST_Response {
				$actor    = $this->actor();
				$gallery  = $this->viewable( $actor, (int) $request->get_param( 'id' ) );
				$expected = $this->expected_revision( $request );
				if ( filter_var( $request->get_param( 'force' ), FILTER_VALIDATE_BOOLEAN ) ) {
					$this->service->delete( $actor, $gallery->id, $expected );
					return new \WP_REST_Response(
						array(
							'id'      => $gallery->id,
							'deleted' => true,
						),
						200
					);
				}
				$revision = $this->service->trash( $actor, $gallery->id, $expected );
				return new \WP_REST_Response(
					array(
						'id'       => $gallery->id,
						'trashed'  => true,
						'status'   => GalleryStatus::Trash->value,
						'revision' => $revision->value,
					),
					200
				);
			}
		);
	}

	/**
	 * Copies the gallery into a new draft owned by the caller (revision 1).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public function duplicate_item( $request ): \WP_REST_Response|\WP_Error {
		return $this->guard(
			function () use ( $request ): \WP_REST_Response {
				$actor  = $this->actor();
				$source = $this->viewable( $actor, (int) $request->get_param( 'id' ) );
				if ( GalleryStatus::Trash === $source->status ) {
					throw new InvalidStateException();
				}
				$title = $this->string_param( $request, 'title' );
				if ( null === $title ) {
					/* translators: %s: title of the duplicated gallery */
					$title = sprintf( __( '%s (copy)', 'sw-gallery' ), $source->title );
				}
				return $this->created( $actor, $this->service->duplicate( $actor, $source->id, $title ) );
			}
		);
	}

	/**
	 * Restores a trashed gallery as a draft.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public function restore_item( $request ): \WP_REST_Response|\WP_Error {
		return $this->guard(
			function () use ( $request ): \WP_REST_Response {
				$actor   = $this->actor();
				$gallery = $this->viewable( $actor, (int) $request->get_param( 'id' ) );
				$result  = $this->service->restore( $actor, $gallery->id, $this->expected_revision( $request ) );
				return new \WP_REST_Response( $this->present( $actor, $result->gallery, $result->revision->value ), 200 );
			}
		);
	}

	/**
	 * Response schema (also the source of the write argument validation).
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		if ( null !== $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}
		$read_only = array(
			'type'     => 'integer',
			'context'  => array( 'view', 'edit' ),
			'readonly' => true,
		);
		$flag      = array(
			'type'     => 'boolean',
			'context'  => array( 'view', 'edit' ),
			'readonly' => true,
		);

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'swg-gallery',
			'type'       => 'object',
			'properties' => array(
				'id'                => array( 'description' => 'Gallery id.' ) + $read_only,
				'title'             => array(
					'description' => 'Title (single line, required on create).',
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 200,
					'context'     => array( 'view', 'edit' ),
					'arg_options' => array(
						'required'          => true,
						'sanitize_callback' => array( self::class, 'sanitize_line' ),
					),
				),
				'description'       => array(
					'description' => 'Plain text with line breaks (decision F-10).',
					'type'        => 'string',
					'maxLength'   => 10000,
					'context'     => array( 'view', 'edit' ),
					'arg_options' => array( 'sanitize_callback' => array( self::class, 'sanitize_multiline' ) ),
				),
				'status'            => array(
					'description' => 'Draft by default; publish and private need the publish right.',
					'type'        => 'string',
					'enum'        => self::WRITABLE_STATUSES,
					'context'     => array( 'view', 'edit' ),
				),
				'ownerId'           => array(
					'description' => 'Owner (changing it needs edit and delete rights; ignored on create).',
					'type'        => 'integer',
					'minimum'     => 1,
					'context'     => array( 'view', 'edit' ),
					'arg_options' => array( 'sanitize_callback' => 'absint' ),
				),
				'coverAttachmentId' => array(
					'description' => 'Cover attachment the actor may use, or null.',
					'type'        => array( 'integer', 'null' ),
					'minimum'     => 1,
					'context'     => array( 'view', 'edit' ),
					'arg_options' => array( 'sanitize_callback' => array( self::class, 'sanitize_nullable_id' ) ),
				),
				'coverUrl'          => array(
					'description' => 'Medium-size cover URL, only when the actor may use the attachment.',
					'type'        => array( 'string', 'null' ),
					'format'      => 'uri',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'layout'            => array(
					'description' => 'Registered layout id.',
					'type'        => 'string',
					'pattern'     => '^[a-z][a-z0-9_-]{0,63}$',
					'context'     => array( 'view', 'edit' ),
				),
				'sortMode'          => array(
					'description' => 'Item order mode.',
					'type'        => 'string',
					'enum'        => array_map( static fn( SortMode $mode ): string => $mode->value, SortMode::cases() ),
					'context'     => array( 'view', 'edit' ),
				),
				'settings'          => array(
					'description'          => 'Layout settings (validated against the layout schema); replaces the stored map.',
					'type'                 => 'object',
					'additionalProperties' => true,
					'context'              => array( 'view', 'edit' ),
				),
				'passwordProtected' => array( 'description' => 'Whether the post carries a password.' ) + $flag,
				'modified'          => array(
					'description' => 'Last modification, ISO 8601 UTC.',
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'revision'          => array( 'description' => 'Current revision; send it as expectedRevision.' ) + $read_only,
				'itemCount'         => array( 'description' => 'Number of items.' ) + $read_only,
				'canEdit'           => array( 'description' => 'UX hint: the actor may edit.' ) + $flag,
				'canDelete'         => array( 'description' => 'UX hint: the actor may trash/delete.' ) + $flag,
				'canPublish'        => array( 'description' => 'UX hint: the actor may publish.' ) + $flag,
			),
		);
		return $this->add_additional_fields_schema( $this->schema );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function collection_args(): array {
		return $this->validated(
			array(
				'search' => array(
					'description'       => 'Search in title and description.',
					'type'              => 'string',
					'maxLength'         => 200,
					'sanitize_callback' => array( self::class, 'sanitize_line' ),
				),
				'status' => array(
					'description' => 'Statuses to list (default: all except trash; trash only on request).',
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
						'enum' => self::LIST_STATUSES,
					),
					'maxItems'    => count( self::LIST_STATUSES ),
				),
				'owner'  => array(
					'description'       => 'Owner id (only honoured for users who see all galleries).',
					'type'              => 'integer',
					'minimum'           => 0,
					'sanitize_callback' => 'absint',
				),
			) + $this->pagination_args()
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return list<GalleryStatus>
	 */
	private function requested_statuses( \WP_REST_Request $request ): array {
		$statuses = array();
		foreach ( (array) $request->get_param( 'status' ) as $value ) {
			$status = GalleryStatus::tryFrom( (string) $value );
			if ( null !== $status && ! in_array( $status, $statuses, true ) ) {
				$statuses[] = $status;
			}
		}
		return $statuses;
	}

	/**
	 * @param Actor $actor Actor.
	 * @param int   $id    Gallery id.
	 * @throws GalleryNotFoundException When missing or not viewable (the same answer, so existence does not leak).
	 */
	private function viewable( Actor $actor, int $id ): Gallery {
		$gallery = $this->galleries->find( $id );
		if ( null === $gallery || ! $this->access->canView( $actor, $gallery ) ) {
			throw new GalleryNotFoundException();
		}
		return $gallery;
	}

	/**
	 * Applies the submitted fields; returns a 422 error for an unusable cover or owner.
	 *
	 * @param Actor            $actor   Actor.
	 * @param Gallery          $current Stored gallery.
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	private function apply_changes( Actor $actor, Gallery $current, \WP_REST_Request $request ): Gallery|\WP_Error {
		$changed = $current;
		if ( $request->has_param( 'title' ) ) {
			$changed = $changed->withTitle( (string) $this->string_param( $request, 'title' ) );
		}
		if ( $request->has_param( 'description' ) ) {
			$changed = $changed->withDescription( (string) $this->string_param( $request, 'description' ) );
		}
		if ( $request->has_param( 'status' ) ) {
			$changed = $changed->withStatus( $this->status_param( $request ) ?? $current->status );
		}
		if ( $request->has_param( 'ownerId' ) ) {
			// Authorisation (403) and validity (422 swg_invalid_owner) of a changed owner are decided by the service.
			$changed = $changed->withOwner( absint( $request->get_param( 'ownerId' ) ) );
		}
		if ( $request->has_param( 'coverAttachmentId' ) ) {
			$cover = $request->get_param( 'coverAttachmentId' );
			$cover = null === $cover ? null : absint( $cover );
			if ( null !== $cover && $cover !== $current->coverAttachmentId && ! $this->access->canUseAttachment( $actor, $cover ) ) {
				return ErrorMapper::unprocessable( self::CODE_INVALID_COVER, __( 'The cover must be an image you may use.', 'sw-gallery' ) );
			}
			$changed = $changed->withCover( $cover );
		}

		$settings = $current->settings;
		if ( $request->has_param( 'layout' ) ) {
			$settings = $settings->withLayout( (string) $this->string_param( $request, 'layout' ) );
		}
		if ( $request->has_param( 'sortMode' ) ) {
			$settings = $settings->withSortMode( SortMode::from( (string) $this->string_param( $request, 'sortMode' ) ) );
		}
		if ( $request->has_param( 'settings' ) ) {
			$settings = $settings->withValues( $this->settings_param( $request ) );
		}
		return $changed->withSettings( $settings );
	}

	private function needs_publish_right( GalleryStatus $status ): bool {
		return GalleryStatus::Publish === $status || GalleryStatus::Private === $status;
	}

	private function cannot_publish(): \WP_Error {
		return new \WP_Error(
			self::CODE_CANNOT_PUBLISH,
			__( 'You are not allowed to publish galleries.', 'sw-gallery' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	private function string_param( \WP_REST_Request $request, string $name ): ?string {
		$value = $request->get_param( $name );
		return is_string( $value ) ? $value : null;
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	private function status_param( \WP_REST_Request $request ): ?GalleryStatus {
		$value = $this->string_param( $request, 'status' );
		return null === $value ? null : GalleryStatus::tryFrom( $value );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return array<string, mixed>
	 */
	private function settings_param( \WP_REST_Request $request ): array {
		$values = array();
		foreach ( (array) $request->get_param( 'settings' ) as $key => $value ) {
			$values[ (string) $key ] = $value;
		}
		return $values;
	}

	/**
	 * @param Actor   $actor    Actor.
	 * @param Gallery $gallery  Gallery.
	 * @param int     $revision Revision.
	 * @return array<string, mixed>
	 */
	private function present( Actor $actor, Gallery $gallery, int $revision ): array {
		$counts = $this->relations->count_by_gallery( array( $gallery->id ) );
		return $this->presenter->present( $actor, $gallery, $revision, $counts[ $gallery->id ] ?? 0 );
	}

	private function created( Actor $actor, GalleryResult $result ): \WP_REST_Response {
		$response = new \WP_REST_Response( $this->present( $actor, $result->gallery, $result->revision->value ), 201 );
		$response->header( 'Location', rest_url( sprintf( '%s/%s/%d', $this->namespace, $this->rest_base, $result->gallery->id ) ) );
		return $response;
	}
}
