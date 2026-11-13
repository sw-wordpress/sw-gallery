<?php
/**
 * The justified layout: markup contract, per-item ratio, settings, image hints.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Domain\Gallery\GallerySettings;
use SWGallery\Core\Domain\Gallery\SortMode;
use SWGallery\Core\Domain\Layout\LayoutRenderRequest;
use SWGallery\Core\Domain\Layout\RenderItem;
use SWGallery\Core\Domain\Layout\ResolvedImage;
use SWGallery\Core\Domain\Settings\SettingsSchemaValidator;
use SWGallery\WordPress\Rendering\Layout\JustifiedLayout;

class RenderJustifiedLayoutTest extends SwgTestCase {

	/**
	 * @param list<RenderItem>     $items  Items.
	 * @param array<string, mixed> $values Layout settings.
	 */
	private function render( array $items, array $values = array() ): string {
		return ( new JustifiedLayout() )->render( new LayoutRenderRequest( 12, 'swg-12-1', $items, new GallerySettings( 'justified', SortMode::Manual, $values ) ) );
	}

	private function item( int $id = 1, int $width = 300, int $height = 200, string $alt = 'A tree', string $caption = '' ): RenderItem {
		return new RenderItem( $id, 100 + $id, 'Title', $caption, $alt, new ResolvedImage( 'https://example.test/t.jpg', $width, $height, 'https://example.test/t.jpg 300w', '33vw' ) );
	}

	private function dom( string $html ): DOMXPath {
		$document = new DOMDocument();
		$document->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>', LIBXML_NOERROR );
		return new DOMXPath( $document );
	}

	private function container_style( string $html ): string {
		return $this->dom( $html )->query( '//div[@data-swg-gallery]' )->item( 0 )->getAttribute( 'style' );
	}

	public function test_it_emits_the_lightbox_markup_contract(): void {
		$xp = $this->dom( $this->render( array( $this->item( 1, 300, 200, 'A tree', 'A <strong>nice</strong> tree' ), $this->item( 2 ) ) ) );

		$this->assertSame( 1, $xp->query( '//div[@class="swg-gallery swg-layout-justified"][@data-swg-gallery][@data-swg-instance="swg-12-1"]' )->length );
		$this->assertSame( 2, $xp->query( '//ul[@class="swg-items"][@role="list"]/li[@class="swg-item"]/a[@class="swg-link"]' )->length );
		$img = $xp->query( '//a[@class="swg-link"]/img' )->item( 0 );
		$this->assertSame( '300', $img->getAttribute( 'width' ) );
		$this->assertSame( '200', $img->getAttribute( 'height' ) );
		$this->assertSame( 1, $xp->query( '//p[@class="swg-caption"]/strong' )->length );
		$this->assertNotSame( '', $xp->query( '//div[@data-swg-gallery]' )->item( 0 )->getAttribute( 'data-swg-labels' ) );
	}

	public function test_each_item_gets_its_ratio_in_stored_order(): void {
		$xp    = $this->dom( $this->render( array( $this->item( 1, 300, 200 ), $this->item( 2, 200, 400 ), $this->item( 3, 1000, 1000 ) ) ) );
		$items = $xp->query( '//li[@class="swg-item"]' );

		$this->assertSame( '--swg-ar:1.5', $items->item( 0 )->getAttribute( 'style' ) );
		$this->assertSame( '--swg-ar:0.5', $items->item( 1 )->getAttribute( 'style' ) );
		$this->assertSame( '--swg-ar:1', $items->item( 2 )->getAttribute( 'style' ) );
	}

	public function test_ratios_are_clamped_and_unusable_dimensions_fall_back(): void {
		$xp    = $this->dom( $this->render( array( $this->item( 1, 5000, 100 ), $this->item( 2, 100, 5000 ), $this->item( 3, 0, 0 ), $this->item( 4, 640, 0 ), $this->item( 5, 1000, 3 ) ) ) );
		$items = $xp->query( '//li[@class="swg-item"]' );

		$this->assertSame( '--swg-ar:4', $items->item( 0 )->getAttribute( 'style' ) );
		$this->assertSame( '--swg-ar:0.25', $items->item( 1 )->getAttribute( 'style' ) );
		$this->assertSame( '--swg-ar:1.5', $items->item( 2 )->getAttribute( 'style' ) );
		$this->assertSame( '--swg-ar:1.5', $items->item( 3 )->getAttribute( 'style' ) );
		$this->assertSame( '--swg-ar:4', $items->item( 4 )->getAttribute( 'style' ) );
		$this->assertNull( $xp->query( '//li[3]//img/@width' )->item( 0 ), 'No width/height attribute for unusable dimensions.' );
	}

	public function test_the_ratio_is_locale_independent_and_pure(): void {
		$this->assertSame( 1.5, JustifiedLayout::ratio( 3, 2 ) );
		$this->assertSame( 1.5, JustifiedLayout::ratio( -1, 10 ) );
		$this->assertEqualsWithDelta( 1.3333, JustifiedLayout::ratio( 4, 3 ), 0.0001 );
		$this->assertMatchesRegularExpression( '/--swg-ar:1\.3333"/', $this->render( array( $this->item( 1, 4, 3 ) ) ) );
	}

	public function test_row_height_gap_and_responsive_row_height_become_custom_properties(): void {
		$base = $this->container_style(
			$this->render(
				array( $this->item() ),
				array(
					'rowHeight' => 300,
					'gap'       => 16,
				)
			)
		);
		$resp = $this->container_style(
			$this->render(
				array( $this->item() ),
				array(
					'rowHeight' => array(
						'base' => 120,
						'md'   => 260,
						'xl'   => 400,
					),
				)
			)
		);
		$def  = $this->container_style( $this->render( array( $this->item() ) ) );
		$junk = $this->container_style(
			$this->render(
				array( $this->item() ),
				array(
					'rowHeight' => '9;background:red',
					'gap'       => 'x',
				)
			)
		);
		$out  = $this->container_style(
			$this->render(
				array( $this->item() ),
				array(
					'rowHeight' => 9000,
					'gap'       => -4,
				)
			)
		);

		$this->assertSame( '--swg-row-height:300px;--swg-gap:16px', $base );
		$this->assertSame( '--swg-row-height:120px;--swg-row-height-md:260px;--swg-row-height-xl:400px;--swg-gap:8px', $resp );
		$this->assertSame( '--swg-row-height:220px;--swg-gap:8px', $def );
		$this->assertSame( '--swg-row-height:220px;--swg-gap:8px', $junk, 'Only validated integers may reach the style attribute.' );
		$this->assertSame( '--swg-row-height:600px;--swg-gap:0px', $out );
	}

	public function test_last_row_justify_adds_a_class_and_unknown_values_do_not(): void {
		$this->assertStringContainsString( 'swg-last-justify', $this->render( array( $this->item() ), array( 'lastRow' => 'justify' ) ) );
		$this->assertStringNotContainsString( 'swg-last-justify', $this->render( array( $this->item() ), array( 'lastRow' => 'left' ) ) );
		$this->assertStringNotContainsString( 'swg-last-justify', $this->render( array( $this->item() ), array( 'lastRow' => '"><script>' ) ) );
	}

	public function test_hostile_text_and_urls_are_escaped_and_no_raw_value_is_echoed(): void {
		$item = new RenderItem( 1, 5, '"><script>alert(1)</script>', '<img src=x onerror=alert(1)><script>alert(2)</script>', '"><script>alert(4)</script>', new ResolvedImage( 'https://example.test/t.jpg?a=1&b="2"', 10, 10 ), new ResolvedImage( 'javascript:alert(1)', 10, 10 ) );
		$html = $this->render(
			array( $item ),
			array(
				'gap'       => '1" onmouseover="alert(1)',
				'rowHeight' => array( 'base' => '<script>' ),
				'lastRow'   => 'justify" onclick="x',
			)
		);

		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'onerror', $html );
		$this->assertStringNotContainsString( 'onmouseover', $html );
		$this->assertStringNotContainsString( 'onclick', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
	}

	public function test_an_empty_page_renders_nothing(): void {
		$this->assertSame( '', $this->render( array() ) );
	}

	public function test_the_schema_validation_matrix(): void {
		$layout    = new JustifiedLayout();
		$schema    = $layout->getSettingsSchema();
		$validator = new SettingsSchemaValidator();

		$this->assertSame( 'justified', $layout->getId() );
		$this->assertSame( 'Justified', $layout->getLabel() );
		$this->assertTrue( $schema['properties']['rowHeight']['x-responsive'] );
		$this->assertTrue(
			$validator->validate(
				$schema,
				array(
					'rowHeight' => 80,
					'gap'       => 0,
					'lastRow'   => 'left',
				)
			)->isValid()
		);
		$this->assertTrue(
			$validator->validate(
				$schema,
				array(
					'rowHeight' => 600,
					'gap'       => 64,
					'lastRow'   => 'justify',
				)
			)->isValid()
		);
		$this->assertTrue(
			$validator->validate(
				$schema,
				array(
					'rowHeight' => array(
						'base' => 120,
						'lg'   => 300,
					),
				)
			)->isValid()
		);
		$this->assertTrue( $validator->validate( $schema, array( 'unknown_future' => true ) )->isValid(), 'Unknown keys are preserved, not rejected.' );
		$this->assertFalse( $validator->validate( $schema, array( 'rowHeight' => 79 ) )->isValid() );
		$this->assertFalse( $validator->validate( $schema, array( 'rowHeight' => 601 ) )->isValid() );
		$this->assertFalse( $validator->validate( $schema, array( 'rowHeight' => array( 'base' => 'tall' ) ) )->isValid() );
		$this->assertFalse( $validator->validate( $schema, array( 'rowHeight' => array( 'md' => 20 ) ) )->isValid() );
		$this->assertFalse( $validator->validate( $schema, array( 'gap' => 65 ) )->isValid() );
		$this->assertFalse( $validator->validate( $schema, array( 'gap' => -1 ) )->isValid() );
		$this->assertFalse( $validator->validate( $schema, array( 'lastRow' => 'hide' ) )->isValid() );
	}

	public function test_image_hints_follow_the_row_height_and_breakpoints(): void {
		$layout = new JustifiedLayout();
		$def    = $layout->imageHints( new GallerySettings( 'justified', SortMode::Manual, array() ) );
		$resp   = $layout->imageHints(
			new GallerySettings(
				'justified',
				SortMode::Manual,
				array(
					'rowHeight' => array(
						'base' => 100,
						'md'   => 300,
					),
				)
			)
		);

		$this->assertSame( 440, $def->displayWidth );
		$this->assertStringEndsWith( 'min(100vw, 440px)', $def->sizes );
		$this->assertSame( 600, $resp->displayWidth );
		$this->assertStringStartsWith( '(min-width: 1280px) min(100vw, 600px), (min-width: 1024px) min(100vw, 600px), (min-width: 768px) min(100vw, 600px), (min-width: 576px) min(100vw, 200px)', $resp->sizes );
		$this->assertStringEndsWith( ', min(100vw, 200px)', $resp->sizes );
		$this->assertSame( 6, $resp->firstRowCount, 'Clamped to the maximum first row.' );
		$this->assertSame( 3, $def->firstRowCount );
	}
}
