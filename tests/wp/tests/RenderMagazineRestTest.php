<?php
/**
 * The magazine layout in GET /swg/v1/layouts.
 *
 * @package sw-gallery
 */

use SWGallery\Tests\Support\RestFixtures;

class RenderMagazineRestTest extends SwgTestCase {

	use RestFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->bootRest();
	}

	public function tear_down(): void {
		$this->shutdownRest();
		parent::tear_down();
	}

	public function test_magazine_is_listed_with_its_schemas(): void {
		$data     = $this->data( $this->call( 'GET', '/layouts', array(), $this->makeUser( 'author' ) ) );
		$magazine = array_column( $data['layouts'], null, 'id' )['magazine'];

		$this->assertSame( 'Magazine', $magazine['label'] );
		$this->assertSame( array( 'rowHeight', 'gap', 'radius', 'rhythm' ), array_keys( $magazine['settingsSchema']['properties'] ) );
		$this->assertTrue( $magazine['settingsSchema']['properties']['rowHeight']['x-responsive'] );
		$this->assertSame( array( 'balanced', 'calm', 'bold' ), $magazine['settingsSchema']['properties']['rhythm']['enum'] );
		$this->assertSame( array( 'balanced', 'calm', 'bold' ), array_keys( $magazine['settingsSchema']['properties']['rhythm']['x-enum-titles'] ) );
		$this->assertSame( array( 'focal' ), array_keys( $magazine['settingsSchema']['x-item-settings']['properties'] ) );
	}
}
