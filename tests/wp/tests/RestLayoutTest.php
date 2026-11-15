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
		$ids = array_column( $data['layouts'], 'id' );
		foreach ( array( 'grid', 'justified', 'masonry', 'mosaic', 'carousel', 'focus' ) as $built_in ) {
			$this->assertContains( $built_in, $ids );
		}
		$grid = array_column( $data['layouts'], null, 'id' )['grid'];
		$this->assertSame( array( 'id', 'label', 'settingsSchema' ), array_keys( $grid ) );
		$this->assertSame( 'grid', $grid['id'] );
		$this->assertSame( 'Grid', $grid['label'] );
		$this->assertTrue( $grid['settingsSchema']['properties']['columns']['x-responsive'] );
		$this->assertSame( 12, $grid['settingsSchema']['properties']['columns']['maximum'] );
		$this->assertSame( 64, $grid['settingsSchema']['properties']['gap']['maximum'] );
	}

	public function test_justified_and_masonry_are_listed_with_their_schemas(): void {
		$data = $this->data( $this->call( 'GET', '/layouts', array(), $this->makeUser( 'author' ) ) );
		$by   = array_column( $data['layouts'], null, 'id' );

		$this->assertSame( 'Justified', $by['justified']['label'] );
		$this->assertSame( array( 'rowHeight', 'gap', 'lastRow' ), array_keys( $by['justified']['settingsSchema']['properties'] ) );
		$this->assertTrue( $by['justified']['settingsSchema']['properties']['rowHeight']['x-responsive'] );
		$this->assertSame( array( 'left', 'justify' ), $by['justified']['settingsSchema']['properties']['lastRow']['enum'] );
		$titles = $by['justified']['settingsSchema']['properties']['lastRow']['x-enum-titles'];
		$this->assertSame( array( 'left', 'justify' ), array_keys( $titles ) );
		$this->assertSame( array( 'Natural height (left-aligned)', 'Stretch to full width' ), array_values( $titles ) );
		$this->assertSame( 'Masonry', $by['masonry']['label'] );
		$this->assertSame( array( 'columns', 'gap', 'radius' ), array_keys( $by['masonry']['settingsSchema']['properties'] ) );
		$this->assertTrue( $by['masonry']['settingsSchema']['properties']['columns']['x-responsive'] );
	}

	public function test_carousel_and_focus_are_listed_with_their_schemas(): void {
		$data = $this->data( $this->call( 'GET', '/layouts', array(), $this->makeUser( 'author' ) ) );
		$by   = array_column( $data['layouts'], null, 'id' );

		$this->assertSame( 'Carousel', $by['carousel']['label'] );
		$this->assertSame( array( 'height', 'gap', 'radius', 'buttons', 'snap' ), array_keys( $by['carousel']['settingsSchema']['properties'] ) );
		$this->assertTrue( $by['carousel']['settingsSchema']['properties']['height']['x-responsive'] );
		$this->assertSame( 'boolean', $by['carousel']['settingsSchema']['properties']['buttons']['type'] );
		$this->assertSame( array( 'start', 'center' ), array_keys( $by['carousel']['settingsSchema']['properties']['snap']['x-enum-titles'] ) );
		$this->assertSame( 'Focus', $by['focus']['label'] );
		$this->assertSame( array( 'stageRatio', 'thumbs', 'thumbSize', 'gap', 'radius' ), array_keys( $by['focus']['settingsSchema']['properties'] ) );
		$this->assertSame( array( 'auto', '16:9', '3:2', '4:3', '1:1' ), $by['focus']['settingsSchema']['properties']['stageRatio']['enum'] );
		$this->assertSame( array( 'bottom', 'start' ), $by['focus']['settingsSchema']['properties']['thumbs']['enum'] );
	}

	public function test_mosaic_is_listed_with_layout_and_item_schemas(): void {
		$data   = $this->data( $this->call( 'GET', '/layouts', array(), $this->makeUser( 'author' ) ) );
		$mosaic = array_column( $data['layouts'], null, 'id' )['mosaic'];

		$this->assertSame( 'Mosaic', $mosaic['label'] );
		$this->assertSame( array( 'gap', 'radius', 'ratio' ), array_keys( $mosaic['settingsSchema']['properties'] ) );
		$this->assertSame( array( 'auto', '1:1', '4:3', '3:2', '16:9' ), $mosaic['settingsSchema']['properties']['ratio']['enum'] );
		$item = $mosaic['settingsSchema']['x-item-settings'];
		$this->assertSame( array( 'emphasis', 'focal' ), array_keys( $item['properties'] ) );
		$this->assertSame( array( 1, 2, 3 ), $item['properties']['emphasis']['enum'] );
		$this->assertSame( array( 1, 2, 3 ), array_keys( $item['properties']['emphasis']['x-enum-titles'] ) );
		$this->assertSame( array( 'x', 'y' ), array_keys( $item['properties']['focal']['properties'] ) );
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
		Services::layouts()->register( new StubLayout( 'wall', $schema ) );

		$data = $this->data( $this->call( 'GET', '/layouts', array(), $this->makeUser( 'editor' ) ) );

		$ids = array_column( $data['layouts'], 'id' );
		$this->assertSame( 'wall', end( $ids ), 'Third-party layouts come after the built-ins.' );
		$masonry = array_column( $data['layouts'], null, 'id' )['wall'];
		$this->assertSame( 'Wall', $masonry['label'] );
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
			$this->assertNotSame( 'Grid', array_column( $data['layouts'], null, 'id' )['grid']['label'] );
		} finally {
			unload_textdomain( 'sw-gallery' );
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}
}
