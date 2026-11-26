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
use SWGallery\Core\Domain\Gallery\HoverAnimation;
use SWGallery\Core\Domain\Gallery\HoverEffect;
use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Core\Domain\Gallery\NewGallery;
use SWGallery\Core\Domain\Gallery\SortMode;
use SWGallery\Core\Exception\AccessDeniedException;
use SWGallery\Core\Exception\GalleryNotFoundException;
use SWGallery\Core\Exception\InvalidStateException;
use SWGallery\WordPress\Gallery\GalleryEditor;
use SWGallery\WordPress\Gallery\GalleryLister;
use SWGallery\WordPress\Repository\WpdbGalleryRelations;
use SWGallery\WordPress\Security\WpGalleryAccessPolicy;
use SWGallery\WordPress\Settings\PluginSettings;

/**
 * Thin HTTP adapter over {@see GalleryService}. Every `{id}` route first loads the gallery and answers 404 when the
 * actor may not view it (existence does not leak); a viewer without the needed right gets 403 from the service.
 * The status `publish`/`private` rule (FU-03) lives here because the service has no publish check.
 */
final class GalleryController extends AbstractController {

	public const CODE_CANNOT_PUBLISH = GalleryEditor::CODE_CANNOT_PUBLISH;
	public const CODE_INVALID_COVER  = GalleryEditor::CODE_INVALID_COVER;
	public const CODE_NO_CHANGES     = GalleryEditor::CODE_NO_CHANGES;

	private const WRITABLE_STATUSES = array( 'draft', 'pending', 'publish', 'private' );
	private const LIST_STATUSES     = GalleryLister::LIST_STATUSES;
	private const ID_PATTERN        = '(?P<id>[1-9]\d{0,9})';

	public function __construct(
		ErrorMapper $errors,
		private readonly GalleryService $service,
		private readonly RevisionStoreInterface $revisions,
		private readonly WpdbGalleryRelations $relations,
		private readonly WpGalleryAccessPolicy $access,
		private readonly GalleryPresenter $presenter,
		private readonly GalleryEditor $editor,
		private readonly GalleryLister $lister
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
				$per_page = max( 1, (int) $request->get_param( 'per_page' ) );
				$search   = $request->get_param( 'search' );
				$owner    = $request->has_param( 'owner' ) ? absint( $request->get_param( 'owner' ) ) : null;
				$found    = $this->lister->list(
					$actor,
					is_string( $search ) ? $search : null,
					GalleryLister::parse_statuses( $request->get_param( 'status' ) ),
					$owner,
					(int) $request->get_param( 'page' ),
					$per_page
				);
				$data     = $found['items'];
				$total    = $found['total'];
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
				$gallery = $this->viewable( $actor, $this->route_id( $request, 'id' ) );
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
				$fields = array();
				foreach ( array( 'title', 'description', 'status', 'layout', 'sortMode', 'perPage', 'hover', 'settings' ) as $field ) {
					if ( $request->has_param( $field ) ) {
						$fields[ $field ] = $request->get_param( $field );
					}
				}
				$result = $this->editor->create( $this->actor(), $fields );
				if ( $result instanceof \WP_Error ) {
					return $result;
				}
				return $this->created( $result );
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
				$actor  = $this->actor();
				$fields = array();
				foreach ( GalleryEditor::UPDATE_FIELDS as $field ) {
					if ( $request->has_param( $field ) ) {
						$fields[ $field ] = $request->get_param( $field );
					}
				}
				$result = $this->editor->update( $actor, $this->route_id( $request, 'id' ), $this->expected_revision( $request )->value, $fields );
				return $result instanceof \WP_Error ? $result : new \WP_REST_Response( $result, 200 );
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
				$gallery  = $this->viewable( $actor, $this->route_id( $request, 'id' ) );
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
				$title = $this->string_param( $request, 'title' );
				return $this->created( $this->editor->duplicate( $this->actor(), $this->route_id( $request, 'id' ), $title ) );
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
				return new \WP_REST_Response( $this->editor->restore( $this->actor(), $this->route_id( $request, 'id' ), $this->expected_revision( $request )->value ), 200 );
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
				'perPage'           => array(
					'description' => 'Items per page of this gallery; null uses the site-wide setting.',
					'type'        => array( 'integer', 'null' ),
					'minimum'     => GallerySettings::MIN_PER_PAGE,
					'maximum'     => GallerySettings::MAX_PER_PAGE,
					'context'     => array( 'view', 'edit' ),
				),
				'hover'             => array(
					'description'          => 'Hover texts of the images (title, caption, animation); null uses the site-wide setting.',
					'type'                 => array( 'object', 'null' ),
					'additionalProperties' => false,
					'properties'           => array(
						'title'     => array( 'type' => 'boolean' ),
						'caption'   => array( 'type' => 'boolean' ),
						'animation' => array(
							'type' => 'string',
							'enum' => array_map( static fn( HoverAnimation $animation ): string => $animation->value, HoverAnimation::cases() ),
						),
					),
					'context'              => array( 'view', 'edit' ),
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
	 * @param Actor $actor Actor.
	 * @param int   $id    Gallery id.
	 * @throws GalleryNotFoundException When missing or not viewable (the same answer, so existence does not leak).
	 */
	private function viewable( Actor $actor, int $id ): Gallery {
		return $this->editor->viewable( $actor, $id );
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
	 * @param Actor   $actor    Actor.
	 * @param Gallery $gallery  Gallery.
	 * @param int     $revision Revision.
	 * @return array<string, mixed>
	 */
	private function present( Actor $actor, Gallery $gallery, int $revision ): array {
		$counts = $this->relations->count_by_gallery( array( $gallery->id ) );
		return $this->presenter->present( $actor, $gallery, $revision, $counts[ $gallery->id ] ?? 0 );
	}

	/**
	 * @param array<string, mixed> $gallery Presented gallery.
	 */
	private function created( array $gallery ): \WP_REST_Response {
		$response = new \WP_REST_Response( $gallery, 201 );
		$response->header( 'Location', rest_url( sprintf( '%s/%s/%d', $this->namespace, $this->rest_base, (int) $gallery['id'] ) ) );
		return $response;
	}
}
