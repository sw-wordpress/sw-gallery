<?php
/**
 * Role matrix of the item and order routes (own and foreign galleries, own and foreign attachments) and the
 * registration invariants of the routes (the all-routes anonymous/subscriber invariants live in RestSecurityTest).
 *
 * @package sw-gallery
 */

use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Core\Domain\Gallery\Revision;
use SWGallery\Tests\Support\RestItemFixtures;
use SWGallery\WordPress\Infrastructure\Services;

class RestItemSecurityTest extends SwgTestCase {

	use RestItemFixtures;

	private const ROLES  = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber', 'anonymous' );
	private const WRITES = array( 'assign', 'patch', 'delete', 'bulk-remove', 'order' );

	/** Expected status for: own gallery, foreign draft, foreign published gallery. */
	private const READ_MATRIX  = array(
		'administrator' => array( 200, 200, 200 ),
		'editor'        => array( 200, 200, 200 ),
		'author'        => array( 200, 404, 200 ),
		'contributor'   => array( 200, 404, 200 ),
		'subscriber'    => array( 403, 403, 403 ),
		'anonymous'     => array( 401, 401, 401 ),
	);
	private const WRITE_MATRIX = array(
		'administrator' => array( 200, 200, 200 ),
		'editor'        => array( 200, 200, 200 ),
		'author'        => array( 200, 404, 403 ),
		'contributor'   => array( 200, 404, 403 ),
		'subscriber'    => array( 403, 403, 403 ),
		'anonymous'     => array( 401, 401, 401 ),
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
		foreach ( array_merge( array( 'list' ), self::WRITES ) as $route ) {
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
		$user     = 'anonymous' === $role ? 0 : $this->makeUser( $role );
		$stranger = $this->makeUser( 'author' );
		$expected = 'list' === $route ? self::READ_MATRIX[ $role ] : self::WRITE_MATRIX[ $role ];

		$scenarios = array(
			'own gallery'       => array( in_array( $role, array( 'anonymous', 'subscriber' ), true ) ? $stranger : $user, GalleryStatus::Draft ),
			'foreign draft'     => array( $stranger, GalleryStatus::Draft ),
			'foreign published' => array( $stranger, GalleryStatus::Publish ),
		);
		$index     = 0;
		foreach ( $scenarios as $label => $scenario ) {
			$gallery = $this->makeGallery( $scenario[0], $scenario[1], 'Matrix' );
			// Items are seeded by the user who owns the gallery; the attachment belongs to somebody else.
			$images = array( $this->makeAttachment( $stranger ), $this->makeAttachment( $stranger ) );
			Services::item_service()->assign( new Actor( $this->admin_id() ), $gallery, $images, new Revision( 1 ) );

			$response = $this->send( $route, $gallery, $user );
			$this->assertSame( $expected[ $index ], $response->get_status(), sprintf( '%s / %s / %s', $route, $role, $label ) );
			$this->assertNoLeak( $response );
			if ( 200 !== $expected[ $index ] ) {
				$this->assertSame( 2, Services::items()->count( $gallery ), 'a refused request changes nothing: ' . $label );
				$this->assertSame( 2, $this->revisionOf( $gallery ), 'a refused request keeps the revision: ' . $label );
			}
			++$index;
		}
	}

	public function test_hidden_and_missing_galleries_answer_identically_on_every_item_route(): void {
		$author  = $this->makeUser( 'author' );
		$hidden  = $this->makeGallery( $this->makeUser( 'author' ), GalleryStatus::Draft );
		$routes  = array(
			array( 'GET', '/items', array(), array() ),
			array( 'POST', '/items', array( 'attachmentIds' => array( 5 ), 'expectedRevision' => 1 ), array() ),
			array( 'POST', '/items/bulk-remove', array( 'itemIds' => array( 5 ), 'expectedRevision' => 1 ), array() ),
			array( 'PATCH', '/items/5', array( 'overrides' => array( 'title' => 'x' ), 'expectedRevision' => 1 ), array() ),
			array( 'DELETE', '/items/5', array(), array( 'expectedRevision' => 1 ) ),
			array( 'PUT', '/order', array( 'itemIds' => array( 5 ), 'expectedRevision' => 1 ), array() ),
		);
		foreach ( $routes as $route ) {
			$one = $this->call( $route[0], '/galleries/' . $hidden . $route[1], $route[2], $author, $route[3] );
			$two = $this->call( $route[0], '/galleries/999999' . $route[1], $route[2], $author, $route[3] );
			$this->assertSame( 404, $one->get_status(), $route[0] . ' ' . $route[1] );
			$this->assertSame( $two->get_data(), $one->get_data() );
			$this->assertSame( 'swg_gallery_not_found', $one->get_data()['code'] );
		}
	}

	public function test_every_item_route_is_registered_with_an_explicit_permission_callback(): void {
		$expected = array(
			'/swg/v1/galleries/(?P<id>[1-9]\d{0,9})/items'                         => array( 'GET', 'POST' ),
			'/swg/v1/galleries/(?P<id>[1-9]\d{0,9})/items/bulk-remove'             => array( 'POST' ),
			'/swg/v1/galleries/(?P<id>[1-9]\d{0,9})/items/(?P<itemId>[1-9]\d{0,9})' => array( 'PATCH', 'DELETE' ),
			'/swg/v1/galleries/(?P<id>[1-9]\d{0,9})/order'                          => array( 'PUT' ),
		);
		$routes   = rest_get_server()->get_routes( 'swg/v1' );
		foreach ( $expected as $route => $methods ) {
			$this->assertArrayHasKey( $route, $routes );
			$found = array();
			foreach ( $routes[ $route ] as $handler ) {
				$this->assertIsCallable( $handler['permission_callback'], $route );
				$this->assertNotSame( '__return_true', $handler['permission_callback'] );
				$found = array_merge( $found, array_keys( (array) $handler['methods'] ) );
			}
			$this->assertSame( $methods, $found, $route );
		}
	}

	public function test_anonymous_and_subscribers_are_refused_before_any_validation_on_realistic_bodies(): void {
		$subscriber = $this->makeUser( 'subscriber' );
		$gallery    = $this->makeGallery( $this->makeUser( 'author' ) );
		foreach ( array( 'list', 'assign', 'patch', 'delete', 'bulk-remove', 'order' ) as $route ) {
			$this->assertSame( 401, $this->send( $route, $gallery, 0 )->get_status(), $route );
			$this->assertSame( 403, $this->send( $route, $gallery, $subscriber )->get_status(), $route );
		}
	}

	private function admin_id(): int {
		static $admin = null;
		return $admin ??= $this->makeUser( 'administrator' );
	}

	/**
	 * Dispatches the request that belongs to a matrix route against a gallery holding two items.
	 */
	private function send( string $route, int $gallery, int $user ): WP_REST_Response {
		$base     = '/galleries/' . $gallery;
		$items    = $this->itemIds( $gallery );
		$revision = $this->revisionOf( $gallery );
		switch ( $route ) {
			case 'list':
				return $this->call( 'GET', $base . '/items', array(), $user );
			case 'assign':
				return $this->call( 'POST', $base . '/items', array( 'attachmentIds' => array( $this->makeAttachment( $user ) ), 'expectedRevision' => $revision ), $user );
			case 'patch':
				return $this->call( 'PATCH', $base . '/items/' . ( $items[0] ?? 1 ), array( 'overrides' => array( 'title' => 'Changed' ), 'expectedRevision' => $revision ), $user );
			case 'delete':
				return $this->call( 'DELETE', $base . '/items/' . ( $items[0] ?? 1 ), array(), $user, array( 'expectedRevision' => $revision ) );
			case 'bulk-remove':
				return $this->call( 'POST', $base . '/items/bulk-remove', array( 'itemIds' => array( $items[0] ?? 1 ), 'expectedRevision' => $revision ), $user );
			default:
				return $this->call( 'PUT', $base . '/order', array( 'itemIds' => array_reverse( $items ), 'expectedRevision' => $revision ), $user );
		}
	}

	private function assertNoLeak( WP_REST_Response $response ): void {
		$body = (string) wp_json_encode( $response->get_data() );
		foreach ( array( ABSPATH, '/var/www', 'wp_posts', 'Stack trace', 'SELECT ' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $body );
		}
	}
}
