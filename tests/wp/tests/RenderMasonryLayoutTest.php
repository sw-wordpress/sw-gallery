<?php
/**
 * The masonry layout: row-ordered markup, settings, image hints and the reported script asset.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Application\Render\RenderAsset;
use SWGallery\Core\Domain\Gallery\GallerySettings;
use SWGallery\Core\Domain\Gallery\SortMode;
use SWGallery\Core\Domain\Layout\LayoutRenderRequest;
use SWGallery\Core\Domain\Layout\RenderItem;
use SWGallery\Core\Domain\Layout\ResolvedImage;
use SWGallery\Core\Domain\Settings\SettingsSchemaValidator;
use SWGallery\WordPress\Rendering\Layout\MasonryLayout;

class RenderMasonryLayoutTest extends SwgTestCase {

	/**
	 * @param list<RenderItem>     $items  Items.
	 * @param array<string, mixed> $values Layout settings.
	 */
	private function render( array $items, array $values = array() ): string {
		return ( new MasonryLayout() )->render( new LayoutRenderRequest( 12, 'swg-12-1', $items, new GallerySettings( 'masonry', SortMode::Manual, $values ) ) );
	}

	private function item( int $id = 1, string $caption = '' ): RenderItem {
		return new RenderItem( $id, 100 + $id, 'Title', $caption, 'A tree', new ResolvedImage( 'https://example.test/t' . $id . '.jpg', 300, 200 + $id * 10, 'https://example.test/t.jpg 300w', '33vw' ) );
	}

	private function dom( string $html ): DOMXPath {
		$document = new DOMDocument();
		$document->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>', LIBXML_NOERROR );
		return new DOMXPath( $document );
	}

	private function style( string $html ): string {
		return $this->dom( $html )->query( '//div[@data-swg-gallery]' )->item( 0 )->getAttribute( 'style' );
	}

	public function test_it_emits_row_ordered_lightbox_markup(): void {
		$xp = $this->dom( $this->render( array( $this->item( 1, 'A <em>nice</em> tree' ), $this->item( 2 ), $this->item( 3 ) ) ) );

		$this->assertSame( 1, $xp->query( '//div[@class="swg-gallery swg-layout-masonry"][@data-swg-gallery][@data-swg-instance="swg-12-1"]' )->length );
		$links = $xp->query( '//ul[@class="swg-items"][@role="list"]/li[@class="swg-item"]/a[@class="swg-link"]' );
		$this->assertSame( 3, $links->length );
		$this->assertSame( array( 'https://example.test/t1.jpg', 'https://example.test/t2.jpg', 'https://example.test/t3.jpg' ), array( $links->item( 0 )->getAttribute( 'href' ), $links->item( 1 )->getAttribute( 'href' ), $links->item( 2 )->getAttribute( 'href' ) ), 'Stored order.' );
		$img = $xp->query( '//a/img' )->item( 0 );
		$this->assertSame( '300', $img->getAttribute( 'width' ), 'Ratios are known before the image loads.' );
		$this->assertSame( '210', $img->getAttribute( 'height' ) );
		$this->assertSame( 1, $xp->query( '//p[@class="swg-caption"]/em' )->length );
		$this->assertSame( 0, $xp->query( '//li[@style]' )->length, 'No per-item inline styles; the script packs.' );
	}

	public function test_columns_and_gap_become_validated_custom_properties(): void {
		$this->assertSame(
			'--swg-columns:4;--swg-gap:16px',
			$this->style(
				$this->render(
					array( $this->item() ),
					array(
						'columns' => 4,
						'gap'     => 16,
					)
				)
			)
		);
		$this->assertSame(
			'--swg-columns:2;--swg-columns-md:4;--swg-columns-xl:6;--swg-gap:8px',
			$this->style(
				$this->render(
					array( $this->item() ),
					array(
						'columns' => array(
							'base' => 2,
							'md'   => 4,
							'xl'   => 6,
						),
					)
				)
			)
		);
		$this->assertSame( '--swg-columns:3;--swg-gap:8px', $this->style( $this->render( array( $this->item() ) ) ) );
		$this->assertSame(
			'--swg-columns:3;--swg-gap:8px',
			$this->style(
				$this->render(
					array( $this->item() ),
					array(
						'columns' => '1;background:red',
						'gap'     => 'x',
					)
				)
			)
		);
		$this->assertSame(
			'--swg-columns:12;--swg-gap:0px',
			$this->style(
				$this->render(
					array( $this->item() ),
					array(
						'columns' => 99,
						'gap'     => -3,
					)
				)
			)
		);
	}

	public function test_hostile_text_and_urls_are_escaped(): void {
		$item = new RenderItem( 1, 5, '"><script>alert(1)</script>', '<img src=x onerror=alert(1)><script>alert(2)</script>', '"><script>alert(4)</script>', new ResolvedImage( 'https://example.test/t.jpg?a=1&b="2"', 10, 10 ), new ResolvedImage( 'javascript:alert(1)', 10, 10 ) );
		$html = $this->render( array( $item ), array( 'columns' => '1" onclick="x' ) );

		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'onerror', $html );
		$this->assertStringNotContainsString( 'onclick', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
	}

	public function test_an_empty_page_renders_nothing_and_the_layout_reports_the_script(): void {
		$layout = new MasonryLayout();
		$this->assertSame( '', $this->render( array() ) );
		$this->assertSame( array( RenderAsset::Masonry ), $layout->renderAssets() );
		$this->assertSame( 'masonry', $layout->getId() );
		$this->assertSame( 'Masonry', $layout->getLabel() );
	}

	public function test_the_schema_validation_matrix(): void {
		$schema    = ( new MasonryLayout() )->getSettingsSchema();
		$validator = new SettingsSchemaValidator();

		$this->assertTrue( $schema['properties']['columns']['x-responsive'] );
		$this->assertTrue(
			$validator->validate(
				$schema,
				array(
					'columns' => 1,
					'gap'     => 0,
				)
			)->isValid()
		);
		$this->assertTrue(
			$validator->validate(
				$schema,
				array(
					'columns' => 12,
					'gap'     => 64,
				)
			)->isValid()
		);
		$this->assertTrue(
			$validator->validate(
				$schema,
				array(
					'columns' => array(
						'base' => 1,
						'lg'   => 6,
					),
				)
			)->isValid()
		);
		$this->assertTrue( $validator->validate( $schema, array( 'unknown_future' => true ) )->isValid(), 'Unknown keys are preserved, not rejected.' );
		$this->assertFalse( $validator->validate( $schema, array( 'columns' => 0 ) )->isValid() );
		$this->assertFalse( $validator->validate( $schema, array( 'columns' => 13 ) )->isValid() );
		$this->assertFalse( $validator->validate( $schema, array( 'columns' => array( 'base' => 'many' ) ) )->isValid() );
		$this->assertFalse( $validator->validate( $schema, array( 'gap' => 65 ) )->isValid() );
	}

	public function test_image_hints_use_the_breakpoints_of_the_stylesheet(): void {
		$layout = new MasonryLayout();
		$def    = $layout->imageHints( new GallerySettings( 'masonry', SortMode::Manual, array() ) );
		$resp   = $layout->imageHints(
			new GallerySettings(
				'masonry',
				SortMode::Manual,
				array(
					'columns' => array(
						'base' => 1,
						'md'   => 4,
					),
				)
			)
		);

		$this->assertSame( 400, $def->displayWidth );
		$this->assertSame( 'calc(100vw / 3)', $def->sizes );
		$this->assertSame( 300, $resp->displayWidth );
		$this->assertSame( '(min-width: 768px) calc(100vw / 4), calc(100vw / 1)', $resp->sizes );
		$this->assertSame( 4, $resp->firstRowCount );
	}
}
