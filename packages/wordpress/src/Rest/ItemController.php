<?php
/**
 * Item and order routes of swg/v1: list, bulk assign, update, remove, bulk remove and reorder.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the framework independent core's value objects expose camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Rest;

use SWGallery\Core\Application\Service\GalleryItemEditService;
use SWGallery\Core\Application\Service\GalleryItemService;
use SWGallery\Core\Contract\GalleryRepositoryInterface;
use SWGallery\Core\Contract\RevisionStoreInterface;
use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Gallery\Gallery;
use SWGallery\Core\Domain\Gallery\ItemOverrides;
use SWGallery\Core\Domain\Gallery\Revision;
use SWGallery\Core\Exception\GalleryNotFoundException;
use SWGallery\Core\Exception\ItemNotFoundException;
use SWGallery\WordPress\Repository\WpdbGalleryItemRepository;
use SWGallery\WordPress\Security\WpGalleryAccessPolicy;

/**
 * Thin HTTP adapter over {@see GalleryItemService} and {@see GalleryItemEditService}. Like the gallery routes, every
 * route first loads the gallery and answers the identical 404 when the actor may not view it; a viewer who may not
 * edit gets 403 from the services, which enforce the access policy themselves.
 *
 * Arguments that carry the payload (`attachmentIds`, `itemIds`) are validated against a schema but are not marked
 * `required`: WordPress validates arguments before the permission callback, so a required argument would turn an
 * anonymous request into 400 instead of 401. The handlers answer 400 `rest_missing_callback_param` themselves.
 */
final class ItemController extends AbstractController {

	public const MAX_BATCH      = GalleryItemService::MAX_BATCH;
	public const MAX_ORDER      = 10000;
	public const ITEMS_PER_PAGE = 50;
	public const TITLE_MAX      = 200;
	public const CAPTION_MAX    = 5000;
	public const ALT_MAX        = 500;

	private const ID_PATTERN   = '(?P<id>[1-9]\d{0,9})';
	private const ITEM_PATTERN = '(?P<itemId>[1-9]\d{0,9})';

	public function __construct(
		ErrorMapper $errors,
		private readonly GalleryItemService $items,
		private readonly GalleryItemEditService $edits,
		private readonly GalleryRepositoryInterface $galleries,
		private readonly WpdbGalleryItemRepository $item_repository,
		private readonly RevisionStoreInterface $revisions,
		private readonly WpGalleryAccessPolicy $access,
		private readonly ItemPresenter $presenter
	) {
		parent::__construct( $errors );
		$this->rest_base = 'galleries';
	}

	public function register_routes(): void {
		$id_arg   = array(
			'description'       => 'Gallery id.',
			'type'              => 'integer',
			'minimum'           => 1,
			'sanitize_callback' => 'absint',
		);
		$item_arg = array(
			'description'       => 'Item id (not the attachment id).',
			'type'              => 'integer',
			'minimum'           => 1,
			'sanitize_callback' => 'absint',
		);
		$base     = '/' . $this->rest_base . '/' . self::ID_PATTERN;
		$revision = array( 'expectedRevision' => $this->expected_revision_arg() );

		register_rest_route(
			self::NAMESPACE_V1,
			$base . '/items',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->validated( array( 'id' => $id_arg ) + $this->items_pagination_args() ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'assign_items' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->validated(
						array(
							'id'            => $id_arg,
							'attachmentIds' => array(
								'description' => 'Attachment ids to add (1 to 500, duplicates are ignored).',
								'type'        => 'array',
								'items'       => array(
									'type'    => 'integer',
									'minimum' => 1,
								),
								'minItems'    => 1,
								'maxItems'    => self::MAX_BATCH,
							),
						) + $revision
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			$base . '/items/bulk-remove',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk_remove' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => $this->validated(
					array(
						'id'      => $id_arg,
						'itemIds' => array(
							'description' => 'Item ids whose relation is removed (1 to 500).',
							'type'        => 'array',
							'items'       => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
							'minItems'    => 1,
							'maxItems'    => self::MAX_BATCH,
						),
					) + $revision
				),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			$base . '/items/' . self::ITEM_PATTERN,
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->with_settings_limits(
						$this->validated(
							array(
								'id'        => $id_arg,
								'itemId'    => $item_arg,
								'overrides' => array(
									'description'          => 'Partial overrides; absent fields keep their stored value, null inherits, an empty string is an intentional blank.',
									'type'                 => 'object',
									'properties'           => array(
										'title'   => array(
											'type'      => array( 'string', 'null' ),
											'maxLength' => self::TITLE_MAX,
										),
										'caption' => array(
											'type'      => array( 'string', 'null' ),
											'maxLength' => self::CAPTION_MAX,
										),
										'alt'     => array(
											'type'      => array( 'string', 'null' ),
											'maxLength' => self::ALT_MAX,
										),
									),
									'additionalProperties' => false,
									'sanitize_callback'    => array( self::class, 'sanitize_overrides' ),
								),
								'settings'  => array(
									'description' => 'Item settings map; replaces the stored map and is validated against the layout.',
									'type'        => 'object',
								),
							) + $revision
						)
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->validated(
						array(
							'id'     => $id_arg,
							'itemId' => $item_arg,
						) + $revision
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			$base . '/order',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'reorder' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => $this->validated(
					array(
						'id'      => $id_arg,
						'itemIds' => array(
							'description' => 'The complete list of the gallery\'s item ids in the new order.',
							'type'        => 'array',
							'items'       => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
							'maxItems'    => self::MAX_ORDER,
						),
					) + $revision
				),
			)
		);
	}

	/**
	 * Coarse check shared by all item routes (same rule as the gallery routes: the admin app is for users who can write
	 * posts); object-level decisions follow in the handlers and the services.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return true|\WP_Error
	 */
	public function permissions_check( $request ) {
		return $this->require_capability( 'edit_posts' );
	}

	/**
	 * Items in stored manual order. `X-SWG-Revision` carries the gallery revision the page belongs to.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public function get_items( $request ): \WP_REST_Response|\WP_Error {
		return $this->guard(
			function () use ( $request ): \WP_REST_Response {
				$actor    = $this->actor();
				$gallery  = $this->viewable( $actor, (int) $request->get_param( 'id' ) );
				$page     = max( 1, (int) $request->get_param( 'page' ) );
				$per_page = max( 1, (int) $request->get_param( 'per_page' ) );
				$rows     = $this->item_repository->findPageWithSettings( $gallery->id, ( $page - 1 ) * $per_page, $per_page );
				$total    = $this->item_repository->count( $gallery->id );

				$response = $this->with_pagination_headers( new \WP_REST_Response( $this->presenter->present_many( $actor, $rows ), 200 ), $total, $per_page );
				$response->header( 'X-SWG-Revision', (string) $this->revisions->current( $gallery->id )->value );
				return $response;
			}
		);
	}

	/**
	 * Bulk assign. 200 with the honest core result even when some ids were skipped.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public function assign_items( $request ): \WP_REST_Response|\WP_Error {
		return $this->guard(
			function () use ( $request ): \WP_REST_Response|\WP_Error {
				$actor   = $this->actor();
				$gallery = $this->viewable( $actor, (int) $request->get_param( 'id' ) );
				$ids     = $this->id_list( $request, 'attachmentIds' );
				if ( $ids instanceof \WP_Error ) {
					return $ids;
				}
				$result = $this->items->assign( $actor, $gallery->id, $ids, $this->expected_revision( $request ) );
				return new \WP_REST_Response(
					array(
						'added'           => $result->added,
						'alreadyAssigned' => $result->alreadyAssigned,
						'missing'         => $result->missing,
						'revision'        => $result->revision->value,
					),
					200
				);
			}
		);
	}

	/**
	 * Partial update. Absent override fields keep their stored value; `settings` replaces the item's map. When both
	 * parts are sent, settings are validated and written first, then the overrides (two revision steps).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public function update_item( $request ): \WP_REST_Response|\WP_Error {
		return $this->guard(
			function () use ( $request ): \WP_REST_Response|\WP_Error {
				$actor   = $this->actor();
				$gallery = $this->viewable( $actor, (int) $request->get_param( 'id' ) );
				if ( ! $this->access->canEdit( $actor, $gallery ) ) {
					throw new \SWGallery\Core\Exception\AccessDeniedException();
				}
				$item_id   = (int) $request->get_param( 'itemId' );
				$overrides = $request->has_param( 'overrides' ) ? (array) $request->get_param( 'overrides' ) : array();
				$settings  = $request->has_param( 'settings' ) ? $this->settings_param( $request ) : null;
				if ( $settings instanceof \WP_Error ) {
					return $settings;
				}
				if ( array() === $overrides && null === $settings ) {
					return ErrorMapper::bad_request( GalleryController::CODE_NO_CHANGES, __( 'The request does not change anything.', 'sw-gallery' ) );
				}
				$current  = $this->item_repository->findWithSettings( $gallery->id, $item_id ) ?? throw new ItemNotFoundException();
				$expect   = $this->expected_revision( $request );
				$revision = $expect;
				if ( null !== $settings ) {
					$revision = $this->edits->setSettings( $actor, $gallery->id, $item_id, $settings, $expect )->revision;
				}
				if ( array() !== $overrides ) {
					$revision = $this->edits->updateOverrides( $actor, $gallery->id, $item_id, $this->merged( $current->item->overrides, $overrides ), $revision );
				}
				return new \WP_REST_Response(
					array(
						'item'     => $this->stored_item( $actor, $gallery->id, $item_id ),
						'revision' => $revision->value,
					),
					200
				);
			}
		);
	}

	/**
	 * Removes the relation only; the attachment is never touched.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public function delete_item( $request ): \WP_REST_Response|\WP_Error {
		return $this->guard(
			function () use ( $request ): \WP_REST_Response {
				$actor    = $this->actor();
				$gallery  = $this->viewable( $actor, (int) $request->get_param( 'id' ) );
				$item_id  = (int) $request->get_param( 'itemId' );
				$revision = $this->items->remove( $actor, $gallery->id, $item_id, $this->expected_revision( $request ) );
				return new \WP_REST_Response(
					array(
						'id'       => $item_id,
						'removed'  => true,
						'revision' => $revision->value,
					),
					200
				);
			}
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public function bulk_remove( $request ): \WP_REST_Response|\WP_Error {
		return $this->guard(
			function () use ( $request ): \WP_REST_Response|\WP_Error {
				$actor   = $this->actor();
				$gallery = $this->viewable( $actor, (int) $request->get_param( 'id' ) );
				$ids     = $this->id_list( $request, 'itemIds' );
				if ( $ids instanceof \WP_Error ) {
					return $ids;
				}
				$result = $this->items->removeMany( $actor, $gallery->id, $ids, $this->expected_revision( $request ) );
				return new \WP_REST_Response(
					array(
						'removed'  => $result->removed,
						'notFound' => $result->notFound,
						'revision' => $result->revision->value,
					),
					200
				);
			}
		);
	}

	/**
	 * Atomic reorder; the list must be an exact permutation of the gallery's item ids (422 `swg_invalid_order`).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public function reorder( $request ): \WP_REST_Response|\WP_Error {
		return $this->guard(
			function () use ( $request ): \WP_REST_Response|\WP_Error {
				$actor   = $this->actor();
				$gallery = $this->viewable( $actor, (int) $request->get_param( 'id' ) );
				$ids     = $this->id_list( $request, 'itemIds' );
				if ( $ids instanceof \WP_Error ) {
					return $ids;
				}
				$revision = $this->items->reorder( $actor, $gallery->id, $ids, $this->expected_revision( $request ) );
				return new \WP_REST_Response(
					array(
						'itemCount' => count( $ids ),
						'revision'  => $revision->value,
					),
					200
				);
			}
		);
	}

	/**
	 * Sanitizer of the `overrides` object: plain text only (`caption` keeps line breaks), `null` stays `null`.
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function sanitize_overrides( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $value as $field => $text ) {
			if ( ! is_string( $text ) ) {
				continue;
			}
			$value[ $field ] = 'caption' === $field ? sanitize_textarea_field( $text ) : sanitize_text_field( $text );
		}
		return $value;
	}

	/**
	 * @param Actor $actor Requesting actor.
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
	 * The item as stored after a write.
	 *
	 * @param Actor $actor      Requesting actor.
	 * @param int   $gallery_id Gallery id.
	 * @param int   $item_id    Item id.
	 * @return array<string, mixed>
	 * @throws ItemNotFoundException When the item vanished meanwhile.
	 */
	private function stored_item( Actor $actor, int $gallery_id, int $item_id ): array {
		$row = $this->item_repository->findWithSettings( $gallery_id, $item_id ) ?? throw new ItemNotFoundException();
		return $this->presenter->present( $actor, $row );
	}

	/**
	 * Applies a partial overrides map on top of the stored overrides.
	 *
	 * @param ItemOverrides        $current Stored overrides.
	 * @param array<string, mixed> $patch   Submitted (sanitized) fields.
	 */
	private function merged( ItemOverrides $current, array $patch ): ItemOverrides {
		return new ItemOverrides(
			array_key_exists( 'title', $patch ) ? $this->nullable_text( $patch['title'] ) : $current->title,
			array_key_exists( 'caption', $patch ) ? $this->nullable_text( $patch['caption'] ) : $current->caption,
			array_key_exists( 'alt', $patch ) ? $this->nullable_text( $patch['alt'] ) : $current->alt
		);
	}

	private function nullable_text( mixed $value ): ?string {
		return is_string( $value ) ? $value : null;
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return array<string, mixed>|\WP_Error
	 */
	private function settings_param( \WP_REST_Request $request ): array|\WP_Error {
		$raw = (array) $request->get_param( 'settings' );
		if ( array() !== $raw && array_is_list( $raw ) ) {
			return ErrorMapper::bad_request( 'rest_invalid_param', __( 'The settings must be an object.', 'sw-gallery' ) );
		}
		$values = array();
		foreach ( $raw as $key => $value ) {
			$values[ (string) $key ] = $value;
		}
		return $values;
	}

	/**
	 * Reads the list parameter (bounds come from the route schema); missing means 400 (see the class comment).
	 *
	 * @param \WP_REST_Request $request    Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @param string           $name       Parameter name.
	 * @return list<int>|\WP_Error
	 */
	private function id_list( \WP_REST_Request $request, string $name ): array|\WP_Error {
		$raw = $request->get_param( $name );
		if ( ! is_array( $raw ) ) {
			return new \WP_Error(
				'rest_missing_callback_param',
				__( 'A required parameter is missing.', 'sw-gallery' ),
				array( 'status' => 400 )
			);
		}
		return array_values( array_map( 'intval', $raw ) );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function items_pagination_args(): array {
		$args                        = $this->pagination_args();
		$args['per_page']['default'] = self::ITEMS_PER_PAGE;
		return $args;
	}
}
