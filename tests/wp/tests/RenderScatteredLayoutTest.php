<?php
/**
 * The scattered layout (seeded photo prints): markup contract, validated numbers only in `style`, schema, image hints,
 * a snapshot with fixed ids, determinism, the `variation` setting and the pipeline (cache keys, REST listing).
 *
 * Update the snapshot after an intended change: UPDATE_SNAPSHOTS=1 composer test:wp -- --filter RenderScatteredLayoutTest
 *
 * @package sw-gallery
 */

use SWGallery\Core\Application\Render\EntryPoint;
use SWGallery\Core\Application\Render\RenderContext;
use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Gallery\GallerySettings;
use SWGallery\Core\Domain\Gallery\SortMode;
use SWGallery\Core\Domain\Layout\LayoutRenderRequest;
use SWGallery\Core\Domain\Layout\RenderItem;
use SWGallery\Core\Domain\Layout\ResolvedImage;
use SWGallery\Core\Domain\Settings\SettingsSchemaValidator;
use SWGallery\Tests\Support\RenderFixtures;
use SWGallery\WordPress\Infrastructure\Services;
use SWGallery\WordPress\Rendering\Layout\ScatteredLayout;
use SWGallery\WordPress\Rendering\View\CssNumber;

class RenderScatteredLayoutTest extends SwgTestCase {

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
		return ( new ScatteredLayout() )->render( new LayoutRenderRequest( 12, 'swg-12-1', $items, new GallerySettings( 'scattered', SortMode::Manual, $values ), $revision ) );
	}

	private function item( int $id = 1, int $width = 300, int $height = 200 ): RenderItem {
		return new RenderItem( $id, 100 + $id, 'Title', 'Cap <em>x</em>', 'A tree', new ResolvedImage( 'https://example.test/t' . $id . '.jpg', $width, $height, 'https://example.test/t.jpg 300w', '33vw' ), null, \SWGallery\Core\Domain\Layout\LoadingStrategy::Lazy, $id, 9 );
	}

	/** @return list<RenderItem> */
	private function items( int $count = 9 ): array {
		$items = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$items[] = $this->item( $i, 900 + 37 * $i, 700 + 11 * $i );
		}
		return $items;
	}

	private function dom( string $html ): DOMXPath {
		$document = new DOMDocument();
		$document->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>', LIBXML_NOERROR );
		return new DOMXPath( $document );
	}

	private function container_style( string $html ): string {
		return $this->dom( $html )->query( '//div[@data-swg-gallery]' )->item( 0 )->getAttribute( 'style' );
	}

	/** @return list<string> */
	private function item_styles( string $html ): array {
		$styles = array();
		foreach ( $this->dom( $html )->query( '//li[@class="swg-item"]' ) as $li ) {
			$styles[] = $li->getAttribute( 'style' );
		}
		return $styles;
	}

	public function test_it_emits_the_lightbox_markup_contract_in_stored_order(): void {
		$xp = $this->dom( $this->layout_html( $this->items() ) );

		$this->assertSame( 1, $xp->query( '//div[@class="swg-gallery swg-layout-scattered"][@data-swg-gallery][@data-swg-instance="swg-12-1"][@data-swg-labels]' )->length );
		$links = $xp->query( '//ul[@class="swg-items"][@role="list"]/li[@class="swg-item"]/a[@class="swg-link"]' );
		$this->assertSame( 9, $links->length );
		for ( $i = 0; $i < 9; $i++ ) {
			$this->assertSame( 'https://example.test/t' . ( $i + 1 ) . '.jpg', $links->item( $i )->getAttribute( 'href' ), 'Stored order.' );
		}
		$this->assertSame( 9, $xp->query( '//p[@class="swg-caption"]/em' )->length, 'Captions stay in the markup for the lightbox.' );
		$this->assertSame( '', $this->layout_html( array() ) );
	}

	public function test_only_validated_numbers_reach_the_style_attributes(): void {
		$html = $this->layout_html(
			$this->items(),
			array(
				'columns'   => array(
					'base' => '3;background:red',
					'md'   => 4,
					'xl'   => 99,
				),
				'ratio'     => '1:1;color:red',
				'overlap'   => '5px;x:y',
				'tilt'      => 'x" onclick="y',
				'border'    => '9px;left:0',
				'variation' => '1;2',
			)
		);
		$this->assertMatchesRegularExpression(
			'/^--swg-columns:3;--swg-columns-md:4;--swg-columns-xl:8;--swg-overlap:12;--swg-border:10px;--swg-cell-ar:1\.5;--swg-bleed-x:\d(\.\d{1,3})?;--swg-bleed-y:\d(\.\d{1,3})?$/',
			$this->container_style( $html )
		);
		foreach ( $this->item_styles( $html ) as $style ) {
			$this->assertMatchesRegularExpression( '/^--swg-rot:-?\d{1,2}(\.\d{1,2})?deg;--swg-dx:-?\d{1,2}(\.\d{1,2})?%;--swg-dy:-?\d{1,2}(\.\d{1,2})?%;--swg-z:\d{1,3}$/', $style );
		}
		$this->assertStringNotContainsString( 'onclick', $html );
		$this->assertStringNotContainsString( 'background:red', $html );
	}

	public function test_settings_are_clamped_and_applied(): void {
		$style = $this->container_style( $this->layout_html( array( $this->item() ), array( 'columns' => 1, 'overlap' => 99, 'border' => 99, 'ratio' => '2:3' ) ) );
		$this->assertStringStartsWith( '--swg-columns:2;--swg-overlap:', $style );
		$this->assertStringContainsString( ';--swg-border:24px;--swg-cell-ar:0.6667;', $style );
		$style = $this->container_style( $this->layout_html( array( $this->item() ), array( 'columns' => array( 'base' => 5, 'sm' => 2 ), 'overlap' => 0, 'border' => 0, 'ratio' => '4:3' ) ) );
		$this->assertMatchesRegularExpression( '/^--swg-columns:5;--swg-columns-sm:2;--swg-overlap:0;--swg-border:0px;--swg-cell-ar:1\.3333;--swg-bleed-x:/', $style );
	}

	public function test_tilt_bounds_the_rotation_and_zero_tilt_keeps_the_prints_straight(): void {
		foreach ( array( 0, 3, 12 ) as $tilt ) {
			foreach ( $this->item_styles( $this->layout_html( $this->items( 20 ), array( 'tilt' => $tilt ) ) ) as $style ) {
				preg_match( '/--swg-rot:(-?[\d.]+)deg/', $style, $m );
				$this->assertLessThanOrEqual( $tilt, abs( (float) $m[1] ) );
			}
		}
		$this->assertStringContainsString( '--swg-rot:0deg', $this->item_styles( $this->layout_html( $this->items( 3 ), array( 'tilt' => 0 ) ) )[0] );
	}

	public function test_stacking_is_a_permutation_and_dom_order_is_untouched(): void {
		$html = $this->layout_html( $this->items( 14 ) );
		$z    = array();
		foreach ( $this->item_styles( $html ) as $style ) {
			preg_match( '/--swg-z:(\d+)/', $style, $m );
			$z[] = (int) $m[1];
		}
		sort( $z );
		$this->assertSame( range( 1, 14 ), $z );
		$links = $this->dom( $html )->query( '//a[@class="swg-link"]' );
		$this->assertSame( 'https://example.test/t14.jpg', $links->item( 13 )->getAttribute( 'href' ) );
	}

	public function test_the_plan_is_deterministic_and_follows_gallery_revision_and_variation(): void {
		$a = $this->layout_html( $this->items() );
		$this->assertSame( $a, $this->layout_html( $this->items() ) );
		$this->assertNotSame( $a, $this->layout_html( $this->items(), array(), 4 ), 'The revision is part of the seed.' );
		$this->assertNotSame( $a, $this->layout_html( $this->items(), array( 'variation' => 1 ) ), 'The variation changes the arrangement.' );
		$this->assertSame( $this->layout_html( $this->items(), array( 'variation' => 7 ) ), $this->layout_html( $this->items(), array( 'variation' => 7 ) ) );
		$this->assertSame( $a, $this->layout_html( $this->items(), array( 'variation' => 0 ) ) );
	}

	public function test_hostile_text_and_urls_are_escaped(): void {
		$item = new RenderItem( 1, 5, '"><script>alert(1)</script>', '<img src=x onerror=alert(1)><script>alert(2)</script>', '"><script>alert(4)</script>', new ResolvedImage( 'https://example.test/t.jpg?a=1&b="2"', 10, 10 ), new ResolvedImage( 'javascript:alert(1)', 10, 10 ) );
		$html = $this->layout_html( array( $item ) );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'onerror', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
	}

	public function test_the_schema_validation_matrix(): void {
		$layout    = new ScatteredLayout();
		$schema    = $layout->getSettingsSchema();
		$validator = new SettingsSchemaValidator();

		$this->assertTrue( $validator->validate( $schema, array( 'columns' => 8, 'ratio' => '2:3', 'overlap' => 40, 'tilt' => 12, 'border' => 24, 'variation' => 99 ) )->isValid() );
		$this->assertTrue( $validator->validate( $schema, array( 'columns' => array( 'base' => 2, 'lg' => 5 ) ) )->isValid() );
		$this->assertTrue( $validator->validate( $schema, array() )->isValid() );
		$bad = array(
			array( 'columns' => 1 ),
			array( 'columns' => 9 ),
			array( 'ratio' => '16:9' ),
			array( 'ratio' => 3 ),
			array( 'overlap' => 41 ),
			array( 'overlap' => -1 ),
			array( 'tilt' => 13 ),
			array( 'tilt' => 2.5 ),
			array( 'border' => 25 ),
			array( 'variation' => 100 ),
			array( 'variation' => '1' ),
		);
		foreach ( $bad as $values ) {
			$this->assertFalse( $validator->validate( $schema, $values )->isValid(), (string) wp_json_encode( $values ) );
		}
		$this->assertSame(
			array(
				'columns'   => 3,
				'ratio'     => '3:2',
				'overlap'   => 12,
				'tilt'      => 5,
				'border'    => 10,
				'variation' => 0,
			),
			$validator->validate( $schema, array() )->values
		);
		$this->assertArrayNotHasKey( 'x-item-settings', $schema );
		$this->assertSame( 'scattered', $layout->getId() );
		$this->assertSame( 'Scattered', $layout->getLabel() );
	}

	public function test_image_hints_follow_the_columns_and_the_overlap(): void {
		$layout = new ScatteredLayout();
		$hints  = $layout->imageHints( new GallerySettings( 'scattered', SortMode::Manual, array() ) );
		$this->assertSame( 448, $hints->displayWidth );
		$this->assertSame( '(max-width: 479px) calc(100vw * 1.12 / 2), calc(100vw * 1.12 / 3)', $hints->sizes );
		$this->assertSame( 3, $hints->firstRowCount );

		$hints = $layout->imageHints( new GallerySettings( 'scattered', SortMode::Manual, array( 'columns' => array( 'base' => 2, 'md' => 5, 'xl' => 8 ), 'overlap' => 0 ) ) );
		$this->assertSame( 600, $hints->displayWidth );
		$this->assertSame( '(min-width: 1280px) calc(100vw * 1 / 8), (min-width: 768px) calc(100vw * 1 / 5), (max-width: 479px) calc(100vw * 1 / 2), calc(100vw * 1 / 2)', $hints->sizes );
		$this->assertSame( 6, $hints->firstRowCount, 'Capped at the maximum of the hints contract.' );
	}

	public function test_css_number_is_locale_independent_and_trimmed(): void {
		$this->assertSame( '0', CssNumber::format( -0.0001, 2 ) );
		$this->assertSame( '1.5', CssNumber::format( 1.5 ) );
		$this->assertSame( '-2.35', CssNumber::format( -2.349, 2 ) );
		$this->assertSame( '12', CssNumber::format( 12.0, 2 ) );
		$this->assertSame( '100', CssNumber::format( 100.0, 0 ) );
		$this->assertSame( '0', CssNumber::format( NAN ) );
		$this->assertSame( '0', CssNumber::format( INF ) );
	}

	public function test_snapshot_with_fixed_ids(): void {
		$actual = rtrim( (string) preg_replace( '/>\s*</', ">\n<", $this->layout_html( $this->items( 7 ), array( 'tilt' => 6, 'border' => 8 ) ) ) ) . "\n";
		$file   = __DIR__ . '/../fixtures/render-snapshots/scattered.html';
		if ( '' !== (string) getenv( 'UPDATE_SNAPSHOTS' ) ) {
			if ( false !== getenv( 'CI' ) && '' !== (string) getenv( 'CI' ) ) {
				$this->fail( 'UPDATE_SNAPSHOTS is refused in CI.' );
			}
			file_put_contents( $file, $actual );
			$this->addToAssertionCount( 1 );
			return;
		}
		$this->assertFileExists( $file );
		$this->assertSame( (string) file_get_contents( $file ), $actual, 'Scattered snapshot changed (intended? UPDATE_SNAPSHOTS=1).' );
	}

	/**
	 * @param array<string, mixed> $settings Layout settings.
	 */
	private function gallery( int $count, array $settings = array() ): int {
		$gallery = $this->makeGalleryPost( $this->author, 'publish', '', 'scattered', 'manual', $settings );
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

	public function test_the_pipeline_renders_the_layout_with_the_stored_settings_and_is_stable_across_requests(): void {
		$gallery = $this->gallery( 8, array( 'tilt' => 9, 'border' => 14 ) );
		$first   = $this->render( $gallery );
		$this->assertSame( 'scattered', $first->layoutId );
		$this->assertStringContainsString( 'swg-layout-scattered', $first->html );
		$this->assertStringContainsString( '--swg-border:14px', $first->html );
		$this->assertCount( 8, $this->item_styles( $first->html ) );
		$this->assertSame( array( \SWGallery\Core\Application\Render\RenderAsset::Lightbox ), $first->assets );
		wp_cache_flush();
		$this->assertSame( $first->html, $this->render( $gallery )->html, 'Same gallery and revision give the identical arrangement.' );
	}

	public function test_the_cache_key_follows_layout_and_settings(): void {
		$gallery = $this->gallery( 6 );
		$key     = function ( ?string $layout, array $overrides = array() ) use ( $gallery ): string {
			Services::reset();
			return Services::render_cache()->key( new RenderContext( $gallery, EntryPoint::Shortcode, new Actor( 0 ), 'swg-1-1', 1, 24, $layout, $overrides ), 1 );
		};
		$this->assertSame( $key( null ), $key( null ) );
		$this->assertNotSame( $key( null ), $key( 'grid' ) );
		$this->assertNotSame( $key( null, array( 'tilt' => 3 ) ), $key( null, array( 'tilt' => 4 ) ) );
		$this->assertNotSame( $key( null, array( 'variation' => 1 ) ), $key( null, array( 'variation' => 2 ) ) );
	}

	public function test_a_variation_override_changes_the_rendered_arrangement(): void {
		$gallery = $this->gallery( 9 );
		$a       = $this->render( $gallery, array( 'settings' => array( 'variation' => 0 ) ) );
		$b       = $this->render( $gallery, array( 'settings' => array( 'variation' => 5 ) ) );
		$this->assertNotSame( $this->item_styles( $a->html ), $this->item_styles( $b->html ) );
	}

	public function test_a_layout_override_to_scattered_renders_it_and_rest_lists_it(): void {
		$gallery = $this->makeGalleryPost( $this->author );
		$this->addItems( $gallery, array( $this->makeImage( 'One', 800, 600, array(), null, 'Alt' ), $this->makeImage( 'Two', 600, 800, array(), null, 'Alt' ) ) );
		$this->assertStringContainsString( 'swg-layout-scattered', $this->render( $gallery, array( 'layout' => 'scattered' ) )->html );

		global $wp_rest_server;
		$wp_rest_server = null;
		rest_get_server();
		wp_set_current_user( $this->makeUser( 'editor' ) );
		$data = rest_do_request( new WP_REST_Request( 'GET', '/swg/v1/layouts' ) )->get_data();
		$by   = array_column( $data['layouts'], null, 'id' );
		$this->assertSame( 'Scattered', $by['scattered']['label'] );
		$this->assertSame( array( 'columns', 'ratio', 'overlap', 'tilt', 'border', 'variation' ), array_keys( $by['scattered']['settingsSchema']['properties'] ) );
		$this->assertTrue( $by['scattered']['settingsSchema']['properties']['columns']['x-responsive'] );
		$wp_rest_server = null;
	}
}
