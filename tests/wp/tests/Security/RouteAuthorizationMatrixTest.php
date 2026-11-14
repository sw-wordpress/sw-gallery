<?php
/**
 * Data-driven authorization matrix over EVERY registered `swg/v1` route and method (M5-04 a).
 *
 * The table below is the contract: the test fails when a route appears that is not listed (so a new route cannot ship
 * without an authorization decision) and when a listed route disappears. Every listed route is then exercised as each
 * actor against a draft gallery of an author. Columns of the expectation lists (ACTORS): anonymous, subscriber,
 * contributor, author (the owner), other author, editor, administrator. A multisite super admin is checked in the
 * network suite (same expectations as the administrator).
 *
 * @package sw-gallery
 */

use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Tests\Support\RestItemFixtures;
use SWGallery\WordPress\Rest\AbstractController;

class RouteAuthorizationMatrixTest extends SwgTestCase {

	use RestItemFixtures;

	private const ACTORS = array( 'anonymous', 'subscriber', 'contributor', 'author', 'other_author', 'editor', 'administrator' );

	/** Shorthand rows (see ACTORS). */
	private const ADMINS_ONLY  = array( 401, 403, 403, 403, 403, 403, 200 );
	private const EDITORS_CAP  = array( 401, 403, 200, 200, 200, 200, 200 );
	private const UPLOADERS    = array( 401, 403, 403, 200, 200, 200, 200 );
	private const OBJECT_READ  = array( 401, 403, 404, 200, 404, 200, 200 );
	private const OBJECT_WRITE = array( 401, 403, 404, 200, 404, 200, 200 );

	/**
	 * Route (METHOD + route with {id}/{itemId}) => expected status per actor.
	 *
	 * @return array<string, list<int>>
	 */
	private static function table(): array {
		return array(
			'GET /swg/v1/galleries'                            => self::EDITORS_CAP,
			'POST /swg/v1/galleries'                           => array( 401, 403, 201, 201, 201, 201, 201 ),
			'GET /swg/v1/galleries/{id}'                       => self::OBJECT_READ,
			'PATCH /swg/v1/galleries/{id}'                     => self::OBJECT_WRITE,
			'DELETE /swg/v1/galleries/{id}'                    => self::OBJECT_WRITE,
			'POST /swg/v1/galleries/{id}/duplicate'            => array( 401, 403, 404, 201, 404, 201, 201 ),
			// A live gallery cannot be restored: 409 for everybody who may touch it, 404 for everybody else.
			'POST /swg/v1/galleries/{id}/restore'              => array( 401, 403, 404, 409, 404, 409, 409 ),
			'GET /swg/v1/galleries/{id}/items'                 => self::OBJECT_READ,
			'POST /swg/v1/galleries/{id}/items'                => self::OBJECT_WRITE,
			'POST /swg/v1/galleries/{id}/items/bulk-remove'    => self::OBJECT_WRITE,
			'PATCH /swg/v1/galleries/{id}/items/{itemId}'      => self::OBJECT_WRITE,
			'DELETE /swg/v1/galleries/{id}/items/{itemId}'     => self::OBJECT_WRITE,
			'PUT /swg/v1/galleries/{id}/order'                 => self::OBJECT_WRITE,
			'POST /swg/v1/galleries/{id}/preview'              => self::OBJECT_WRITE,
			'GET /swg/v1/layouts'                              => self::EDITORS_CAP,
			'GET /swg/v1/media'                                => self::UPLOADERS,
			// No file in the request: the permitted roles get as far as the upload validation (400).
			'POST /swg/v1/media'                               => array( 401, 403, 403, 400, 400, 400, 400 ),
			'GET /swg/v1/settings'                             => self::ADMINS_ONLY,
			'PATCH /swg/v1/settings'                           => self::ADMINS_ONLY,
			'GET /swg/v1/status'                               => self::ADMINS_ONLY,
		);
	}

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
		foreach ( array_keys( self::table() ) as $route ) {
			foreach ( self::ACTORS as $actor ) {
				$cases[ $route . ' as ' . $actor ] = array( $route, $actor );
			}
		}
		return $cases;
	}

	/**
	 * Registered "METHOD route" keys of the namespace, normalised to the table's notation.
	 *
	 * @return array<string, array<string, mixed>> Key => handler.
	 */
	private function registered(): array {
		$found = array();
		foreach ( rest_get_server()->get_routes( AbstractController::NAMESPACE_V1 ) as $route => $handlers ) {
			if ( '/' . AbstractController::NAMESPACE_V1 === $route ) {
				// WordPress' own namespace index: route names and argument schemas, no plugin data.
				continue;
			}
			$normalised = (string) preg_replace( array( '/\(\?P<id>[^)]*\)/', '/\(\?P<itemId>[^)]*\)/' ), array( '{id}', '{itemId}' ), $route );
			foreach ( $handlers as $handler ) {
				foreach ( array_keys( (array) $handler['methods'] ) as $method ) {
					$found[ $method . ' ' . $normalised ] = $handler;
				}
			}
		}
		return $found;
	}

	public function test_the_table_covers_exactly_the_registered_routes(): void {
		$registered = array_keys( $this->registered() );
		$listed     = array_keys( self::table() );
		sort( $registered );
		sort( $listed );
		$this->assertSame(
			array(),
			array_values( array_diff( $registered, $listed ) ),
			'A swg/v1 route has no entry in the authorization matrix: add it with its expected status per actor.'
		);
		$this->assertSame( array(), array_values( array_diff( $listed, $registered ) ), 'A listed route is no longer registered.' );
		$this->assertCount( 20, $registered );
	}

	public function test_every_handler_has_a_real_permission_callback_and_a_schema(): void {
		foreach ( $this->registered() as $key => $handler ) {
			$this->assertArrayHasKey( 'permission_callback', $handler, $key );
			$this->assertIsCallable( $handler['permission_callback'], $key );
			$this->assertNotSame( '__return_true', $handler['permission_callback'], $key );
			$this->assertIsArray( $handler['args'] ?? null, $key . ' declares its arguments' );
		}
	}

	/**
	 * @dataProvider matrix_cases
	 */
	public function test_expected_status_per_route_and_actor( string $route, string $actor ): void {
		$user = $this->actor_id( $actor );
		$ctx  = $this->scenario();
		$response = $this->dispatch( $route, $ctx, $user );
		$expected = self::table()[ $route ][ array_search( $actor, self::ACTORS, true ) ];
		$this->assertSame( $expected, $response->get_status(), sprintf( '%s as %s: %s', $route, $actor, wp_json_encode( $response->get_data() ) ) );
		$this->assertNoDisclosure( $response );
		if ( $expected >= 400 && 'POST /swg/v1/media' !== $route ) {
			// A refused request changes nothing: the gallery keeps its revision and items.
			$this->assertSame( 2, $this->revisionOf( $ctx['gallery'] ) );
			$this->assertCount( 1, $this->itemIds( $ctx['gallery'] ) );
		}
	}

	public function test_hidden_and_missing_galleries_are_indistinguishable_on_every_object_route(): void {
		$ctx      = $this->scenario();
		$stranger = $this->actor_id( 'other_author' );
		foreach ( array_keys( self::table() ) as $route ) {
			if ( ! str_contains( $route, '{id}' ) ) {
				continue;
			}
			$hidden  = $this->dispatch( $route, $ctx, $stranger );
			$missing = $this->dispatch( $route, array( 'gallery' => 999999 ) + $ctx, $stranger );
			$this->assertSame( 404, $hidden->get_status(), $route );
			$this->assertSame( $missing->get_status(), $hidden->get_status(), $route );
			$this->assertSame( $missing->get_data(), $hidden->get_data(), $route . ' must not reveal whether the gallery exists' );
		}
	}

	public function test_multisite_super_admin_matches_the_administrator_and_a_site_admin_is_not_more_privileged(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Network suite only.' );
		}
		$site_admin = $this->actor_id( 'administrator' );
		$this->assertFalse( is_super_admin( $site_admin ), 'a site administrator is not a network administrator' );
		$super = $this->actor_id( 'administrator' );
		grant_super_admin( $super );
		$this->assertTrue( is_super_admin( $super ) );
		foreach ( array_keys( self::table() ) as $route ) {
			$expected = self::table()[ $route ][ count( self::ACTORS ) - 1 ];
			foreach ( array( 'site admin' => $site_admin, 'super admin' => $super ) as $label => $user ) {
				$ctx = $this->scenario();
				$this->assertSame( $expected, $this->dispatch( $route, $ctx, $user )->get_status(), $route . ' as ' . $label );
			}
		}
	}

	/**
	 * @return array{gallery: int, item: int, owner: int}
	 */
	private function scenario(): array {
		$owner   = $this->actor_id( 'author' );
		$gallery = $this->makeGallery( $owner, GalleryStatus::Draft, 'Matrix' );
		$this->seedItems( $gallery, $owner, 1 );
		return array(
			'gallery' => $gallery,
			'item'    => $this->itemIds( $gallery )[0],
			'owner'   => $owner,
		);
	}

	/** @var array<string, int> */
	private array $actors = array();

	private function actor_id( string $actor ): int {
		if ( 'anonymous' === $actor ) {
			return 0;
		}
		return $this->actors[ $actor ] ??= $this->makeUser( 'other_author' === $actor ? 'author' : $actor );
	}

	/**
	 * @param array{gallery: int, item?: int, owner?: int} $ctx Scenario ids.
	 */
	private function dispatch( string $route, array $ctx, int $user ): WP_REST_Response {
		[ $method, $path ] = explode( ' ', $route, 2 );
		$path              = str_replace( '/swg/v1', '', $path );
		$path              = str_replace( array( '{id}', '{itemId}' ), array( (string) $ctx['gallery'], (string) ( $ctx['item'] ?? 1 ) ), $path );
		$revision          = array( 'expectedRevision' => 2 );
		$body              = array();
		$query             = array();
		switch ( $route ) {
			case 'POST /swg/v1/galleries':
				$body = array( 'title' => 'Matrix create' );
				break;
			case 'PATCH /swg/v1/galleries/{id}':
				$body = array( 'title' => 'Changed' ) + $revision;
				break;
			case 'DELETE /swg/v1/galleries/{id}':
			case 'DELETE /swg/v1/galleries/{id}/items/{itemId}':
				$query = $revision;
				break;
			case 'POST /swg/v1/galleries/{id}/restore':
				$body = $revision;
				break;
			case 'POST /swg/v1/galleries/{id}/items':
				$body = array( 'attachmentIds' => array( 999999 ) ) + $revision;
				break;
			case 'POST /swg/v1/galleries/{id}/items/bulk-remove':
				$body = array( 'itemIds' => array( $ctx['item'] ?? 1 ) ) + $revision;
				break;
			case 'PATCH /swg/v1/galleries/{id}/items/{itemId}':
				$body = array( 'overrides' => array( 'title' => 'Changed' ) ) + $revision;
				break;
			case 'PUT /swg/v1/galleries/{id}/order':
				$body = array( 'itemIds' => array( $ctx['item'] ?? 1 ) ) + $revision;
				break;
			case 'PATCH /swg/v1/settings':
				$body = array( 'itemsPerPage' => 30 );
				break;
		}
		return $this->call( $method, $path, $body, $user, $query );
	}

	private function assertNoDisclosure( WP_REST_Response $response ): void {
		$body = (string) wp_json_encode( $response->get_data() );
		foreach ( array( ABSPATH, '/var/www', 'wp_posts', 'Stack trace', 'SELECT ', 'swg_gallery_items', DB_PASSWORD, wp_salt( 'auth' ) ) as $needle ) {
			// The throw-away test database uses short placeholder secrets that collide with legitimate words.
			if ( strlen( $needle ) >= 8 ) {
				$this->assertStringNotContainsString( $needle, $body );
			}
		}
	}
}
