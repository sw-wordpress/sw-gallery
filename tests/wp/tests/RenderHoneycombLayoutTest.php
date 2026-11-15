<?php
/**
 * The honeycomb layout: markup contract, validated values only in `style`/class, row flags per breakpoint, schema,
 * image hints, snapshot with fixed ids, REST listing.
 *
 * Update the snapshot after an intended change: UPDATE_SNAPSHOTS=1 composer test:wp -- --filter RenderHoneycombLayoutTest
 *
 * @package sw-gallery
 */

use SWGallery\Core\Domain\Gallery\GallerySettings;
use SWGallery\Core\Domain\Gallery\SortMode;
use SWGallery\Core\Domain\Layout\LayoutRenderRequest;
use SWGallery\Core\Domain\Layout\LoadingStrategy;
use SWGallery\Core\Domain\Layout\RenderItem;
use SWGallery\Core\Domain\Layout\ResolvedImage;
use SWGallery\Core\Domain\Settings\SettingsSchemaValidator;
use SWGallery\WordPress\Rendering\Layout\HoneycombLayout;

class RenderHoneycombLayoutTest extends SwgTestCase {

	/**
	 * @param list<RenderItem>     $items  Items.
	 * @param array<string, mixed> $values Layout settings.
	 */
	private function layout_html( array $items, array $values = array() ): string {
		return ( new HoneycombLayout() )->render( new LayoutRenderRequest( 12, 'swg-12-1', $items, new GallerySettings( 'honeycomb', SortMode::Manual, $values ), 3 ) );
	}

	private function item( int $id ): RenderItem {
		return new RenderItem( $id, 100 + $id, 'Title', 'Cap <em>x</em>', 'A tree', new ResolvedImage( 'https://example.test/t' . $id . '.jpg', 300, 200, 'https://example.test/t.jpg 300w', '33vw' ), null, LoadingStrategy::Lazy, $id, 12 );
	}

	/** @return list<RenderItem> */
	private function items( int $count ): array {
		return array_map( fn ( int $i ): RenderItem => $this->item( $i ), range( 1, $count ) );
	}

	private function dom( string $html ): DOMXPath {
		$document = new DOMDocument();
		$document->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>', LIBXML_NOERROR );
		return new DOMXPath( $document );
	}

	public function test_it_emits_the_lightbox_markup_contract_in_stored_order(): void {
		$xp = $this->dom( $this->layout_html( $this->items( 6 ) ) );

		$this->assertSame( 1, $xp->query( '//div[@class="swg-gallery swg-layout-honeycomb swg-honeycomb-hexagon"][@data-swg-gallery][@data-swg-instance="swg-12-1"][@data-swg-labels]' )->length );
		$links = $xp->query( '//ul[@class="swg-items"][@role="list"]/li[@class="swg-item" or starts-with(@class,"swg-item")]/a[@class="swg-link"]/img/..' );
		$this->assertSame( 6, $links->length );
		for ( $i = 0; $i < 6; $i++ ) {
			$this->assertSame( 'https://example.test/t' . ( $i + 1 ) . '.jpg', $links->item( $i )->getAttribute( 'href' ), 'Stored order.' );
		}
		$this->assertSame( 0, $xp->query( '//svg' )->length, 'Pure CSS: no SVG, no script.' );
		$this->assertSame( '', $this->layout_html( array() ) );
		$this->assertSame( 'honeycomb', ( new HoneycombLayout() )->getId() );
		$this->assertSame( 'Honeycomb', ( new HoneycombLayout() )->getLabel() );
	}

	public function test_odd_row_flags_follow_the_columns_of_every_breakpoint(): void {
		$xp = $this->dom( $this->layout_html( $this->items( 11 ), array( 'columns' => array( 'base' => 2, 'md' => 3, 'xl' => 4 ) ) ) );
		$li = $xp->query( '//li[@class="swg-item"]' );
		$styles = array();
		foreach ( $li as $node ) {
			$styles[] = $node->getAttribute( 'style' );
		}
		// base/sm: 2 columns (rows 0,1,2,... odd rows are items 2,3 / 6,7 / 10); md/lg: 3 (items 3-5, 9-10); xl: 4 (items 4-7).
		$this->assertSame( '', $styles[0] );
		$this->assertSame( '--swg-hs0:1;--swg-hs1:1', $styles[2] );
		$this->assertSame( '--swg-hs0:1;--swg-hs1:1;--swg-hs2:1;--swg-hs3:1', $styles[3] );
		$this->assertSame( '--swg-hs2:1;--swg-hs3:1;--swg-hs4:1', $styles[4] );
		$this->assertSame( '--swg-hs2:1;--swg-hs3:1;--swg-hs4:1', $styles[5] );
		$this->assertSame( '--swg-hs0:1;--swg-hs1:1;--swg-hs4:1', $styles[6] );
		$this->assertSame( '--swg-hs2:1;--swg-hs3:1', $styles[9] );
		$this->assertSame( '--swg-hs0:1;--swg-hs1:1;--swg-hs2:1;--swg-hs3:1', $styles[10] );
	}

	public function test_a_row_flag_exists_exactly_for_the_items_of_odd_rows(): void {
		foreach ( range( 2, 10 ) as $columns ) {
			$xp = $this->dom( $this->layout_html( $this->items( 37 ), array( 'columns' => $columns ) ) );
			foreach ( $xp->query( '//li[@class="swg-item"]' ) as $index => $node ) {
				$expected = 1 === intdiv( $index, $columns ) % 2;
				$this->assertSame( $expected, str_contains( $node->getAttribute( 'style' ), '--swg-hs0:1' ), "columns $columns item $index" );
			}
		}
	}

	public function test_only_validated_values_reach_style_and_class(): void {
		$html = $this->layout_html( $this->items( 3 ), array( 'columns' => '4;background:red', 'gap' => '5px;x', 'shape' => 'diamond" onclick="y' ) );
		$xp   = $this->dom( $html );
		$root = $xp->query( '//div[@data-swg-gallery]' )->item( 0 );
		$this->assertSame( 'swg-gallery swg-layout-honeycomb swg-honeycomb-hexagon', $root->getAttribute( 'class' ) );
		$this->assertSame( '--swg-columns:4;--swg-gap:6px', $root->getAttribute( 'style' ) );
		$this->assertStringNotContainsString( 'onclick', $html );
		$this->assertStringNotContainsString( 'background:red', $html );

		$root = $this->dom( $this->layout_html( $this->items( 1 ), array( 'columns' => array( 'base' => 99, 'md' => -3 ), 'gap' => 99, 'shape' => 'diamond' ) ) )->query( '//div[@data-swg-gallery]' )->item( 0 );
		$this->assertSame( 'swg-gallery swg-layout-honeycomb swg-honeycomb-diamond', $root->getAttribute( 'class' ) );
		$this->assertSame( '--swg-columns:10;--swg-columns-md:2;--swg-gap:32px', $root->getAttribute( 'style' ) );
	}

	public function test_hostile_text_and_urls_are_escaped(): void {
		$item = new RenderItem( 1, 5, '"><script>alert(1)</script>', '<img src=x onerror=alert(1)><script>alert(2)</script>', '"><script>alert(4)</script>', new ResolvedImage( 'https://example.test/t.jpg?a=1&b="2"', 10, 10 ), new ResolvedImage( 'javascript:alert(1)', 10, 10 ) );
		$html = $this->layout_html( array( $item ) );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'onerror', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
	}

	public function test_the_schema_validation_matrix(): void {
		$schema    = ( new HoneycombLayout() )->getSettingsSchema();
		$validator = new SettingsSchemaValidator();

		$this->assertTrue( $validator->validate( $schema, array( 'columns' => 10, 'gap' => 0, 'shape' => 'diamond' ) )->isValid() );
		$this->assertTrue( $validator->validate( $schema, array( 'columns' => array( 'base' => 2, 'xl' => 9 ) ) )->isValid() );
		$this->assertSame( array( 'columns' => 4, 'gap' => 6, 'shape' => 'hexagon' ), $validator->validate( $schema, array() )->values );
		foreach ( array( array( 'columns' => 1 ), array( 'columns' => 11 ), array( 'columns' => array( 'md' => 11 ) ), array( 'gap' => 33 ), array( 'gap' => -1 ), array( 'shape' => 'circle' ), array( 'shape' => 1 ), array( 'gap' => '6' ) ) as $bad ) {
			$this->assertFalse( $validator->validate( $schema, $bad )->isValid(), (string) wp_json_encode( $bad ) );
		}
		$this->assertSame( array( 'hexagon', 'diamond' ), $schema['properties']['shape']['enum'] );
		$this->assertSame( array( 'hexagon', 'diamond' ), array_keys( $schema['properties']['shape']['x-enum-titles'] ) );
	}

	public function test_image_hints_use_the_cell_width_including_the_half_cell(): void {
		$layout = new HoneycombLayout();
		$hints  = $layout->imageHints( new GallerySettings( 'honeycomb', SortMode::Manual, array() ) );
		$this->assertSame( 267, $hints->displayWidth );
		$this->assertStringEndsWith( ', calc(100vw / 4.5)', $hints->sizes );
		$this->assertStringStartsWith( '(min-width: 1280px) calc(100vw / 4.5)', $hints->sizes );
		$this->assertSame( 4, $hints->firstRowCount );

		$hints = $layout->imageHints( new GallerySettings( 'honeycomb', SortMode::Manual, array( 'columns' => array( 'base' => 2, 'md' => 6 ) ) ) );
		$this->assertSame( '(min-width: 1280px) calc(100vw / 6.5), (min-width: 1024px) calc(100vw / 6.5), (min-width: 768px) calc(100vw / 6.5), (min-width: 576px) calc(100vw / 2.5), calc(100vw / 2.5)', $hints->sizes );
		$this->assertSame( 185, $hints->displayWidth );
		$this->assertSame( 6, $hints->firstRowCount );
	}

	public function test_snapshot_with_fixed_ids(): void {
		$actual = rtrim( (string) preg_replace( '/>\s*</', ">\n<", $this->layout_html( $this->items( 7 ), array( 'columns' => array( 'base' => 2, 'md' => 3 ), 'gap' => 8 ) ) ) ) . "\n";
		$file   = __DIR__ . '/../fixtures/render-snapshots/honeycomb.html';
		if ( '' !== (string) getenv( 'UPDATE_SNAPSHOTS' ) ) {
			if ( false !== getenv( 'CI' ) && '' !== (string) getenv( 'CI' ) ) {
				$this->fail( 'UPDATE_SNAPSHOTS is refused in CI.' );
			}
			file_put_contents( $file, $actual );
			$this->addToAssertionCount( 1 );
			return;
		}
		$this->assertFileExists( $file );
		$this->assertSame( (string) file_get_contents( $file ), $actual, 'Honeycomb snapshot changed (intended? UPDATE_SNAPSHOTS=1).' );
	}
}
