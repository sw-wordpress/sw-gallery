<?php
/**
 * The grid `aspect` setting: schema validation and the emitted custom property.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Domain\Gallery\GallerySettings;
use SWGallery\Core\Domain\Gallery\SortMode;
use SWGallery\Core\Domain\Layout\LayoutRenderRequest;
use SWGallery\Core\Domain\Layout\RenderItem;
use SWGallery\Core\Domain\Layout\ResolvedImage;
use SWGallery\Core\Domain\Settings\SettingsSchemaValidator;
use SWGallery\WordPress\Rendering\Layout\GridLayout;

class RenderGridAspectTest extends SwgTestCase {

	/**
	 * @param array<string, mixed> $values Layout settings.
	 */
	private function style( array $values ): string {
		$item    = new RenderItem( 1, 101, 'T', '', 'A', new ResolvedImage( 'https://example.test/t.jpg', 300, 200, '', '33vw' ), null );
		$html    = ( new GridLayout() )->render( new LayoutRenderRequest( 12, 'swg-12-1', array( $item ), new GallerySettings( 'grid', SortMode::Manual, $values ) ) );
		$matched = preg_match( '/style="([^"]*)"/', $html, $m );
		$this->assertSame( 1, $matched );
		return $m[1];
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function aspects(): iterable {
		yield '1:1' => array( '1:1', '--swg-aspect:1/1' );
		yield '4:3' => array( '4:3', '--swg-aspect:4/3' );
		yield '3:2' => array( '3:2', '--swg-aspect:3/2' );
		yield '16:9' => array( '16:9', '--swg-aspect:16/9' );
	}

	/**
	 * @dataProvider aspects
	 */
	public function test_a_fixed_aspect_is_emitted_as_a_custom_property( string $aspect, string $expected ): void {
		$this->assertStringEndsWith( ';' . $expected, $this->style( array( 'aspect' => $aspect ) ) );
	}

	public function test_auto_and_invalid_values_emit_nothing(): void {
		$this->assertStringNotContainsString( 'aspect', $this->style( array() ) );
		$this->assertStringNotContainsString( 'aspect', $this->style( array( 'aspect' => 'auto' ) ) );
		$this->assertStringNotContainsString( 'aspect', $this->style( array( 'aspect' => '9:16;color:red' ) ) );
		$this->assertStringNotContainsString( 'aspect', $this->style( array( 'aspect' => 4 ) ) );
	}

	public function test_the_schema_validates_the_aspect(): void {
		$schema    = ( new GridLayout() )->getSettingsSchema();
		$validator = new SettingsSchemaValidator();

		$this->assertSame( 'auto', $schema['properties']['aspect']['default'] );
		$this->assertTrue( $validator->validate( $schema, array( 'aspect' => '16:9' ) )->isValid() );
		$this->assertTrue( $validator->validate( $schema, array( 'aspect' => 'auto' ) )->isValid() );
		$this->assertFalse( $validator->validate( $schema, array( 'aspect' => '21:9' ) )->isValid() );
		$this->assertFalse( $validator->validate( $schema, array( 'aspect' => 16 ) )->isValid() );
	}

	public function test_the_image_hints_match_the_stylesheet_breakpoints(): void {
		$hints = ( new GridLayout() )->imageHints( new GallerySettings( 'grid', SortMode::Manual, array( 'columns' => array( 'base' => 1, 'sm' => 2, 'md' => 3, 'lg' => 4, 'xl' => 6 ) ) ) );

		$this->assertSame( '(min-width: 1280px) calc(100vw / 6), (min-width: 1024px) calc(100vw / 4), (min-width: 768px) calc(100vw / 3), (min-width: 576px) calc(100vw / 2), calc(100vw / 1)', $hints->sizes );
		$this->assertSame( 200, $hints->displayWidth );
	}
}
