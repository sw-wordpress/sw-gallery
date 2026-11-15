<?php
/**
 * The magazine layout: nested list markup contract, validated enums and numbers only in class/style, schemas, image
 * hints, a snapshot with fixed ids, and the pipeline (determinism, cache keys, item settings).
 *
 * Update the snapshot after an intended change: UPDATE_SNAPSHOTS=1 composer test:wp -- --filter RenderMagazineLayoutTest
 *
 * @package sw-gallery
 */

use SWGallery\Core\Application\Render\EntryPoint;
use SWGallery\Core\Application\Render\RenderContext;
use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Gallery\GallerySettings;
use SWGallery\Core\Domain\Gallery\SortMode;
use SWGallery\Core\Domain\Layout\LayoutRenderRequest;
use SWGallery\Core\Domain\Layout\Magazine\RowTemplates;
use SWGallery\Core\Domain\Layout\RenderItem;
use SWGallery\Core\Domain\Layout\ResolvedImage;
use SWGallery\Core\Domain\Settings\SettingsSchemaValidator;
use SWGallery\Tests\Support\RenderFixtures;
use SWGallery\WordPress\Infrastructure\Services;
use SWGallery\WordPress\Rendering\Layout\MagazineLayout;

class RenderMagazineLayoutTest extends SwgTestCase {

	use RenderFixtures;

	private int $author;

	public function set_up(): void {
		parent::set_up();
		$this->purgeGalleries();
		Services::reset();
		$this->author = $this->makeUser( 'author' );
		$this->lockPassword();
		wp_cache_flush();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->lockPassword();
		$this->purgeGalleries();
		Services::reset();
		parent::tear_down();
	}

	/**
	 * @param list<RenderItem>     $items    Items.
	 * @param array<string, mixed> $values   Layout settings.
	 * @param int                  $revision Gallery revision.
	 */
	private function layout_html( array $items, array $values = array(), int $revision = 3 ): string {
		return ( new MagazineLayout() )->render( new LayoutRenderRequest( 12, 'swg-12-1', $items, new GallerySettings( 'magazine', SortMode::Manual, $values ), $revision ) );
	}

	/**
	 * @param array<string, mixed> $settings Item settings.
	 */
	private function item( int $id = 1, array $settings = array(), int $width = 300, int $height = 200 ): RenderItem {
		return new RenderItem( $id, 100 + $id, 'Title', 'Cap <em>x</em>', 'A tree', new ResolvedImage( 'https://example.test/t' . $id . '.jpg', $width, $height, 'https://example.test/t.jpg 300w', '33vw' ), null, \SWGallery\Core\Domain\Layout\LoadingStrategy::Lazy, $id, 7, $settings );
	}

	/** @return list<RenderItem> */
	private function seven(): array {
		return array(
			$this->item( 1, array( 'focal' => array( 'x' => 25, 'y' => 75 ) ), 1600, 1200 ),
			$this->item( 2, array(), 1200, 1600 ),
			$this->item( 3, array(), 2000, 1000 ),
			$this->item( 4, array(), 1600, 1200 ),
			$this->item( 5, array(), 900, 900 ),
			$this->item( 6 ),
			$this->item( 7, array(), 1500, 1000 ),
		);
	}

	private function dom( string $html ): DOMXPath {
		$document = new DOMDocument();
		$document->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>', LIBXML_NOERROR );
		return new DOMXPath( $document );
	}

	private function container_style( string $html ): string {
		return $this->dom( $html )->query( '//div[@data-swg-gallery]' )->item( 0 )->getAttribute( 'style' );
	}

	public function test_it_emits_nested_lists_and_the_lightbox_markup_contract_in_stored_order(): void {
		$xp = $this->dom( $this->layout_html( $this->seven() ) );

		$this->assertSame( 1, $xp->query( '//div[@class="swg-gallery swg-layout-magazine"][@data-swg-gallery][@data-swg-instance="swg-12-1"][@data-swg-labels]' )->length );
		$this->assertGreaterThan( 0, $xp->query( '//ul[@class="swg-items"][@role="list"]/li[starts-with(@class,"swg-row swg-tpl-")]/ul[@class="swg-row-items"][@role="list"]/li[@class="swg-item"]/a[@class="swg-link"]' )->length );
		$links = $xp->query( '//a[@class="swg-link"]' );
		$this->assertSame( 7, $links->length, 'Every picture exactly once.' );
		for ( $i = 0; $i < 7; $i++ ) {
			$this->assertSame( 'https://example.test/t' . ( $i + 1 ) . '.jpg', $links->item( $i )->getAttribute( 'href' ), 'Stored order.' );
		}
		$this->assertSame( 0, $xp->query( '//li[@class="swg-item"][not(parent::ul[@class="swg-row-items"])]' )->length );
		$this->assertSame( '1200', $xp->query( '//a/img' )->item( 1 )->getAttribute( 'width' ) );
		$this->assertSame( 7, $xp->query( '//p[@class="swg-caption"]/em' )->length );
		$this->assertSame( '', $this->layout_html( array() ) );
	}

	public function test_rows_name_known_templates_and_slots_in_order(): void {
		$xp  = $this->dom( $this->layout_html( $this->seven() ) );
		$row = 0;
		foreach ( $xp->query( '//li[starts-with(@class,"swg-row ")]' ) as $li ) {
			$this->assertSame( '--swg-row:' . $row, $li->getAttribute( 'style' ) );
			$id = substr( $li->getAttribute( 'class' ), strlen( 'swg-row swg-tpl-' ) );
			$this->assertTrue( RowTemplates::exists( $id ), $id );
			$slots = array();
			foreach ( $xp->query( './/li[@class="swg-item"]', $li ) as $item ) {
				$this->assertSame( 1, preg_match( '/^--swg-slot:([a-e])(;--swg-fx:[\d.]+%;--swg-fy:[\d.]+%)?$/', $item->getAttribute( 'style' ), $m ) );
				$slots[] = $m[1];
			}
			$this->assertSame( RowTemplates::get( $id )->slots, $slots );
			++$row;
		}
		$this->assertGreaterThan( 1, $row );
	}

	public function test_only_validated_values_reach_class_and_style(): void {
		$html = $this->layout_html(
			$this->seven(),
			array(
				'gap'       => '5px;background:red',
				'radius'    => 'x" onclick="y',
				'rhythm'    => 'bold" onload="z',
				'rowHeight' => '300;color:red',
			)
		);
		$this->assertSame( '--swg-mag-h:320;--swg-gap:8px;--swg-thumb-radius:2px', $this->container_style( $html ) );
		$this->assertStringNotContainsString( 'onclick', $html );
		$this->assertStringNotContainsString( 'onload', $html );
		$this->assertStringNotContainsString( 'background:red', $html );
		$this->assertSame( 1, preg_match_all( '/class="swg-row swg-tpl-[a-z-]+" style="--swg-row:\d+"/', $html ) > 0 ? 1 : 0 );
	}

	public function test_numbers_are_clamped_and_responsive_row_heights_are_written(): void {
		$this->assertSame( '--swg-mag-h:640;--swg-gap:64px;--swg-thumb-radius:0px', $this->container_style( $this->layout_html( array( $this->item() ), array( 'gap' => 99, 'radius' => -4, 'rowHeight' => 9999 ) ) ) );
		$this->assertSame( '--swg-mag-h:160;--swg-gap:0px;--swg-thumb-radius:12px', $this->container_style( $this->layout_html( array( $this->item() ), array( 'gap' => 0, 'radius' => 12, 'rowHeight' => 1 ) ) ) );
		$this->assertSame(
			'--swg-mag-h:300;--swg-mag-h-md:400;--swg-mag-h-xl:640;--swg-gap:8px;--swg-thumb-radius:2px',
			$this->container_style( $this->layout_html( array( $this->item() ), array( 'rowHeight' => array( 'base' => 300, 'md' => 400, 'xl' => 700, 'bad' => 5 ) ) ) )
		);
	}

	public function test_the_focal_point_is_emitted_only_off_centre_and_clamped(): void {
		$xp     = $this->dom(
			$this->layout_html(
				array(
					$this->item( 1, array( 'focal' => array( 'x' => 25.5, 'y' => 75 ) ) ),
					$this->item( 2, array( 'focal' => array( 'x' => 50, 'y' => 50 ) ) ),
					$this->item( 3, array( 'focal' => array( 'x' => 900, 'y' => -3 ) ) ),
					$this->item( 4, array( 'focal' => array( 'x' => 'left;x', 'y' => null ) ) ),
					$this->item( 5, array( 'focal' => 'evil' ) ),
				)
			)
		);
		$styles = array();
		foreach ( $xp->query( '//li[@class="swg-item"]' ) as $li ) {
			$styles[] = $li->getAttribute( 'style' );
		}
		$this->assertStringEndsWith( '--swg-fx:25.5%;--swg-fy:75%', $styles[0] );
		$this->assertStringNotContainsString( '--swg-fx', $styles[1] );
		$this->assertStringEndsWith( '--swg-fx:100%;--swg-fy:0%', $styles[2] );
		$this->assertStringNotContainsString( '--swg-fx', $styles[3] );
		$this->assertStringNotContainsString( '--swg-fx', $styles[4] );
	}

	public function test_the_rhythm_changes_the_rows_and_the_plan_is_deterministic(): void {
		$items = array();
		for ( $i = 1; $i <= 24; $i++ ) {
			$items[] = $this->item( $i, array(), 1000 + 61 * $i, 700 + 17 * ( $i % 5 ) * 20 );
		}
		$balanced = $this->layout_html( $items );
		$this->assertSame( $balanced, $this->layout_html( $items ) );
		$this->assertNotSame( $balanced, $this->layout_html( $items, array( 'rhythm' => 'bold' ) ) );
		$this->assertNotSame( $this->layout_html( $items, array( 'rhythm' => 'calm' ) ), $this->layout_html( $items, array( 'rhythm' => 'bold' ) ) );
		$same = array();
		for ( $i = 1; $i <= 24; $i++ ) {
			$same[] = $this->item( $i, array(), 1500, 1000 );
		}
		$base    = $this->layout_html( $same, array(), 1 );
		$changed = false;
		foreach ( range( 2, 14 ) as $revision ) {
			$changed = $changed || $this->layout_html( $same, array(), $revision ) !== $base;
		}
		$this->assertTrue( $changed, 'The revision is part of the seed.' );
	}

	public function test_hostile_text_and_urls_are_escaped(): void {
		$item = new RenderItem( 1, 5, '"><script>alert(1)</script>', '<img src=x onerror=alert(1)><script>alert(2)</script>', '"><script>alert(4)</script>', new ResolvedImage( 'https://example.test/t.jpg?a=1&b="2"', 10, 10 ), new ResolvedImage( 'javascript:alert(1)', 10, 10 ) );
		$html = $this->layout_html( array( $item ) );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'onerror', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
	}

	public function test_the_schema_validation_matrix(): void {
		$schema    = ( new MagazineLayout() )->getSettingsSchema();
		$validator = new SettingsSchemaValidator();

		$this->assertTrue( $validator->validate( $schema, array( 'gap' => 0, 'radius' => 64, 'rhythm' => 'calm', 'rowHeight' => 160 ) )->isValid() );
		$this->assertTrue( $validator->validate( $schema, array( 'rowHeight' => array( 'base' => 200, 'lg' => 640 ) ) )->isValid() );
		$this->assertTrue( $validator->validate( $schema, array() )->isValid() );
		$bad_cases = array( array( 'gap' => 65 ), array( 'gap' => -1 ), array( 'radius' => 65 ), array( 'rhythm' => 'loud' ), array( 'rhythm' => 1 ), array( 'rowHeight' => 159 ), array( 'rowHeight' => 641 ), array( 'gap' => '6' ) );
		foreach ( $bad_cases as $bad ) {
			$this->assertFalse( $validator->validate( $schema, $bad )->isValid(), (string) wp_json_encode( $bad ) );
		}
		$result = $validator->validate( $schema, array() );
		$this->assertSame( 320, $result->values['rowHeight'] );
		$this->assertSame( 8, $result->values['gap'] );
		$this->assertSame( 2, $result->values['radius'] );
		$this->assertSame( 'balanced', $result->values['rhythm'] );

		$item = $schema['x-item-settings'];
		$this->assertTrue( $validator->validate( $item, array( 'focal' => array( 'x' => 0, 'y' => 100 ) ) )->isValid() );
		foreach ( array( array( 'focal' => array( 'x' => 101 ) ), array( 'focal' => array( 'y' => -1 ) ), array( 'focal' => array( 'x' => 'a' ) ), array( 'focal' => 5 ) ) as $bad ) {
			$this->assertFalse( $validator->validate( $item, $bad )->isValid(), (string) wp_json_encode( $bad ) );
		}
	}

	public function test_image_hints_describe_the_largest_tile(): void {
		$layout = new MagazineLayout();
		$hints  = $layout->imageHints( new GallerySettings( 'magazine', SortMode::Manual, array() ) );
		$this->assertSame( 1200, $hints->displayWidth );
		$this->assertSame( '(min-width: 1200px) 1200px, 100vw', $hints->sizes );
		$this->assertSame( 4, $hints->firstRowCount );
		$this->assertSame( 'magazine', $layout->getId() );
		$this->assertSame( 'Magazine', $layout->getLabel() );
	}

	public function test_snapshot_with_fixed_ids(): void {
		$actual = rtrim( (string) preg_replace( '/>\s*</', ">\n<", $this->layout_html( $this->seven(), array( 'gap' => 8, 'radius' => 4 ) ) ) ) . "\n";
		$file   = __DIR__ . '/../fixtures/render-snapshots/magazine.html';
		if ( '' !== (string) getenv( 'UPDATE_SNAPSHOTS' ) ) {
			if ( false !== getenv( 'CI' ) && '' !== (string) getenv( 'CI' ) ) {
				$this->fail( 'UPDATE_SNAPSHOTS is refused in CI.' );
			}
			file_put_contents( $file, $actual );
			$this->addToAssertionCount( 1 );
			return;
		}
		$this->assertFileExists( $file );
		$this->assertSame( (string) file_get_contents( $file ), $actual, 'Magazine snapshot changed (intended? UPDATE_SNAPSHOTS=1).' );
	}

	/**
	 * @param array<string, mixed> $settings Layout settings.
	 */
	private function gallery( int $count, array $settings = array() ): int {
		$gallery = $this->makeGalleryPost( $this->author, 'publish', '', 'magazine', 'manual', $settings );
		$ids     = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$ids[] = $this->makeImage( "Image $i", 800 + 100 * ( $i % 4 ), 600 + 150 * ( $i % 3 ), array(), null, "Alt $i" );
		}
		$this->addItems( $gallery, $ids );
		return $gallery;
	}

	/**
	 * @param array<string, mixed> $options Render options.
	 */
	private function render( int $gallery, array $options = array() ): \SWGallery\Core\Application\Render\RenderResult {
		wp_set_current_user( 0 );
		Services::reset();
		return Services::gallery_renderer()->render( $gallery, EntryPoint::Shortcode, $options );
	}

	public function test_the_pipeline_renders_every_item_once_in_stored_order_and_is_stable(): void {
		$gallery = $this->gallery( 11 );
		$result  = $this->render( $gallery );
		$xp      = $this->dom( $result->html );

		$this->assertSame( 'magazine', $result->layoutId );
		$this->assertSame( array( \SWGallery\Core\Application\Render\RenderAsset::Lightbox ), $result->assets );
		$this->assertSame( 11, $xp->query( '//a[@class="swg-link"]' )->length );
		$alts = array();
		foreach ( $xp->query( '//a[@class="swg-link"]/img' ) as $img ) {
			$alts[] = $img->getAttribute( 'alt' );
		}
		$this->assertSame( array_map( static fn ( int $i ): string => "Alt $i", range( 1, 11 ) ), $alts );
		wp_cache_flush();
		$this->assertSame( $result->html, $this->render( $gallery )->html, 'Same gallery and revision give the identical rows.' );
	}

	public function test_the_cache_key_follows_layout_and_settings(): void {
		$gallery = $this->gallery( 8 );
		$key     = function ( ?string $layout, array $overrides = array() ) use ( $gallery ): string {
			Services::reset();
			return Services::render_cache()->key( new RenderContext( $gallery, EntryPoint::Shortcode, new Actor( 0 ), 'swg-1-1', 1, 24, $layout, $overrides ), 1 );
		};
		$this->assertSame( $key( null ), $key( null ) );
		$this->assertNotSame( $key( null ), $key( 'grid' ) );
		$this->assertNotSame( $key( null ), $key( null, array( 'gap' => 20 ) ) );
		$this->assertNotSame( $key( null, array( 'rhythm' => 'calm' ) ), $key( null, array( 'rhythm' => 'bold' ) ) );
		$this->assertNotSame( $key( null, array( 'rowHeight' => 200 ) ), $key( null, array( 'rowHeight' => 300 ) ) );
	}

	public function test_each_page_gets_its_own_rows(): void {
		$gallery = $this->gallery( 9 );
		$one     = $this->render( $gallery, array( 'per_page' => 4, 'page' => 1 ) );
		$three   = $this->render( $gallery, array( 'per_page' => 4, 'page' => 3 ) );

		$this->assertSame( 4, $this->dom( $one->html )->query( '//a[@class="swg-link"]' )->length );
		$this->assertSame( 1, $this->dom( $three->html )->query( '//a[@class="swg-link"]' )->length );
		$this->assertSame( 1, $this->dom( $three->html )->query( '//li[starts-with(@class,"swg-row ")]' )->length );
	}

	public function test_a_layout_override_to_magazine_renders_the_magazine(): void {
		$gallery = $this->makeGalleryPost( $this->author );
		$this->addItems( $gallery, array( $this->makeImage( 'One', 800, 600, array(), null, 'Alt' ), $this->makeImage( 'Two', 600, 800, array(), null, 'Alt' ) ) );
		$result = $this->render( $gallery, array( 'layout' => 'magazine' ) );
		$this->assertStringContainsString( 'swg-layout-magazine', $result->html );
	}
}
