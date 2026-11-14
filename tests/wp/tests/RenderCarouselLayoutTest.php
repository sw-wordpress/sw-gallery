<?php
/**
 * The carousel layout (film strip): accessible scroll-snap markup, settings, image hints and the reported script asset.
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
use SWGallery\WordPress\Rendering\Layout\CarouselLayout;

class RenderCarouselLayoutTest extends SwgTestCase {

	/**
	 * @param list<RenderItem>     $items  Items.
	 * @param array<string, mixed> $values Layout settings.
	 */
	private function render( array $items, array $values = array() ): string {
		return ( new CarouselLayout() )->render( new LayoutRenderRequest( 12, 'swg-12-1', $items, new GallerySettings( 'carousel', SortMode::Manual, $values ) ) );
	}

	private function item( int $id = 1, string $caption = '', int $width = 300, int $height = 200, string $alt = 'A tree' ): RenderItem {
		return new RenderItem( $id, 100 + $id, 'Title ' . $id, $caption, $alt, new ResolvedImage( 'https://example.test/t' . $id . '.jpg', $width, $height, 'https://example.test/t.jpg 300w', '33vw' ), new ResolvedImage( 'https://example.test/f' . $id . '.jpg', 2000, 1333 ), \SWGallery\Core\Domain\Layout\LoadingStrategy::Lazy, $id, 3 );
	}

	private function dom( string $html ): DOMXPath {
		$document = new DOMDocument();
		$document->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>', LIBXML_NOERROR );
		return new DOMXPath( $document );
	}

	private function style( string $html ): string {
		return $this->dom( $html )->query( '//div[@data-swg-gallery]' )->item( 0 )->getAttribute( 'style' );
	}

	public function test_it_emits_an_accessible_scroll_container_with_lightbox_links_in_order(): void {
		$html = $this->render( array( $this->item( 1, 'A <em>nice</em> tree' ), $this->item( 2 ), $this->item( 3 ) ) );
		$xp   = $this->dom( $html );

		$this->assertSame( 1, $xp->query( '//div[@class="swg-gallery swg-layout-carousel"][@data-swg-gallery][@data-swg-instance="swg-12-1"]' )->length );
		$viewport = $xp->query( '//div[@class="swg-carousel__frame"]/div[@class="swg-carousel__viewport"]' );
		$this->assertSame( 1, $viewport->length );
		$this->assertSame( '0', $viewport->item( 0 )->getAttribute( 'tabindex' ), 'A scroll container must be keyboard focusable.' );
		$this->assertSame( 'region', $viewport->item( 0 )->getAttribute( 'role' ) );
		$this->assertSame( 'Image carousel', $viewport->item( 0 )->getAttribute( 'aria-label' ) );

		$links = $xp->query( '//ul[@class="swg-items"][@role="list"]/li[@class="swg-item"]/a[@class="swg-link"]' );
		$this->assertSame( 3, $links->length, 'Every image is exactly one lightbox link.' );
		$this->assertSame( 3, $xp->query( '//a[@class="swg-link"]' )->length );
		$this->assertSame(
			array( 'https://example.test/f1.jpg', 'https://example.test/f2.jpg', 'https://example.test/f3.jpg' ),
			array( $links->item( 0 )->getAttribute( 'href' ), $links->item( 1 )->getAttribute( 'href' ), $links->item( 2 )->getAttribute( 'href' ) ),
			'DOM order is the stored order.'
		);
		$this->assertSame( 1, $xp->query( '//p[@class="swg-caption"]/em' )->length );
		$this->assertSame( 0, $xp->query( '//button' )->length, 'Buttons are inserted by the script only.' );
	}

	public function test_labels_carry_the_translatable_name_next_to_the_lightbox_labels(): void {
		$labels = json_decode( $this->dom( $this->render( array( $this->item() ) ) )->query( '//div[@data-swg-gallery]' )->item( 0 )->getAttribute( 'data-swg-labels' ), true );
		$this->assertSame( 'Image carousel', $labels['carousel'] );
		foreach ( array( 'dialog', 'close', 'previous', 'next', 'counter', 'error' ) as $key ) {
			$this->assertArrayHasKey( $key, $labels );
		}
	}

	public function test_each_slide_carries_its_validated_aspect_ratio(): void {
		$xp    = $this->dom( $this->render( array( $this->item( 1, '', 300, 200 ), $this->item( 2, '', 200, 300 ), $this->item( 3, '', 4000, 100 ), $this->item( 4, '', 0, 0 ) ) ) );
		$items = $xp->query( '//li[@class="swg-item"]' );
		$this->assertSame( '--swg-ar:1.5', $items->item( 0 )->getAttribute( 'style' ) );
		$this->assertSame( '--swg-ar:0.6667', $items->item( 1 )->getAttribute( 'style' ) );
		$this->assertSame( '--swg-ar:4', $items->item( 2 )->getAttribute( 'style' ), 'Panoramas are clamped.' );
		$this->assertSame( '--swg-ar:1.5', $items->item( 3 )->getAttribute( 'style' ), 'Unknown dimensions use the fallback.' );
	}

	public function test_height_gap_and_radius_become_validated_custom_properties(): void {
		$style = fn( array $values ): string => $this->style( $this->render( array( $this->item() ), $values ) );
		$this->assertSame( '--swg-carousel-height:320px;--swg-gap:8px;--swg-thumb-radius:4px', $style( array() ) );
		$this->assertSame(
			'--swg-carousel-height:200px;--swg-gap:16px;--swg-thumb-radius:0px',
			$style(
				array(
					'height' => 200,
					'gap'    => 16,
					'radius' => 0,
				)
			)
		);
		$this->assertSame(
			'--swg-carousel-height:240px;--swg-carousel-height-md:400px;--swg-carousel-height-xl:600px;--swg-gap:8px;--swg-thumb-radius:4px',
			$style(
				array(
					'height' => array(
						'base' => 240,
						'md'   => 400,
						'xl'   => 600,
					),
				)
			)
		);
		$this->assertSame( '--swg-carousel-height:800px;--swg-gap:0px;--swg-thumb-radius:64px', $style(
			array(
				'height' => 9999,
				'gap'    => -3,
				'radius' => 9999,
			)
		) );
		$this->assertSame( '--swg-carousel-height:120px;--swg-gap:8px;--swg-thumb-radius:4px', $style( array( 'height' => 5 ) ) );
		$this->assertSame(
			'--swg-carousel-height:320px;--swg-gap:8px;--swg-thumb-radius:4px',
			$style(
				array(
					'height' => '1;background:red',
					'gap'    => 'x',
					'radius' => 'red;} body{display:none',
				)
			),
			'Hostile values never reach the style attribute.'
		);
	}

	public function test_snap_and_buttons_become_classes_only_for_known_values(): void {
		$class = fn( array $values ): string => $this->dom( $this->render( array( $this->item() ), $values ) )->query( '//div[@data-swg-gallery]' )->item( 0 )->getAttribute( 'class' );
		$this->assertSame( 'swg-gallery swg-layout-carousel', $class( array() ) );
		$this->assertSame( 'swg-gallery swg-layout-carousel swg-snap-center', $class( array( 'snap' => 'center' ) ) );
		$this->assertSame( 'swg-gallery swg-layout-carousel swg-no-buttons', $class( array( 'buttons' => false ) ) );
		$this->assertSame( 'swg-gallery swg-layout-carousel', $class( array( 'snap' => 'x" onclick="y' ) ) );
		$this->assertSame( 'swg-gallery swg-layout-carousel', $class( array( 'buttons' => 'no' ) ) );
	}

	public function test_hostile_text_and_urls_are_escaped(): void {
		$item = new RenderItem( 1, 5, '"><script>alert(1)</script>', '<img src=x onerror=alert(1)><script>alert(2)</script>', '"><script>alert(4)</script>', new ResolvedImage( 'https://example.test/t.jpg?a=1&b="2"', 10, 10 ), new ResolvedImage( 'javascript:alert(1)', 10, 10 ) );
		$html = $this->render( array( $item ), array( 'height' => '1" onclick="x' ) );

		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'onerror', $html );
		$this->assertStringNotContainsString( 'onclick', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
	}

	public function test_an_empty_page_renders_nothing_and_the_layout_reports_the_script(): void {
		$layout = new CarouselLayout();
		$this->assertSame( '', $this->render( array() ) );
		$this->assertSame( array( RenderAsset::Carousel ), $layout->renderAssets() );
		$this->assertSame( 'carousel', $layout->getId() );
		$this->assertSame( 'Carousel', $layout->getLabel() );
	}

	public function test_the_schema_validation_matrix(): void {
		$schema    = ( new CarouselLayout() )->getSettingsSchema();
		$validator = new SettingsSchemaValidator();

		$this->assertTrue( $schema['properties']['height']['x-responsive'] );
		$this->assertSame( array( 'start', 'center' ), $schema['properties']['snap']['enum'] );
		$this->assertSame( array( 'start', 'center' ), array_keys( $schema['properties']['snap']['x-enum-titles'] ) );
		foreach ( array(
			array(
				'height'  => 120,
				'gap'     => 0,
				'radius'  => 0,
				'buttons' => false,
				'snap'    => 'center',
			),
			array(
				'height'  => 800,
				'gap'     => 64,
				'radius'  => 64,
				'buttons' => true,
				'snap'    => 'start',
			),
			array(
				'height' => array(
					'base' => 200,
					'lg'   => 500,
				),
			),
			array( 'unknown_future' => true ),
		) as $valid ) {
			$this->assertTrue( $validator->validate( $schema, $valid )->isValid(), (string) wp_json_encode( $valid ) );
		}
		foreach ( array(
			array( 'height' => 119 ),
			array( 'height' => 801 ),
			array( 'height' => array( 'base' => 'tall' ) ),
			array( 'gap' => 65 ),
			array( 'gap' => -1 ),
			array( 'radius' => 65 ),
			array( 'buttons' => 'yes' ),
			array( 'snap' => 'end' ),
			array( 'snap' => 1 ),
		) as $invalid ) {
			$this->assertFalse( $validator->validate( $schema, $invalid )->isValid(), (string) wp_json_encode( $invalid ) );
		}
	}

	public function test_image_hints_request_slides_by_height(): void {
		$layout = new CarouselLayout();
		$def    = $layout->imageHints( new GallerySettings( 'carousel', SortMode::Manual, array() ) );
		$resp   = $layout->imageHints(
			new GallerySettings(
				'carousel',
				SortMode::Manual,
				array(
					'height' => array(
						'base' => 200,
						'md'   => 400,
					),
				)
			)
		);

		$this->assertSame( 480, $def->displayWidth );
		$this->assertSame( 'min(100vw, 480px)', $def->sizes );
		$this->assertSame( 2, $def->firstRowCount, 'floor(1200 / (320 * 1.5)).' );
		$this->assertSame( 600, $resp->displayWidth );
		$this->assertSame( '(min-width: 768px) min(100vw, 600px), min(100vw, 300px)', $resp->sizes );
		$this->assertSame( 4, $resp->firstRowCount );
	}
}
