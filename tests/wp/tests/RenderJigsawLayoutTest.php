<?php
/**
 * The jigsaw layout: markup contract, validated values only, SVG ids per instance (also after a cache hit), edge
 * consistency read back from the markup, determinism and `variation`, schema, hints, snapshot, REST listing of both
 * geometric layouts.
 *
 * Update the snapshot after an intended change: UPDATE_SNAPSHOTS=1 composer test:wp -- --filter RenderJigsawLayoutTest
 *
 * @package sw-gallery
 */

use SWGallery\Core\Application\Render\EntryPoint;
use SWGallery\Core\Domain\Gallery\GallerySettings;
use SWGallery\Core\Domain\Gallery\SortMode;
use SWGallery\Core\Domain\Layout\Jigsaw\JigsawEdges;
use SWGallery\Core\Domain\Layout\LayoutRenderRequest;
use SWGallery\Core\Domain\Layout\LoadingStrategy;
use SWGallery\Core\Domain\Layout\RenderItem;
use SWGallery\Core\Domain\Layout\ResolvedImage;
use SWGallery\Core\Domain\Settings\SettingsSchemaValidator;
use SWGallery\Tests\Support\RenderFixtures;
use SWGallery\Tests\Support\RestFixtures;
use SWGallery\WordPress\Infrastructure\Services;
use SWGallery\WordPress\Rendering\Layout\JigsawLayout;

class RenderJigsawLayoutTest extends SwgTestCase {

	use RenderFixtures;
	use RestFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->bootRest();
		$this->purgeGalleries();
		Services::reset();
		$this->lockPassword();
		wp_cache_flush();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->lockPassword();
		$this->purgeGalleries();
		Services::reset();
		$this->shutdownRest();
		parent::tear_down();
	}

	/**
	 * @param list<RenderItem>     $items    Items.
	 * @param array<string, mixed> $values   Layout settings.
	 * @param string               $instance Instance id.
	 * @param int                  $revision Revision.
	 */
	private function layout_html( array $items, array $values = array(), string $instance = 'swg-12-1', int $revision = 3 ): string {
		return ( new JigsawLayout() )->render( new LayoutRenderRequest( 12, $instance, $items, new GallerySettings( 'jigsaw', SortMode::Manual, $values ), $revision ) );
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

	/**
	 * Edge code per item and breakpoint number read back from the `--swg-cpN:url(#...-jc<code>)` custom properties
	 * (a breakpoint that is not written inherits the previous one).
	 *
	 * @return list<list<int>> Items x 5 breakpoints.
	 */
	private function codes_from( string $html ): array {
		$result = array();
		foreach ( $this->dom( $html )->query( '//li[@class="swg-item"]' ) as $node ) {
			$this->assertMatchesRegularExpression( '/^--swg-cp0:url\(#swg-12-1-jc\d{1,2}\)(;--swg-cp[1-4]:url\(#swg-12-1-jc\d{1,2}\))*$/', $node->getAttribute( 'style' ) );
			$codes = array();
			preg_match_all( '/--swg-cp(\d):url\(#swg-12-1-jc(\d+)\)/', $node->getAttribute( 'style' ), $m, PREG_SET_ORDER );
			$last = 0;
			foreach ( $m as $match ) {
				$codes[ (int) $match[1] ] = (int) $match[2];
			}
			$row = array();
			for ( $b = 0; $b < 5; $b++ ) {
				$last  = $codes[ $b ] ?? $last;
				$row[] = $last;
			}
			$result[] = $row;
		}
		return $result;
	}

	public function test_it_emits_the_lightbox_markup_contract_in_stored_order(): void {
		$html = $this->layout_html( $this->items( 6 ) );
		$xp   = $this->dom( $html );

		$this->assertSame( 1, $xp->query( '//div[@class="swg-gallery swg-layout-jigsaw"][@data-swg-gallery][@data-swg-instance="swg-12-1"][@data-swg-labels]' )->length );
		$links = $xp->query( '//ul[@class="swg-items"][@role="list"]/li[@class="swg-item"]/a[@class="swg-link"]' );
		$this->assertSame( 6, $links->length );
		for ( $i = 0; $i < 6; $i++ ) {
			$this->assertSame( 'https://example.test/t' . ( $i + 1 ) . '.jpg', $links->item( $i )->getAttribute( 'href' ), 'Stored order.' );
		}
		$svg = $xp->query( '//div[@data-swg-gallery]/svg[@class="swg-jigsaw-defs"][@aria-hidden="true"][@focusable="false"][@width="0"][@height="0"]' );
		$this->assertSame( 1, $svg->length, 'One hidden, zero sized SVG per gallery.' );
		$this->assertStringNotContainsString( 'display:none', $html );
		$this->assertSame( '', $this->layout_html( array() ) );
		$this->assertSame( 'jigsaw', ( new JigsawLayout() )->getId() );
		$this->assertSame( 'Jigsaw', ( new JigsawLayout() )->getLabel() );
	}

	public function test_clip_paths_exist_for_every_used_code_and_ids_are_prefixed_by_the_instance(): void {
		$html = $this->layout_html( $this->items( 13 ), array( 'columns' => array( 'base' => 3, 'xl' => 4 ) ) );
		$xp   = $this->dom( $html );
		$ids  = array();
		// The HTML parser lower-cases the SVG names.
		foreach ( $xp->query( '//clippath' ) as $node ) {
			$this->assertSame( 'objectBoundingBox', $node->getAttribute( 'clippathunits' ) );
			$this->assertMatchesRegularExpression( '/^swg-12-1-jc\d{1,2}$/', $node->getAttribute( 'id' ) );
			$ids[] = $node->getAttribute( 'id' );
		}
		$this->assertSame( $ids, array_values( array_unique( $ids ) ), 'Unique ids.' );
		foreach ( $this->codes_from( $html ) as $row ) {
			foreach ( $row as $code ) {
				$this->assertContains( 'swg-12-1-jc' . $code, $ids );
			}
		}
		preg_match_all( '/ id="([^"]+)"/', $html, $all );
		foreach ( $all[1] as $id ) {
			$this->assertStringStartsWith( 'swg-12-1-', $id );
		}
		$this->assertMatchesRegularExpression( '/^M[\d. ]+(?:[LC][\d. ]+)+Z$/', $xp->query( '//clippath/path/@d' )->item( 0 )->nodeValue );
	}

	public function test_neighbours_interlock_in_the_rendered_markup_for_every_breakpoint(): void {
		$columns = array( 'base' => 2, 'md' => 3, 'xl' => 5 );
		$count   = 17;
		$codes   = $this->codes_from( $this->layout_html( $this->items( $count ), array( 'columns' => $columns ) ) );
		$per     = array( 2, 2, 3, 3, 5 );
		for ( $b = 0; $b < 5; $b++ ) {
			$cols = $per[ $b ];
			for ( $i = 0; $i < $count; $i++ ) {
				$sides = JigsawEdges::sides( $codes[ $i ][ $b ] );
				if ( $i % $cols === $cols - 1 || $i + 1 >= $count ) {
					$this->assertSame( JigsawEdges::FLAT, $sides[1], "flat right edge, item $i breakpoint $b" );
				} else {
					$this->assertSame( 3, $sides[1] + JigsawEdges::sides( $codes[ $i + 1 ][ $b ] )[3], "item $i breakpoint $b" );
				}
				if ( $i >= $cols ) {
					$this->assertSame( 3, JigsawEdges::sides( $codes[ $i - $cols ][ $b ] )[2] + $sides[0] );
				} else {
					$this->assertSame( JigsawEdges::FLAT, $sides[0] );
				}
				if ( 0 === $i % $cols ) {
					$this->assertSame( JigsawEdges::FLAT, $sides[3] );
				}
			}
		}
	}

	public function test_the_arrangement_is_deterministic_and_follows_variation_gallery_and_revision(): void {
		$items = $this->items( 20 );
		$base  = $this->layout_html( $items );
		$this->assertSame( $base, $this->layout_html( $items ) );
		$this->assertNotSame( $this->codes_from( $base ), $this->codes_from( $this->layout_html( $items, array( 'variation' => 1 ) ) ), 'variation changes the edges' );
		$this->assertNotSame( $this->codes_from( $base ), $this->codes_from( $this->layout_html( $items, array( 'variation' => 99 ) ) ) );
		$this->assertNotSame( $this->codes_from( $base ), $this->codes_from( $this->layout_html( $items, array(), 'swg-12-1', 4 ) ), 'the revision is part of the seed' );
	}

	public function test_only_validated_values_reach_style_and_markup(): void {
		$html = $this->layout_html( $this->items( 3 ), array( 'columns' => '4;background:red', 'gap' => '5px;x', 'ratio' => '1:1;color:red', 'tab' => 'x" onclick="y', 'variation' => '1;2' ) );
		$root = $this->dom( $html )->query( '//div[@data-swg-gallery]' )->item( 0 );
		$this->assertSame( '--swg-columns:4;--swg-gap:0px;--swg-tab:18;--swg-jr:1;--swg-jaspect:1/1', $root->getAttribute( 'style' ) );
		$this->assertStringNotContainsString( 'onclick', $html );
		$this->assertStringNotContainsString( 'background:red', $html );

		$root = $this->dom( $this->layout_html( $this->items( 2 ), array( 'columns' => array( 'base' => 99, 'md' => -3 ), 'gap' => 99, 'ratio' => '4:3', 'tab' => 99 ) ) )->query( '//div[@data-swg-gallery]' )->item( 0 );
		$this->assertSame( '--swg-columns:8;--swg-columns-md:2;--swg-gap:16px;--swg-tab:30;--swg-jr:1.3333;--swg-jaspect:4/3', $root->getAttribute( 'style' ) );
		$root = $this->dom( $this->layout_html( $this->items( 2 ), array( 'ratio' => '3:2', 'tab' => 1 ) ) )->query( '//div[@data-swg-gallery]' )->item( 0 );
		$this->assertSame( '--swg-columns:4;--swg-gap:0px;--swg-tab:8;--swg-jr:1.5;--swg-jaspect:3/2', $root->getAttribute( 'style' ) );
	}

	public function test_hostile_text_and_urls_are_escaped(): void {
		$item = new RenderItem( 1, 5, '"><script>alert(1)</script>', '<img src=x onerror=alert(1)><script>alert(2)</script>', '"><script>alert(4)</script>', new ResolvedImage( 'https://example.test/t.jpg?a=1&b="2"', 10, 10 ), new ResolvedImage( 'javascript:alert(1)', 10, 10 ) );
		$html = $this->layout_html( array( $item ), array(), 'swg-12-1' );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'onerror', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
	}

	public function test_the_schema_validation_matrix(): void {
		$schema    = ( new JigsawLayout() )->getSettingsSchema();
		$validator = new SettingsSchemaValidator();

		$this->assertTrue( $validator->validate( $schema, array( 'columns' => 8, 'gap' => 16, 'ratio' => '3:2', 'tab' => 30, 'variation' => 99 ) )->isValid() );
		$this->assertTrue( $validator->validate( $schema, array( 'columns' => array( 'base' => 2, 'xl' => 7 ) ) )->isValid() );
		$this->assertSame( array( 'columns' => 4, 'gap' => 0, 'ratio' => '1:1', 'tab' => 18, 'variation' => 0 ), $validator->validate( $schema, array() )->values );
		$bad = array(
			array( 'columns' => 1 ),
			array( 'columns' => 9 ),
			array( 'columns' => array( 'lg' => 9 ) ),
			array( 'gap' => 17 ),
			array( 'gap' => -1 ),
			array( 'ratio' => '16:9' ),
			array( 'ratio' => 1 ),
			array( 'tab' => 7 ),
			array( 'tab' => 31 ),
			array( 'tab' => '18' ),
			array( 'variation' => 100 ),
			array( 'variation' => -1 ),
			array( 'variation' => 1.5 ),
		);
		foreach ( $bad as $values ) {
			$this->assertFalse( $validator->validate( $schema, $values )->isValid(), (string) wp_json_encode( $values ) );
		}
		$this->assertTrue( $schema['properties']['columns']['x-responsive'] );
		$this->assertSame( array( '1:1', '4:3', '3:2' ), array_keys( $schema['properties']['ratio']['x-enum-titles'] ) );
	}

	public function test_image_hints_account_for_the_tab_overhang(): void {
		$layout = new JigsawLayout();
		$hints  = $layout->imageHints( new GallerySettings( 'jigsaw', SortMode::Manual, array() ) );
		$this->assertSame( 408, $hints->displayWidth );
		$this->assertStringEndsWith( ', calc(100vw * 1.36 / 4)', $hints->sizes );
		$this->assertSame( 4, $hints->firstRowCount );
		$hints = $layout->imageHints( new GallerySettings( 'jigsaw', SortMode::Manual, array( 'columns' => array( 'base' => 2, 'md' => 6 ), 'tab' => 30 ) ) );
		$this->assertStringContainsString( '(min-width: 768px) calc(100vw * 1.6 / 6)', $hints->sizes );
		$this->assertStringEndsWith( ', calc(100vw * 1.6 / 2)', $hints->sizes );
		$this->assertSame( 6, $hints->firstRowCount );
	}

	public function test_snapshot_with_fixed_ids(): void {
		$actual = rtrim( (string) preg_replace( '/>\s*</', ">\n<", $this->layout_html( $this->items( 7 ), array( 'columns' => array( 'base' => 2, 'md' => 3 ), 'gap' => 2, 'tab' => 20 ) ) ) ) . "\n";
		$file   = __DIR__ . '/../fixtures/render-snapshots/jigsaw.html';
		if ( '' !== (string) getenv( 'UPDATE_SNAPSHOTS' ) ) {
			if ( false !== getenv( 'CI' ) && '' !== (string) getenv( 'CI' ) ) {
				$this->fail( 'UPDATE_SNAPSHOTS is refused in CI.' );
			}
			file_put_contents( $file, $actual );
			$this->addToAssertionCount( 1 );
			return;
		}
		$this->assertFileExists( $file );
		$this->assertSame( (string) file_get_contents( $file ), $actual, 'Jigsaw snapshot changed (intended? UPDATE_SNAPSHOTS=1).' );
	}

	/**
	 * Two galleries on one page and a cache hit: every embed owns its ids, the `url(#...)` references follow.
	 */
	public function test_ids_and_references_follow_the_instance_through_the_cache(): void {
		$author  = $this->makeUser( 'author' );
		$gallery = $this->makeGalleryPost( $author, 'publish', '', 'jigsaw', 'manual', array( 'columns' => 3 ) );
		$images  = array();
		for ( $i = 1; $i <= 8; $i++ ) {
			$images[] = $this->makeImage( "Image $i", 800, 600, array(), null, "Alt $i" );
		}
		$this->addItems( $gallery, $images );

		wp_set_current_user( 0 );
		Services::reset();
		$renderer = Services::gallery_renderer();
		$first    = $renderer->render( $gallery, EntryPoint::Shortcode )->html;
		$second   = $renderer->render( $gallery, EntryPoint::Shortcode )->html;

		$this->assertStringContainsString( 'data-swg-instance="swg-' . $gallery . '-1"', $first );
		$this->assertStringContainsString( 'data-swg-instance="swg-' . $gallery . '-2"', $second, 'The second embed is a cache hit.' );
		$this->assertStringNotContainsString( 'swg-instance-placeholder', $first . $second );
		foreach ( array( 1 => $first, 2 => $second ) as $number => $html ) {
			$prefix = 'swg-' . $gallery . '-' . $number . '-jc';
			preg_match_all( '/ id="([^"]+)"/', $html, $ids );
			$this->assertNotEmpty( $ids[1] );
			foreach ( $ids[1] as $id ) {
				$this->assertStringStartsWith( $prefix, $id );
			}
			preg_match_all( '/url\(#([^)]+)\)/', $html, $refs );
			$this->assertNotEmpty( $refs[1] );
			foreach ( $refs[1] as $ref ) {
				$this->assertContains( $ref, $ids[1], 'Every reference points at an id of the same embed.' );
			}
		}
		$svg = static fn ( string $html ): string => (string) preg_replace( '#^.*(<svg.*</svg>).*$#s', '$1', $html );
		$this->assertSame( str_replace( 'swg-' . $gallery . '-1', 'swg-' . $gallery . '-2', $svg( $first ) ), $svg( $second ), 'The definitions of a hit equal a fresh render except for the instance id.' );
		$merged = array_merge( $this->all_ids( $first ), $this->all_ids( $second ) );
		$this->assertSame( count( $merged ), count( array_unique( $merged ) ), 'No id appears twice on the page.' );
	}

	/** @return list<string> */
	private function all_ids( string $html ): array {
		preg_match_all( '/ id="([^"]+)"/', $html, $ids );
		return $ids[1];
	}

	public function test_both_geometric_layouts_are_listed_with_their_schemas(): void {
		$data    = $this->data( $this->call( 'GET', '/layouts', array(), $this->makeUser( 'author' ) ) );
		$layouts = array_column( $data['layouts'], null, 'id' );
		$this->assertSame( 'Jigsaw', $layouts['jigsaw']['label'] );
		$this->assertSame( array( 'columns', 'gap', 'ratio', 'tab', 'variation' ), array_keys( $layouts['jigsaw']['settingsSchema']['properties'] ) );
		$this->assertSame( 'Honeycomb', $layouts['honeycomb']['label'] );
		$this->assertSame( array( 'columns', 'gap', 'shape' ), array_keys( $layouts['honeycomb']['settingsSchema']['properties'] ) );
		$this->assertSame( array( 'hexagon', 'diamond' ), $layouts['honeycomb']['settingsSchema']['properties']['shape']['enum'] );
	}
}
