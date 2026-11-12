<?php
/**
 * POST /swg/v1/galleries/{id}/preview: authorisation, unsaved state, validation, response shape, nothing stored.
 *
 * @package sw-gallery
 */

use SWGallery\Tests\Support\RenderFixtures;
use SWGallery\Tests\Support\RestFixtures;
use SWGallery\Tests\Support\StubLayout;
use SWGallery\WordPress\Infrastructure\Services;

class PreviewRouteTest extends SwgTestCase {

	use RestFixtures;
	use RenderFixtures;

	private int $author;
	private int $other;
	private int $editor;
	private int $admin;

	public function set_up(): void {
		parent::set_up();
		$this->bootRest();
		$this->author = $this->makeUser( 'author' );
		$this->other  = $this->makeUser( 'author' );
		$this->editor = $this->makeUser( 'editor' );
		$this->admin  = $this->makeUser( 'administrator' );
		$this->lockPassword();
	}

	public function tear_down(): void {
		$this->lockPassword();
		$this->shutdownRest();
		parent::tear_down();
	}

	/**
	 * @param list<string> $titles Image titles in manual order.
	 */
	private function gallery( string $status = 'publish', string $password = '', array $titles = array( 'Beta', 'Alpha', 'Gamma' ) ): int {
		$id     = $this->makeGalleryPost( $this->author, $status, $password );
		$images = array();
		foreach ( $titles as $title ) {
			$images[] = $this->makeImage( $title, 2000, 1500, array(), null, $title );
		}
		$this->addItems( $id, $images );
		return $id;
	}

	/**
	 * @param array<string, mixed> $body Request body.
	 */
	private function preview( int $id, array $body = array(), int $user = 0 ): WP_REST_Response {
		return $this->call( 'POST', "/galleries/$id/preview", $body, $user );
	}

	public function test_the_response_shape(): void {
		$id   = $this->gallery();
		$data = $this->data( $this->preview( $id, array(), $this->author ) );

		$this->assertSame( array( 'html', 'layoutId', 'page', 'totalPages', 'totalItems', 'diagnostics', 'assets' ), array_keys( $data ) );
		$this->assertStringContainsString( 'data-swg-gallery', $data['html'] );
		$this->assertSame( 3, substr_count( $data['html'], '<img' ) );
		$this->assertSame( 'grid', $data['layoutId'] );
		$this->assertSame( 1, $data['page'] );
		$this->assertSame( 1, $data['totalPages'] );
		$this->assertSame( 3, $data['totalItems'] );
		$this->assertSame( array(), $data['diagnostics'] );
		$this->assertSame( array( 'css', 'js' ), array_keys( $data['assets'] ) );
		$this->assertIsArray( $data['assets']['css'] );
		$this->assertIsArray( $data['assets']['js'] );
		$response = $this->preview( $id, array(), $this->author );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
	}

	/**
	 * @return iterable<string, array{string, string, string, int}>
	 */
	public static function authorisation(): iterable {
		// status, password, user, expected HTTP status. The gallery belongs to "author".
		foreach ( array( 'draft', 'pending', 'private' ) as $status ) {
			yield "$status owner" => array( $status, '', 'author', 200 );
			yield "$status editor" => array( $status, '', 'editor', 200 );
			yield "$status admin" => array( $status, '', 'admin', 200 );
			yield "$status foreign author" => array( $status, '', 'other', 404 );
		}
		yield 'publish owner' => array( 'publish', '', 'author', 200 );
		yield 'publish foreign author may read but not edit' => array( 'publish', '', 'other', 403 );
		yield 'password owner' => array( 'publish', 'secret', 'author', 200 );
		yield 'password editor' => array( 'publish', 'secret', 'editor', 200 );
		yield 'password foreign author' => array( 'publish', 'secret', 'other', 403 );
		yield 'subscriber' => array( 'publish', '', 'subscriber', 403 );
		yield 'anonymous' => array( 'publish', '', 'anonymous', 401 );
		yield 'anonymous draft' => array( 'draft', '', 'anonymous', 401 );
	}

	/**
	 * @dataProvider authorisation
	 */
	public function test_authorisation( string $status, string $password, string $user, int $expected ): void {
		$ids = array(
			'author'     => $this->author,
			'other'      => $this->other,
			'editor'     => $this->editor,
			'admin'      => $this->admin,
			'subscriber' => $this->makeUser( 'subscriber' ),
			'anonymous'  => 0,
		);
		$id  = $this->gallery( $status, $password );

		$response = $this->preview( $id, array(), $ids[ $user ] );

		$this->assertSame( $expected, $response->get_status(), "$status/$password/$user" );
		if ( 200 === $expected ) {
			$data = $this->data( $response );
			$this->assertStringContainsString( 'data-swg-gallery', $data['html'] );
			$this->assertStringNotContainsString( 'post_password', $data['html'] );
			$this->assertStringNotContainsString( 'swg-diagnostic', $data['html'] );
		} else {
			$this->assertStringNotContainsString( 'Alpha', (string) wp_json_encode( $response->get_data() ) );
		}
	}

	public function test_a_trashed_gallery_never_renders(): void {
		$id = $this->gallery( 'trash' );

		$response = $this->preview( $id, array(), $this->admin );

		$this->assertContains( $response->get_status(), array( 200, 404 ) );
		if ( 200 === $response->get_status() ) {
			$this->assertSame( '', $this->data( $response )['html'] );
		}
	}

	public function test_a_missing_gallery_is_404(): void {
		$this->assertSame( 404, $this->preview( 987654, array(), $this->admin )->get_status() );
	}

	public function test_unsaved_state_is_rendered_but_never_stored(): void {
		$id = $this->gallery();
		global $wpdb;
		$snapshot = static function () use ( $wpdb, $id ): array {
			return array(
				'options' => $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" ), // phpcs:ignore WordPress.DB
				'posts'   => $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ), // phpcs:ignore WordPress.DB
				'meta'    => $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id", $id ), ARRAY_A ), // phpcs:ignore WordPress.DB
				'items'   => $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \SWGallery\WordPress\Database\Schema::items_table() ), // phpcs:ignore WordPress.DB
				'modified' => get_post_field( 'post_modified', $id ),
			);
		};
		$before = $snapshot();

		$data = $this->data(
			$this->preview(
				$id,
				array(
					'settings' => array(
						'columns' => 2,
						'gap'     => 20,
					),
					'sortMode' => 'title_asc',
				),
				$this->author
			)
		);

		$this->assertStringContainsString( '--swg-columns:2;--swg-gap:20px', $data['html'] );
		$this->assertSame( $before, $snapshot(), 'Nothing is persisted, cached or stored.' );
		$this->assertSame( 'manual', Services::galleries()->find( $id )->settings->sortMode->value );

		// The stored state still renders as before.
		$stored = $this->data( $this->preview( $id, array(), $this->author ) );
		$this->assertStringContainsString( '--swg-columns:3;', $stored['html'] );
	}

	public function test_the_sort_mode_changes_the_order(): void {
		$id = $this->gallery();

		$manual = $this->data( $this->preview( $id, array(), $this->author ) )['html'];
		$sorted = $this->data( $this->preview( $id, array( 'sortMode' => 'title_asc' ), $this->author ) )['html'];

		$this->assertLessThan( strpos( $manual, 'Alpha' ), strpos( $manual, 'Beta' ) );
		$this->assertLessThan( strpos( $sorted, 'Beta' ), strpos( $sorted, 'Alpha' ) );
	}

	public function test_paging(): void {
		$id   = $this->gallery();
		$data = $this->data( $this->preview( $id, array( 'page' => 2, 'perPage' => 1 ), $this->author ) );

		$this->assertSame( 2, $data['page'] );
		$this->assertSame( 3, $data['totalPages'] );
		$this->assertSame( 3, $data['totalItems'] );
		$this->assertSame( 1, substr_count( $data['html'], '<img' ) );
	}

	public function test_an_unknown_layout_is_422_and_a_layout_override_is_applied(): void {
		$id = $this->gallery();
		$this->assertSame( 422, $this->preview( $id, array( 'layout' => 'doesnotexist' ), $this->author )->get_status() );
		$this->assertSame( 'swg_unknown_layout', $this->preview( $id, array( 'layout' => 'doesnotexist' ), $this->author )->get_data()['code'] );

		Services::layouts()->register( new StubLayout( 'stubby' ) );
		$data = $this->data( $this->preview( $id, array( 'layout' => 'stubby' ), $this->author ) );
		$this->assertSame( 'stubby', $data['layoutId'] );
		$this->assertSame( '<div></div>', $data['html'] );
		$this->assertSame( 'grid', Services::galleries()->find( $id )->settings->layout );
	}

	public function test_invalid_settings_are_422(): void {
		$id       = $this->gallery();
		$response = $this->preview( $id, array( 'settings' => array( 'columns' => 99 ) ), $this->author );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'swg_validation_failed', $response->get_data()['code'] );
	}

	/**
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function malformed_bodies(): iterable {
		yield 'page zero' => array( array( 'page' => 0 ) );
		yield 'page text' => array( array( 'page' => 'x' ) );
		yield 'perPage too big' => array( array( 'perPage' => 500 ) );
		yield 'perPage zero' => array( array( 'perPage' => 0 ) );
		yield 'sortMode unknown' => array( array( 'sortMode' => 'random' ) );
		yield 'layout invalid id' => array( array( 'layout' => 'Bad Id!' ) );
		yield 'settings scalar' => array( array( 'settings' => 'x' ) );
	}

	/**
	 * @dataProvider malformed_bodies
	 * @param array<string, mixed> $body Body.
	 */
	public function test_malformed_bodies_are_400( array $body ): void {
		$id = $this->gallery();

		$this->assertSame( 400, $this->preview( $id, $body, $this->author )->get_status() );
	}

	public function test_diagnostics_are_structured_and_not_part_of_the_html(): void {
		$id   = $this->makeGalleryPost( $this->author );
		$data = $this->data( $this->preview( $id, array(), $this->author ) );

		$this->assertSame( '', $data['html'] );
		$this->assertCount( 1, $data['diagnostics'] );
		$this->assertSame( 'empty_gallery', $data['diagnostics'][0]['code'] );
		$this->assertStringContainsString( 'no images', $data['diagnostics'][0]['message'] );
	}

	public function test_diagnostic_markup_is_stripped_from_non_empty_html(): void {
		$id = $this->makeGalleryPost( $this->author, 'publish', '', 'ghost-layout' );
		$this->addItems( $id, array( $this->makeImage( 'One' ) ) );
		$data = $this->data( $this->preview( $id, array(), $this->author ) );

		$this->assertSame( 'unknown_layout', $data['diagnostics'][0]['code'] );
		$this->assertSame( 'grid', $data['layoutId'] );
		$this->assertStringNotContainsString( 'swg-diagnostic', $data['html'] );
		$this->assertStringContainsString( 'data-swg-gallery', $data['html'] );
	}

	public function test_the_route_is_registered_with_the_documented_method(): void {
		$routes = rest_get_server()->get_routes( 'swg/v1' );
		$route  = '/swg/v1/galleries/(?P<id>[1-9]\d{0,9})/preview';

		$this->assertArrayHasKey( $route, $routes );
		$this->assertSame( array( 'POST' ), array_keys( $routes[ $route ][0]['methods'] ) );
	}
}
