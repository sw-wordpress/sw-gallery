<?php
/**
 * Gallery create: the publish rule of the access policy (FU-10) and the plugin default layout (FU-11).
 *
 * @package sw-gallery
 */

use SWGallery\Tests\Support\RestFixtures;
use SWGallery\Tests\Support\StubLayout;
use SWGallery\WordPress\Infrastructure\Services;

class RestGalleryCreateDefaultsTest extends SwgTestCase {

	use RestFixtures;

	public function set_up(): void {
		parent::set_up();
		delete_option( 'swg_settings' );
		$this->bootRest();
	}

	public function tear_down(): void {
		delete_option( 'swg_settings' );
		$this->shutdownRest();
		parent::tear_down();
	}

	/**
	 * @return iterable<string, array{string, string, int}>
	 */
	public static function publish_matrix(): iterable {
		foreach ( array( 'publish', 'private' ) as $status ) {
			yield "$status administrator" => array( 'administrator', $status, 201 );
			yield "$status editor" => array( 'editor', $status, 201 );
			yield "$status author" => array( 'author', $status, 201 );
			yield "$status contributor" => array( 'contributor', $status, 403 );
		}
		yield 'draft contributor' => array( 'contributor', 'draft', 201 );
		yield 'pending contributor' => array( 'contributor', 'pending', 201 );
	}

	/**
	 * @dataProvider publish_matrix
	 */
	public function test_creating_with_a_publishing_status_needs_the_publish_right( string $role, string $status, int $expected ): void {
		$response = $this->call( 'POST', '/galleries', array( 'title' => 'New', 'status' => $status ), $this->makeUser( $role ) );

		$this->assertSame( $expected, $response->get_status() );
		if ( 403 === $expected ) {
			$this->assertSame( 'swg_cannot_publish', $response->get_data()['code'] );
		} else {
			$this->assertSame( $status, $this->data( $response )['status'] );
		}
	}

	public function test_the_default_layout_setting_applies_when_the_request_has_no_layout(): void {
		Services::layouts()->register( new StubLayout( 'stubby' ) );
		Services::settings()->update( array( 'defaultLayout' => 'stubby' ) );
		$admin = $this->makeUser( 'administrator' );

		$default  = $this->call( 'POST', '/galleries', array( 'title' => 'Default' ), $admin );
		$explicit = $this->call( 'POST', '/galleries', array( 'title' => 'Explicit', 'layout' => 'grid' ), $admin );

		$this->assertSame( 201, $default->get_status() );
		$this->assertSame( 'stubby', $this->data( $default )['layout'] );
		$this->assertSame( 'grid', $this->data( $explicit )['layout'] );
	}

	public function test_without_a_stored_setting_new_galleries_use_the_grid(): void {
		$response = $this->call( 'POST', '/galleries', array( 'title' => 'Plain' ), $this->makeUser( 'author' ) );

		$this->assertSame( 'grid', $this->data( $response )['layout'] );
	}
}
