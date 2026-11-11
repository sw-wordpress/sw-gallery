<?php
/**
 * The grid layout: markup contract of the lightbox, contextual escaping, settings schema.
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

class GridLayoutTest extends SwgTestCase {

	/**
	 * @param list<RenderItem>     $items  Items.
	 * @param array<string, mixed> $values Layout settings.
	 */
	private function render( array $items, array $values = array() ): string {
		return ( new GridLayout() )->render( new LayoutRenderRequest( 12, 'swg-12-1', $items, new GallerySettings( 'grid', SortMode::Manual, $values ) ) );
	}

	private function item( int $id = 1, string $alt = 'A tree', string $caption = '', ?ResolvedImage $full = null ): RenderItem {
		return new RenderItem( $id, 100 + $id, 'Title', $caption, $alt, new ResolvedImage( 'https://example.test/t.jpg', 300, 200, 'https://example.test/t.jpg 300w', '33vw' ), $full );
	}

	private function dom( string $html ): DOMXPath {
		$document = new DOMDocument();
		$document->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>', LIBXML_NOERROR );
		return new DOMXPath( $document );
	}

	public function test_it_emits_the_lightbox_markup_contract(): void {
		$full = new ResolvedImage( 'https://example.test/full.jpg', 2000, 1333, 'https://example.test/s.jpg 800w, https://example.test/full.jpg 2000w', '100vw' );
		$html = $this->render( array( $this->item( 1, 'A tree', 'A <strong>nice</strong> tree', $full ), $this->item( 2 ) ) );
		$xp   = $this->dom( $html );

		$this->assertSame( 1, $xp->query( '//div[@class="swg-gallery swg-layout-grid"][@data-swg-gallery][@data-swg-instance="swg-12-1"]' )->length );
		$this->assertSame( 2, $xp->query( '//ul[@class="swg-items"][@role="list"]/li[@class="swg-item"]' )->length );
		$link = $xp->query( '//a[@class="swg-link"]' )->item( 0 );
		$this->assertSame( 'https://example.test/full.jpg', $link->getAttribute( 'href' ) );
		$this->assertSame( '2000', $link->getAttribute( 'data-swg-full-width' ) );
		$this->assertSame( '1333', $link->getAttribute( 'data-swg-full-height' ) );
		$this->assertSame( '100vw', $link->getAttribute( 'data-swg-full-sizes' ) );
		$img = $xp->query( '//a[@class="swg-link"]/img' )->item( 0 );
		$this->assertSame( 'A tree', $img->getAttribute( 'alt' ) );
		$this->assertSame( '300', $img->getAttribute( 'width' ) );
		$this->assertSame( '200', $img->getAttribute( 'height' ) );
		$this->assertSame( 'lazy', $img->getAttribute( 'loading' ) );
		$this->assertSame( 'async', $img->getAttribute( 'decoding' ) );
		$this->assertSame( 1, $xp->query( '//p[@class="swg-caption"]/strong' )->length );
		// An item without a separate full image links its thumbnail.
		$this->assertSame( 'https://example.test/t.jpg', $xp->query( '//li[2]/a' )->item( 0 )->getAttribute( 'href' ) );
	}

	public function test_the_labels_are_valid_json_with_the_counter_placeholders(): void {
		$xp     = $this->dom( $this->render( array( $this->item() ) ) );
		$labels = json_decode( $xp->query( '//div[@data-swg-gallery]' )->item( 0 )->getAttribute( 'data-swg-labels' ), true );

		$this->assertSame( array( 'dialog', 'close', 'previous', 'next', 'counter', 'error' ), array_keys( $labels ) );
		$this->assertStringContainsString( '{current}', $labels['counter'] );
		$this->assertStringContainsString( '{total}', $labels['counter'] );
	}

	public function test_hostile_text_and_urls_are_escaped_for_their_context(): void {
		$full = new ResolvedImage( 'javascript:alert(1)', 10, 10 );
		$html = $this->render(
			array( new RenderItem( 1, 5, '"><script>alert(1)</script>', '<img src=x onerror=alert(1)><script>alert(2)</script><a href="javascript:alert(3)">x</a>', '"><script>alert(4)</script>', new ResolvedImage( 'https://example.test/t.jpg?a=1&b="2"', 10, 10 ), $full ) )
		);

		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'onerror', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$xp = $this->dom( $html );
		$this->assertSame( '"><script>alert(4)</script>', $xp->query( '//img' )->item( 0 )->getAttribute( 'alt' ), 'The alt text round-trips as text.' );
	}

	public function test_columns_gap_and_responsive_columns_become_custom_properties(): void {
		$base = $this->dom( $this->render( array( $this->item() ), array( 'columns' => 4, 'gap' => 16 ) ) )->query( '//div[@data-swg-gallery]' )->item( 0 )->getAttribute( 'style' );
		$resp = $this->dom( $this->render( array( $this->item() ), array( 'columns' => array( 'base' => 2, 'md' => 4, 'xl' => 6 ) ) ) )->query( '//div[@data-swg-gallery]' )->item( 0 )->getAttribute( 'style' );
		$def  = $this->dom( $this->render( array( $this->item() ) ) )->query( '//div[@data-swg-gallery]' )->item( 0 )->getAttribute( 'style' );
		$junk = $this->dom( $this->render( array( $this->item() ), array( 'columns' => '1;background:red', 'gap' => 'x' ) ) )->query( '//div[@data-swg-gallery]' )->item( 0 )->getAttribute( 'style' );

		$this->assertSame( '--swg-columns:4;--swg-gap:16px', $base );
		$this->assertSame( '--swg-columns:2;--swg-columns-md:4;--swg-columns-xl:6;--swg-gap:8px', $resp );
		$this->assertSame( '--swg-columns:3;--swg-gap:8px', $def );
		$this->assertSame( '--swg-columns:3;--swg-gap:8px', $junk, 'Only validated integers may reach the style attribute.' );
	}

	public function test_an_empty_page_renders_nothing(): void {
		$this->assertSame( '', $this->render( array() ) );
	}

	public function test_the_schema_accepts_valid_and_rejects_invalid_settings(): void {
		$schema    = ( new GridLayout() )->getSettingsSchema();
		$validator = new SettingsSchemaValidator();

		$this->assertTrue( $validator->validate( $schema, array( 'columns' => 3, 'gap' => 8 ) )->isValid() );
		$this->assertTrue( $validator->validate( $schema, array( 'columns' => array( 'base' => 1, 'lg' => 6 ) ) )->isValid() );
		$this->assertTrue( $validator->validate( $schema, array( 'unknown_future' => true ) )->isValid(), 'Unknown keys are preserved, not rejected.' );
		$this->assertFalse( $validator->validate( $schema, array( 'columns' => 0 ) )->isValid() );
		$this->assertFalse( $validator->validate( $schema, array( 'columns' => 13 ) )->isValid() );
		$this->assertFalse( $validator->validate( $schema, array( 'gap' => -1 ) )->isValid() );
		$this->assertFalse( $validator->validate( $schema, array( 'columns' => array( 'base' => 'many' ) ) )->isValid() );
		$this->assertSame( 'grid', ( new GridLayout() )->getId() );
	}
}
