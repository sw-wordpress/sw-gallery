<?php
/**
 * GET /swg/v1/layouts: registered layouts with translated labels and settings schemas.
 *
 * @package sw-gallery
 */

use SWGallery\Tests\Support\RestFixtures;
use SWGallery\Tests\Support\StubLayout;
use SWGallery\WordPress\Infrastructure\Services;

class RestLayoutTest extends SwgTestCase {

	use RestFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->bootRest();
	}

	public function tear_down(): void {
		$this->shutdownRest();
		parent::tear_down();
	}

	public function test_role_matrix(): void {
		$expected = array(
			'administrator' => 200,
			'editor'        => 200,
			'author'        => 200,
			'contributor'   => 200,
			'subscriber'    => 403,
			'anonymous'     => 401,
		);
		foreach ( $expected as $role => $status ) {
			$user = 'anonymous' === $role ? 0 : $this->makeUser( $role );
			$this->assertSame( $status, $this->call( 'GET', '/layouts', array(), $user )->get_status(), $role );
		}
	}

	public function test_the_grid_layout_is_listed_with_schema_and_fallback(): void {
		$response = $this->call( 'GET', '/layouts', array(), $this->makeUser( 'author' ) );
		$data     = $this->data( $response );

		$this->assertSame( 'grid', $data['fallbackId'] );
		$this->assertCount( 1, $data['layouts'] );
		$grid = $data['layouts'][0];
		$this->assertSame( array( 'id', 'label', 'settingsSchema' ), array_keys( $grid ) );
		$this->assertSame( 'grid', $grid['id'] );
		$this->assertSame( 'Grid', $grid['label'] );
		$this->assertTrue( $grid['settingsSchema']['properties']['columns']['x-responsive'] );
		$this->assertSame( 12, $grid['settingsSchema']['properties']['columns']['maximum'] );
		$this->assertSame( 64, $grid['settingsSchema']['properties']['gap']['maximum'] );
	}

	public function test_extra_layouts_keep_unknown_labels_and_item_settings_keys(): void {
		$schema = array(
			'type'            => 'object',
			'properties'      => array(),
			'x-item-settings' => array(
				'type'       => 'object',
				'properties' => array( 'span' => array( 'type' => 'integer' ) ),
			),
		);
		Services::layouts()->register( new StubLayout( 'masonry', $schema ) );

		$data = $this->data( $this->call( 'GET', '/layouts', array(), $this->makeUser( 'editor' ) ) );

		$this->assertSame( array( 'grid', 'masonry' ), array_column( $data['layouts'], 'id' ) );
		$masonry = $data['layouts'][1];
		$this->assertSame( 'Masonry', $masonry['label'] );
		$this->assertArrayHasKey( 'x-item-settings', $masonry['settingsSchema'] );
		// An empty property list must stay a JSON object.
		$this->assertStringContainsString( '"properties":{}', (string) wp_json_encode( $masonry['settingsSchema'] ) );
	}

	public function test_labels_are_translated_at_presentation_time(): void {
		$switched = switch_to_locale( 'de_DE' );
		add_filter( 'locale', static fn(): string => 'de_DE' );
		load_textdomain( 'sw-gallery', SWGALLERY_DIR . 'languages/sw-gallery-de_DE.mo' );
		try {
			$data = $this->data( $this->call( 'GET', '/layouts', array(), $this->makeUser( 'editor' ) ) );
			$this->assertNotSame( 'Grid', $data['layouts'][0]['label'] );
		} finally {
			unload_textdomain( 'sw-gallery' );
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}
}
