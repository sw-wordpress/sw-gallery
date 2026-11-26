<?php
/**
 * Base of the swg/v1 REST controllers.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Rest;

use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Gallery\Revision;
use SWGallery\Core\Exception\AccessDeniedException;

/**
 * Shared plumbing: namespace, actor, coarse permission checks, failure mapping, pagination and revision arguments.
 * Controllers receive their services through the constructor; only the registrar reads the service holder.
 * Permission callbacks are coarse (logged in, capability); object-level decisions are made by the access policy
 * and the core services, which enforce them as well.
 */
abstract class AbstractController extends \WP_REST_Controller {

	public const NAMESPACE_V1 = 'swg/v1';
	public const MAX_PER_PAGE = 100;
	public const PER_PAGE     = 20;
	public const MAX_PAGE     = 100000;

	/** Bounds of a submitted settings map (layout or item settings): legitimate maps are a few hundred bytes. */
	public const SETTINGS_MAX_BYTES = 32768;
	public const SETTINGS_MAX_DEPTH = 6;
	public const SETTINGS_MAX_KEYS  = 200;

	/**
	 * REST namespace of every route.
	 *
	 * @var string
	 */
	protected $namespace = self::NAMESPACE_V1;

	public function __construct( protected readonly ErrorMapper $errors ) {
	}

	/** The user of the current request (anonymous when the REST authentication did not accept the credentials). */
	protected function actor(): Actor {
		return new Actor( max( 0, get_current_user_id() ) );
	}

	/**
	 * Coarse permission check shared by all routes: anonymous visitors get 401, users without the capability 403.
	 *
	 * @param string $capability Capability the user needs for the route family.
	 * @return true|\WP_Error
	 */
	protected function require_capability( string $capability ) {
		return $this->require_allowed( current_user_can( $capability ) );
	}

	/**
	 * @param bool $allowed Result of the coarse check (already evaluated for the current user).
	 * @return true|\WP_Error
	 */
	protected function require_allowed( bool $allowed ) {
		if ( $allowed ) {
			return true;
		}
		return $this->errors->map( new AccessDeniedException(), $this->actor() );
	}

	/**
	 * Runs a handler and turns every failure into a safe REST error.
	 *
	 * @param callable():(\WP_REST_Response|\WP_Error) $handler The route handler body.
	 */
	protected function guard( callable $handler ): \WP_REST_Response|\WP_Error {
		try {
			return $handler();
		} catch ( \Throwable $error ) {
			return $this->errors->map( $error, $this->actor() );
		}
	}

	/**
	 * Reads the identity matched by the route. Query and body arguments are never used to select the object.
	 *
	 * @param \WP_REST_Request $request Matched request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @param string           $name    Named path capture.
	 */
	protected function route_id( \WP_REST_Request $request, string $name ): int {
		return (int) $request->get_url_params()[ $name ];
	}

	/**
	 * @param \WP_REST_Request $request Request carrying the validated `expectedRevision`.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	protected function expected_revision( \WP_REST_Request $request ): Revision {
		$value = $request->get_param( 'expectedRevision' );
		return new Revision( is_numeric( $value ) ? (int) $value : 0 );
	}

	/**
	 * WordPress only validates against the schema when an argument names its validator (the schema-derived arguments
	 * do); hand-written arguments get it added here so types, bounds and enums are always enforced (HTTP 400).
	 *
	 * @param array<string, array<string, mixed>> $args Argument definitions.
	 * @return array<string, array<string, mixed>>
	 */
	protected function validated( array $args ): array {
		foreach ( $args as $name => $definition ) {
			$args[ $name ]['validate_callback'] = $definition['validate_callback'] ?? 'rest_validate_request_arg';
		}
		return $args;
	}

	/**
	 * Schema validation plus the size bounds of a settings map. Unknown keys inside a map are kept on purpose (they
	 * belong to layouts that are not active), so the bounds are what keeps a request from filling the database.
	 *
	 * @param mixed            $value   Submitted value.
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @param string           $param   Argument name.
	 * @return true|\WP_Error
	 */
	public static function validate_settings_map( mixed $value, \WP_REST_Request $request, string $param ) {
		$valid = rest_validate_request_arg( $value, $request, $param );
		if ( true !== $valid ) {
			return $valid;
		}
		if ( is_array( $value ) && ! self::settings_within_limits( $value ) ) {
			return new \WP_Error(
				'rest_invalid_param',
				/* translators: %s: argument name. */
				sprintf( __( '%s is too large or nested too deeply.', 'sw-gallery' ), $param ),
				array( 'status' => 400 )
			);
		}
		return true;
	}

	/**
	 * Whether a settings map respects the size bounds (bytes, depth, keys); shared with the MCP abilities.
	 *
	 * @param array<mixed> $value Submitted map.
	 */
	public static function settings_within_limits( array $value ): bool {
		return self::settings_shape_ok( $value, 1 ) && strlen( (string) wp_json_encode( $value ) ) <= self::SETTINGS_MAX_BYTES;
	}

	/**
	 * @param array<mixed> $value Map or list.
	 * @param int          $depth Nesting level of $value.
	 */
	private static function settings_shape_ok( array $value, int $depth ): bool {
		if ( $depth > self::SETTINGS_MAX_DEPTH || count( $value ) > self::SETTINGS_MAX_KEYS ) {
			return false;
		}
		foreach ( $value as $entry ) {
			if ( is_array( $entry ) && ! self::settings_shape_ok( $entry, $depth + 1 ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Adds the size bounds to the `settings` argument of a route.
	 *
	 * @param array<string, array<string, mixed>> $args Argument definitions.
	 * @return array<string, array<string, mixed>>
	 */
	protected function with_settings_limits( array $args ): array {
		if ( isset( $args['settings'] ) ) {
			$args['settings']['validate_callback'] = array( self::class, 'validate_settings_map' );
		}
		return $args;
	}

	/**
	 * Argument definition of `expectedRevision` (required on every write except create and duplicate).
	 *
	 * @return array<string, mixed>
	 */
	protected function expected_revision_arg(): array {
		return array(
			'description'       => 'Revision the client last saw; a different stored revision answers 409.',
			'type'              => 'integer',
			'minimum'           => 0,
			'required'          => true,
			'sanitize_callback' => 'absint',
		);
	}

	/**
	 * Argument definitions of `page` and `per_page`.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function pagination_args(): array {
		return array(
			'page'     => array(
				'description'       => 'Page number, starting at 1.',
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'maximum'           => self::MAX_PAGE,
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'description'       => 'Items per page, 1 to 100.',
				'type'              => 'integer',
				'default'           => self::PER_PAGE,
				'minimum'           => 1,
				'maximum'           => self::MAX_PER_PAGE,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Adds the standard pagination headers.
	 *
	 * @param \WP_REST_Response $response Response to extend.
	 * @param int               $total    Total number of matches.
	 * @param int               $per_page Page size.
	 */
	protected function with_pagination_headers( \WP_REST_Response $response, int $total, int $per_page ): \WP_REST_Response {
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / max( 1, $per_page ) ) );
		return $response;
	}

	/**
	 * Sanitizer for single-line text that tolerates non-strings (validation reports those).
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function sanitize_line( mixed $value ): mixed {
		return is_string( $value ) ? sanitize_text_field( $value ) : $value;
	}

	/**
	 * Sanitizer for multi-line plain text (decision F-10).
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function sanitize_multiline( mixed $value ): mixed {
		return is_string( $value ) ? sanitize_textarea_field( $value ) : $value;
	}

	/**
	 * Sanitizer for ids that may be null.
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function sanitize_nullable_id( mixed $value ): mixed {
		return is_numeric( $value ) ? absint( $value ) : $value;
	}
}
