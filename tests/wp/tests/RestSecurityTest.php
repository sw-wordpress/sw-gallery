<?php
/**
 * REST security invariants: the role matrix over every gallery route (own and foreign galleries), no unauthenticated
 * route (decision D5), an explicit permission callback everywhere and no leakage of internals.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Application\Service\GalleryService;
use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Core\Domain\Gallery\Revision;
use SWGallery\Core\Domain\Settings\SettingsSchemaValidator;
use SWGallery\Tests\Support\RecordingLogger;
use SWGallery\Tests\Support\RestFixtures;
use SWGallery\Tests\Support\ThrowingGalleries;
use SWGallery\WordPress\Infrastructure\Services;
use SWGallery\WordPress\Rest\ErrorMapper;
use SWGallery\WordPress\Gallery\GalleryEditor;
use SWGallery\WordPress\Rest\GalleryController;
use SWGallery\WordPress\Rest\GalleryPresenter;

class RestSecurityTest extends SwgTestCase {

	use RestFixtures;

	private const ROLES = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber', 'anonymous' );

	/** Expected status per route and role for: own gallery, foreign draft, foreign published gallery. */
	private const MATRIX = array(
		'get'       => array(
			'administrator' => array( 200, 200, 200 ),
			'editor'        => array( 200, 200, 200 ),
			'author'        => array( 200, 404, 200 ),
			'contributor'   => array( 200, 404, 200 ),
			'subscriber'    => array( 403, 403, 403 ),
			'anonymous'     => array( 401, 401, 401 ),
		),
		'patch'     => array(
			'administrator' => array( 200, 200, 200 ),
			'editor'        => array( 200, 200, 200 ),
			'author'        => array( 200, 404, 403 ),
			'contributor'   => array( 200, 404, 403 ),
			'subscriber'    => array( 403, 403, 403 ),
			'anonymous'     => array( 401, 401, 401 ),
		),
		'trash'     => array(
			'administrator' => array( 200, 200, 200 ),
			'editor'        => array( 200, 200, 200 ),
			'author'        => array( 200, 404, 403 ),
			'contributor'   => array( 200, 404, 403 ),
			'subscriber'    => array( 403, 403, 403 ),
			'anonymous'     => array( 401, 401, 401 ),
		),
		'force'     => array(
			'administrator' => array( 200, 200, 200 ),
			'editor'        => array( 200, 200, 200 ),
			'author'        => array( 200, 404, 403 ),
			'contributor'   => array( 200, 404, 403 ),
			'subscriber'    => array( 403, 403, 403 ),
			'anonymous'     => array( 401, 401, 401 ),
		),
		'restore'   => array(
			'administrator' => array( 200, 200, 200 ),
			'editor'        => array( 200, 200, 200 ),
			'author'        => array( 200, 404, 404 ),
			'contributor'   => array( 200, 404, 404 ),
			'subscriber'    => array( 403, 403, 403 ),
			'anonymous'     => array( 401, 401, 401 ),
		),
		'duplicate' => array(
			'administrator' => array( 201, 201, 201 ),
			'editor'        => array( 201, 201, 201 ),
			'author'        => array( 201, 404, 201 ),
			'contributor'   => array( 201, 404, 201 ),
			'subscriber'    => array( 403, 403, 403 ),
			'anonymous'     => array( 401, 401, 401 ),
		),
	);

	public function set_up(): void {
		parent::set_up();
		$this->bootRest();
	}

	public function tear_down(): void {
		$this->shutdownRest();
		parent::tear_down();
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function matrix_cases(): array {
		$cases = array();
		foreach ( array_keys( self::MATRIX ) as $route ) {
			foreach ( self::ROLES as $role ) {
				$cases[ $route . ' as ' . $role ] = array( $route, $role );
			}
		}
		return $cases;
	}

	/**
	 * @dataProvider matrix_cases
	 */
	public function test_role_matrix( string $route, string $role ): void {
		$user    = 'anonymous' === $role ? 0 : $this->makeUser( $role );
		$stranger = $this->makeUser( 'author' );
		$expected = self::MATRIX[ $route ][ $role ];

		$scenarios = array(
			'own gallery'       => array( in_array( $role, array( 'anonymous', 'subscriber' ), true ) ? $stranger : $user, GalleryStatus::Draft ),
			'foreign draft'     => array( $stranger, GalleryStatus::Draft ),
			'foreign published' => array( $stranger, GalleryStatus::Publish ),
		);
		$index     = 0;
		foreach ( $scenarios as $label => $scenario ) {
			$gallery = $this->makeGallery( $scenario[0], $scenario[1], 'Matrix' );
			$revision = 1;
			if ( 'restore' === $route ) {
				Services::gallery_service()->trash( new Actor( $scenario[0] ), $gallery, new Revision( 1 ) );
				$revision = 2;
			}
			$response = $this->send( $route, $gallery, $revision, $user );
			$this->assertSame( $expected[ $index ], $response->get_status(), sprintf( '%s / %s / %s', $route, $role, $label ) );
			$this->assertNoLeak( $response );
			++$index;
		}
	}

	public function test_create_and_list_matrix(): void {
		$expected = array(
			'administrator' => 201,
			'editor'        => 201,
			'author'        => 201,
			'contributor'   => 201,
			'subscriber'    => 403,
			'anonymous'     => 401,
		);
		foreach ( $expected as $role => $status ) {
			$user = 'anonymous' === $role ? 0 : $this->makeUser( $role );
			$this->assertSame( $status, $this->call( 'POST', '/galleries', array( 'title' => 'Matrix' ), $user )->get_status(), 'create as ' . $role );
			$list = $this->call( 'GET', '/galleries', array(), $user );
			$this->assertSame( 201 === $status ? 200 : $status, $list->get_status(), 'list as ' . $role );
			$this->assertNoLeak( $list );
		}
	}

	public function test_no_swg_route_is_reachable_without_authentication(): void {
		$checked = 0;
		foreach ( rest_get_server()->get_routes( 'swg/v1' ) as $route => $handlers ) {
			if ( '/swg/v1' === $route ) {
				// WordPress' own namespace index lists route names and argument schemas, no plugin data.
				continue;
			}
			$path = (string) preg_replace( '/\(\?P<\w+>[^)]*\)/', '1', $route );
			foreach ( $handlers as $handler ) {
				foreach ( array_keys( (array) $handler['methods'] ) as $method ) {
					$request = new WP_REST_Request( $method, $path );
					$request->set_header( 'content-type', 'application/json' );
					$request->set_body( (string) wp_json_encode( array( 'title' => 'x', 'expectedRevision' => 1 ) ) );
					wp_set_current_user( 0 );
					$response = rest_do_request( $request );
					$this->assertSame( 401, $response->get_status(), $method . ' ' . $route );
					$checked++;
				}
			}
		}
		$this->assertGreaterThanOrEqual( 7, $checked, 'The routes were not discovered.' );
	}

	public function test_every_route_has_an_explicit_permission_callback(): void {
		$checked = 0;
		foreach ( rest_get_server()->get_routes( 'swg/v1' ) as $route => $handlers ) {
			if ( '/swg/v1' === $route ) {
				continue;
			}
			foreach ( $handlers as $handler ) {
				$this->assertArrayHasKey( 'permission_callback', $handler, $route );
				$this->assertIsCallable( $handler['permission_callback'], $route );
				$this->assertNotSame( '__return_true', $handler['permission_callback'], $route );
				++$checked;
			}
		}
		$this->assertGreaterThanOrEqual( 7, $checked );
	}

	public function test_every_route_refuses_users_without_edit_rights(): void {
		$subscriber = $this->makeUser( 'subscriber' );
		foreach ( rest_get_server()->get_routes( 'swg/v1' ) as $route => $handlers ) {
			if ( '/swg/v1' === $route ) {
				continue;
			}
			$path = (string) preg_replace( '/\(\?P<\w+>[^)]*\)/', '1', $route );
			foreach ( $handlers as $handler ) {
				foreach ( array_keys( (array) $handler['methods'] ) as $method ) {
					wp_set_current_user( $subscriber );
					$request = new WP_REST_Request( $method, $path );
					$request->set_header( 'content-type', 'application/json' );
					$request->set_body( (string) wp_json_encode( array( 'title' => 'x', 'expectedRevision' => 1 ) ) );
					$this->assertSame( 403, rest_do_request( $request )->get_status(), $method . ' ' . $route );
				}
			}
		}
	}

	public function test_the_routes_publish_a_schema_and_only_the_documented_methods(): void {
		$routes = rest_get_server()->get_routes( 'swg/v1' );

		$this->assertArrayHasKey( '/swg/v1/galleries', $routes );
		$methods = array();
		foreach ( $routes as $route => $handlers ) {
			foreach ( $handlers as $handler ) {
				$methods[ $route ][] = implode( ',', array_keys( (array) $handler['methods'] ) );
			}
		}
		$this->assertSame( array( 'GET', 'POST' ), $methods['/swg/v1/galleries'] );
		$item = '/swg/v1/galleries/(?P<id>[1-9]\d{0,9})';
		$this->assertSame( array( 'GET', 'PATCH', 'DELETE' ), $methods[ $item ] );

		wp_set_current_user( $this->makeUser( 'administrator' ) );
		$options = rest_do_request( new WP_REST_Request( 'OPTIONS', '/swg/v1/galleries' ) );
		$schema  = $this->data( $options )['schema'];
		$this->assertArrayHasKey( 'ownerId', $schema['properties'] );
		$this->assertTrue( $schema['properties']['revision']['readonly'] );
	}

	public function test_unexpected_failures_answer_500_without_details_and_are_logged(): void {
		$logger     = new RecordingLogger();
		$service    = new GalleryService( new ThrowingGalleries(), Services::revisions(), Services::transactions(), Services::access(), Services::events(), Services::layouts(), new SettingsSchemaValidator() );
		$controller = new GalleryController(
			new ErrorMapper( $logger ),
			$service,
			new ThrowingGalleries(),
			Services::revisions(),
			Services::relations(),
			Services::access(),
			new GalleryPresenter( Services::access() ),
			Services::settings(),
			new GalleryEditor( $service, new ThrowingGalleries(), Services::revisions(), Services::relations(), Services::access(), new GalleryPresenter( Services::access() ) )
		);
		$admin      = $this->makeUser( 'administrator' );
		wp_set_current_user( $admin );

		$single = new WP_REST_Request( 'GET', '/swg/v1/galleries/5' );
		$single->set_param( 'id', 5 );
		$list = new WP_REST_Request( 'GET', '/swg/v1/galleries' );
		$list->set_param( 'page', 1 );
		$list->set_param( 'per_page', 10 );
		$create = new WP_REST_Request( 'POST', '/swg/v1/galleries' );
		$create->set_param( 'title', 'x' );
		foreach ( array( $controller->get_item( $single ), $controller->get_items( $list ), $controller->create_item( $create ) ) as $result ) {
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'swg_internal_error', $result->get_error_code() );
			$this->assertSame( 500, $result->get_error_data()['status'] );
			$flat = $result->get_error_message() . wp_json_encode( $result->get_error_data() );
			$this->assertStringNotContainsString( 'SELECT', $flat );
			$this->assertStringNotContainsString( '/var/www', $flat );
		}
		$this->assertCount( 3, $logger->entries );
		$this->assertSame( array( 'swg_rest_unexpected_error' ), array_unique( $logger->codes() ) );
		foreach ( $logger->entries as $entry ) {
			$this->assertStringNotContainsString( 'secret', (string) wp_json_encode( $entry['context'] ) );
		}
	}

	/**
	 * Dispatches the request that belongs to a matrix route.
	 */
	private function send( string $route, int $gallery, int $revision, int $user ): WP_REST_Response {
		$base = '/galleries/' . $gallery;
		switch ( $route ) {
			case 'get':
				return $this->call( 'GET', $base, array(), $user );
			case 'patch':
				return $this->call( 'PATCH', $base, array( 'title' => 'Changed', 'expectedRevision' => $revision ), $user );
			case 'trash':
				return $this->call( 'DELETE', $base, array(), $user, array( 'expectedRevision' => $revision ) );
			case 'force':
				return $this->call( 'DELETE', $base, array(), $user, array( 'expectedRevision' => $revision, 'force' => 'true' ) );
			case 'restore':
				return $this->call( 'POST', $base . '/restore', array( 'expectedRevision' => $revision ), $user );
			default:
				return $this->call( 'POST', $base . '/duplicate', array(), $user );
		}
	}

	private function assertNoLeak( WP_REST_Response $response ): void {
		$body = (string) wp_json_encode( $response->get_data() );
		foreach ( array( ABSPATH, '/var/www', 'wp_posts', 'Stack trace', 'SELECT ' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $body );
		}
	}
}
