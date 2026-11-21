<?php
/**
 * Preview route of swg/v1: renders unsaved gallery state for the editor.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the framework independent core's value objects expose camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Rest;

use SWGallery\Core\Application\Render\EntryPoint;
use SWGallery\Core\Application\Render\RenderDiagnostic;
use SWGallery\Core\Application\Service\GalleryItemEditService;
use SWGallery\Core\Contract\GalleryRepositoryInterface;
use SWGallery\Core\Contract\LayoutRegistryInterface;
use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Gallery\Gallery;
use SWGallery\Core\Domain\Gallery\GallerySettings;
use SWGallery\Core\Domain\Gallery\HoverAnimation;
use SWGallery\Core\Domain\Gallery\HoverEffect;
use SWGallery\Core\Domain\Gallery\SortMode;
use SWGallery\Core\Domain\Settings\SettingsSchemaValidator;
use SWGallery\Core\Exception\AccessDeniedException;
use SWGallery\Core\Exception\GalleryNotFoundException;
use SWGallery\Core\Exception\UnknownLayoutException;
use SWGallery\Core\Exception\ValidationFailedException;
use SWGallery\WordPress\Frontend\FrontendAssets;
use SWGallery\WordPress\Rendering\DiagnosticMarkup;
use SWGallery\WordPress\Rendering\GalleryRenderer;
use SWGallery\WordPress\Security\WpGalleryAccessPolicy;

/**
 * `POST /galleries/{id}/preview`: the same renderer as every other surface, fed with unsaved `layout`, `sortMode` and
 * `settings` (validated like the gallery PATCH, never persisted) plus `page` and `perPage`. The actor must be able to
 * edit the gallery (404 when it is not viewable, 403 when viewable but not editable); the explicit preview rule of the
 * access policy applies (drafts, private and password-protected galleries render for their editors, the trash never).
 * Nothing is cached or stored; the response is sent with `Cache-Control: no-store`.
 */
final class PreviewController extends AbstractController {

	private const ID_PATTERN = '(?P<id>[1-9]\d{0,9})';

	/**
	 * REST base of the route.
	 *
	 * @var string
	 */
	protected $rest_base = 'galleries';

	/**
	 * @param ErrorMapper                $errors       Shared error mapper.
	 * @param GalleryRepositoryInterface $galleries    Stored galleries.
	 * @param WpGalleryAccessPolicy      $access       The shared access policy.
	 * @param LayoutRegistryInterface    $layouts      Registered layouts.
	 * @param \Closure                   $renderer_for Gallery => GalleryRenderer that sees the given (unsaved) gallery state.
	 * @param FrontendAssets             $assets       Source of the preview asset URLs.
	 */
	public function __construct(
		ErrorMapper $errors,
		private readonly GalleryRepositoryInterface $galleries,
		private readonly WpGalleryAccessPolicy $access,
		private readonly LayoutRegistryInterface $layouts,
		private readonly \Closure $renderer_for,
		private readonly FrontendAssets $assets
	) {
		parent::__construct( $errors );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			'/' . $this->rest_base . '/' . self::ID_PATTERN . '/preview',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_item' ),
				'permission_callback' => array( $this, 'create_item_permissions_check' ),
				'args'                => $this->with_settings_limits( $this->validated( $this->route_args() ) ),
			)
		);
	}

	/**
	 * Coarse check; the object-level rule runs in the handler.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return true|\WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		return $this->require_capability( 'edit_posts' );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public function create_item( $request ): \WP_REST_Response|\WP_Error {
		return $this->guard(
			function () use ( $request ): \WP_REST_Response {
				$actor   = $this->actor();
				$gallery = $this->editable( $actor, (int) $request->get_param( 'id' ) );
				$gallery = $this->with_unsaved_state( $gallery, $request );

				$options = array();
				if ( $request->has_param( 'page' ) ) {
					$options['page'] = absint( $request->get_param( 'page' ) );
				}
				if ( $request->has_param( 'perPage' ) ) {
					$options['per_page'] = absint( $request->get_param( 'perPage' ) );
				}
				$renderer = ( $this->renderer_for )( $gallery );
				$result   = $renderer->render( $gallery->id, EntryPoint::Preview, $options );

				$html = $result->html;
				$bar  = DiagnosticMarkup::render( $result->diagnostics );
				if ( '' !== $bar && str_starts_with( $html, $bar ) ) {
					$html = substr( $html, strlen( $bar ) );
				}
				$response = new \WP_REST_Response(
					array(
						'html'        => $html,
						'layoutId'    => $result->layoutId ?? $gallery->settings->layout,
						'page'        => $result->page,
						'totalPages'  => $result->totalPages,
						'totalItems'  => $result->totalItems,
						'diagnostics' => array_map(
							static fn( RenderDiagnostic $diagnostic ): array => array(
								'code'    => $diagnostic->code,
								'message' => DiagnosticMarkup::message( $diagnostic ),
							),
							$result->diagnostics
						),
						'assets'      => $this->assets->preview_assets(),
					),
					200
				);
				$response->header( 'Cache-Control', 'no-store' );
				return $response;
			}
		);
	}

	/**
	 * @param Actor $actor Actor.
	 * @param int   $id    Gallery id.
	 * @throws GalleryNotFoundException When missing or not viewable (the same answer, so existence does not leak).
	 * @throws AccessDeniedException    When viewable but not editable.
	 */
	private function editable( Actor $actor, int $id ): Gallery {
		$gallery = $this->galleries->find( $id );
		if ( null === $gallery || ! $this->access->canView( $actor, $gallery ) ) {
			throw new GalleryNotFoundException();
		}
		if ( ! $this->access->canEdit( $actor, $gallery ) ) {
			throw new AccessDeniedException();
		}
		return $gallery;
	}

	/**
	 * Applies the submitted layout, sort mode and settings in memory, validated like the gallery PATCH.
	 *
	 * @param Gallery          $gallery Stored gallery.
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @throws UnknownLayoutException    When the layout is not registered.
	 * @throws ValidationFailedException When the settings violate the layout schema.
	 */
	private function with_unsaved_state( Gallery $gallery, \WP_REST_Request $request ): Gallery {
		$settings = $gallery->settings;
		if ( $request->has_param( 'layout' ) ) {
			$layout = $request->get_param( 'layout' );
			if ( ! is_string( $layout ) || ! $this->layouts->has( $layout ) ) {
				throw new UnknownLayoutException();
			}
			$settings = $settings->withLayout( $layout );
		}
		if ( $request->has_param( 'sortMode' ) ) {
			$settings = $settings->withSortMode( SortMode::from( (string) $request->get_param( 'sortMode' ) ) );
		}
		if ( $request->has_param( 'galleryPerPage' ) ) {
			$per_page = $request->get_param( 'galleryPerPage' );
			$settings = $settings->withPerPage( is_numeric( $per_page ) ? (int) $per_page : null );
		}
		if ( $request->has_param( 'galleryHover' ) ) {
			$settings = $settings->withHover( HoverEffect::fromArray( $request->get_param( 'galleryHover' ) ) );
		}
		if ( $request->has_param( 'settings' ) ) {
			$values = array();
			foreach ( (array) $request->get_param( 'settings' ) as $key => $value ) {
				$values[ (string) $key ] = $value;
			}
			$settings = $settings->withValues( $values );
		}
		if ( $request->has_param( 'layout' ) || $request->has_param( 'settings' ) ) {
			$schema = $this->layouts->get( $settings->layout )->getSettingsSchema();
			unset( $schema[ GalleryItemEditService::ITEM_SCHEMA_KEY ] );
			$result = ( new SettingsSchemaValidator() )->validate( $schema, $settings->values );
			if ( ! $result->isValid() ) {
				throw new ValidationFailedException( $result->errors );
			}
		}
		return $gallery->withSettings( $settings );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function route_args(): array {
		return array(
			'id'             => array(
				'description'       => 'Gallery id.',
				'type'              => 'integer',
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'layout'         => array(
				'description' => 'Unsaved layout id.',
				'type'        => 'string',
				'pattern'     => '^[a-z][a-z0-9_-]{0,63}$',
			),
			'sortMode'       => array(
				'description' => 'Unsaved item order mode.',
				'type'        => 'string',
				'enum'        => array_map( static fn( SortMode $mode ): string => $mode->value, SortMode::cases() ),
			),
			'galleryPerPage' => array(
				'description' => 'Unsaved items per page of the gallery; null uses the site-wide setting.',
				'type'        => array( 'integer', 'null' ),
				'minimum'     => GallerySettings::MIN_PER_PAGE,
				'maximum'     => GallerySettings::MAX_PER_PAGE,
			),
			'galleryHover'   => array(
				'description'          => 'Unsaved hover texts of the gallery; null uses the site-wide setting.',
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
			),
			'settings'       => array(
				'description'          => 'Unsaved layout settings (validated against the layout schema, replaces the stored map).',
				'type'                 => 'object',
				'additionalProperties' => true,
			),
			'page'           => array(
				'description'       => 'Page to render, starting at 1.',
				'type'              => 'integer',
				'minimum'           => 1,
				'maximum'           => self::MAX_PAGE,
				'sanitize_callback' => 'absint',
			),
			'perPage'        => array(
				'description'       => 'Items per page, 1 to 200.',
				'type'              => 'integer',
				'minimum'           => 1,
				'maximum'           => 200,
				'sanitize_callback' => 'absint',
			),
		);
	}
}
