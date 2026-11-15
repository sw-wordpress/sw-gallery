<?php
/**
 * GET /swg/v1/layouts lists the shattered layout with its settings schema and its item settings schema.
 *
 * @package sw-gallery
 */

use SWGallery\Tests\Support\RestFixtures;

class RenderShatteredRestTest extends SwgTestCase {

	use RestFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->bootRest();
	}

	public function tear_down(): void {
		$this->shutdownRest();
		parent::tear_down();
	}

	public function test_the_layout_is_listed_with_both_schemas(): void {
		$data = $this->data( $this->call( 'GET', '/layouts', array(), $this->makeUser( 'author' ) ) );
		$by   = array_column( $data['layouts'], null, 'id' );

		$this->assertArrayHasKey( 'shattered', $by );
		$this->assertSame( 'Shattered', $by['shattered']['label'] );
		$schema = $by['shattered']['settingsSchema'];
		$this->assertSame( array( 'grout', 'variation', 'ratio' ), array_keys( $schema['properties'] ) );
		$this->assertSame( 24, $schema['properties']['grout']['maximum'] );
		$this->assertSame( 3, $schema['properties']['grout']['default'] );
		$this->assertSame( 99, $schema['properties']['variation']['maximum'] );
		$this->assertSame( array( 'auto', '1:1', '4:3', '3:2', '16:9' ), $schema['properties']['ratio']['enum'] );
		$this->assertArrayHasKey( 'x-enum-titles', $schema['properties']['ratio'] );
		$this->assertSame( array( 'emphasis', 'focal' ), array_keys( $schema['x-item-settings']['properties'] ) );
	}
}
