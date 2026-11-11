<?php
/**
 * Status route of swg/v1: sanitized system diagnostics.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Rest;

/**
 * Read-only, `manage_options` only; the payload is built by {@see StatusReport} and contains no secrets or paths.
 */
final class StatusController extends AbstractController {

	/**
	 * REST base of the route.
	 *
	 * @var string
	 */
	protected $rest_base = 'status';

	public function __construct( ErrorMapper $errors, private readonly StatusReport $report ) {
		parent::__construct( $errors );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			'/' . $this->rest_base,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'get_item_permissions_check' ),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return true|\WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		return $this->require_capability( 'manage_options' );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		return $this->guard( fn(): \WP_REST_Response => new \WP_REST_Response( $this->report->build() ) );
	}
}
