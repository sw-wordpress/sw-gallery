<?php
/**
 * Layout route of swg/v1: the registered layouts with their settings schemas.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Rest;

use SWGallery\Core\Contract\LayoutRegistryInterface;

/**
 * Read-only. The admin app renders its layout and settings forms from the schemas served here.
 */
final class LayoutController extends AbstractController {

	/**
	 * REST base of the route.
	 *
	 * @var string
	 */
	protected $rest_base = 'layouts';

	public function __construct( ErrorMapper $errors, private readonly LayoutRegistryInterface $layouts, private readonly LayoutPresenter $presenter ) {
		parent::__construct( $errors );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			'/' . $this->rest_base,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
			)
		);
	}

	/**
	 * Same audience as the gallery routes: users who may use the admin app.
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
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ) {
		return $this->guard( fn(): \WP_REST_Response => new \WP_REST_Response( $this->presenter->collection( $this->layouts ) ) );
	}
}
