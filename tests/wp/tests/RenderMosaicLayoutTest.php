<?php
/**
 * The mosaic layout (treemap LayoutPlan): markup contract, validated numbers only in `style`, schemas incl. the item
 * settings, image hints, a snapshot with fixed ids, and the pipeline (item settings reach the layout, revision seed,
 * cache keys, one plan per page).
 *
 * Update the snapshot after an intended change: UPDATE_SNAPSHOTS=1 composer test:wp -- --filter RenderMosaicLayoutTest
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
use SWGallery\WordPress\Rendering\Layout\MosaicLayout;

class RenderMosaicLayoutTest extends SwgTestCase {

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
		return ( new MosaicLayout() )->render( new LayoutRenderRequest( 12, 'swg-12-1', $items, new GallerySettings( 'mosaic', SortMode::Manual, $values ), $revision ) );
	}

	/**
	 * @param array<string, mixed> $settings Item settings.
	 */
	private function item( int $id = 1, array $settings = array(), int $width = 300, int $height = 200 ): RenderItem {
		return new RenderItem( $id, 100 + $id, 'Title', 'Cap <em>x</em>', 'A tree', new ResolvedImage( 'https://example.test/t' . $id . '.jpg', $width, $height, 'https://example.test/t.jpg 300w', '33vw' ), null, \SWGallery\Core\Domain\Layout\LoadingStrategy::Lazy, $id, 6, $settings );
	}

	/** @return list<RenderItem> */
	private function six(): array {
		return array(
			$this->item( 1, array( 'emphasis' => 3, 'focal' => array( 'x' => 25, 'y' => 75 ) ), 1600, 1200 ),
			$this->item( 2, array(), 1200, 1600 ),
			$this->item( 3, array( 'emphasis' => 2 ), 2000, 1000 ),
			$this->item( 4, array(), 1600, 1200 ),
			$this->item( 5, array(), 900, 900 ),
			$this->item( 6 ),
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

	/** @return list<string> */
	private function item_styles( string $html ): array {
		$styles = array();
		foreach ( $this->dom( $html )->query( '//li[@class="swg-item"]' ) as $li ) {
			$styles[] = $li->getAttribute( 'style' );
		}
		return $styles;
	}

	public function test_it_emits_the_lightbox_markup_contract_in_stored_order(): void {
		$xp = $this->dom( $this->layout_html( $this->six() ) );

		$this->assertSame( 1, $xp->query( '//div[@class="swg-gallery swg-layout-mosaic"][@data-swg-gallery][@data-swg-instance="swg-12-1"][@data-swg-labels]' )->length );
		$links = $xp->query( '//ul[@class="swg-items"][@role="list"]/li[@class="swg-item"]/a[@class="swg-link"]' );
		$this->assertSame( 6, $links->length );
		for ( $i = 0; $i < 6; $i++ ) {
			$this->assertSame( 'https://example.test/t' . ( $i + 1 ) . '.jpg', $links->item( $i )->getAttribute( 'href' ), 'Stored order.' );
		}
		$this->assertSame( '1200', $xp->query( '//a/img' )->item( 1 )->getAttribute( 'width' ) );
		$this->assertSame( 6, $xp->query( '//p[@class="swg-caption"]/em' )->length, 'Captions stay in the markup (the lightbox reads them), the stylesheet hides them in plan mode.' );
		$this->assertSame( '', $this->layout_html( array() ) );
	}

	public function test_only_validated_numbers_reach_the_style_attributes(): void {
		$html = $this->layout_html(
			$this->six(),
			array(
				'gap'    => '5px;background:red',
				'radius' => 'x" onclick="y',
				'ratio'  => '1:1;color:red',
			)
		);
		$this->assertMatchesRegularExpression( '/^--swg-gap:6px;--swg-thumb-radius:0px;--swg-n-ar:0\.8;--swg-m-ar:1;--swg-w-ar:1\.6$/', $this->container_style( $html ) );
		foreach ( $this->item_styles( $html ) as $style ) {
			$this->assertMatchesRegularExpression( '/^(--swg-[nmw]-[xywh]:\d{1,3}(\.\d{1,3})?;){11}--swg-w-h:\d{1,3}(\.\d{1,3})?(;--swg-fx:\d{1,3}(\.\d{1,3})?%;--swg-fy:\d{1,3}(\.\d{1,3})?%)?$/', $style );
		}
		$this->assertStringNotContainsString( 'onclick', $html );
		$this->assertStringNotContainsString( 'background:red', $html );
	}

	public function test_gap_radius_and_ratio_are_clamped_and_applied(): void {
		$style = $this->container_style( $this->layout_html( array( $this->item() ), array( 'gap' => 99, 'radius' => -4, 'ratio' => '16:9' ) ) );
		$this->assertStringStartsWith( '--swg-gap:64px;--swg-thumb-radius:0px;--swg-n-ar:0.8;--swg-m-ar:1;--swg-w-ar:1.778', $style );
		$style = $this->container_style( $this->layout_html( array( $this->item() ), array( 'gap' => 0, 'radius' => 12, 'ratio' => '1:1' ) ) );
		$this->assertSame( '--swg-gap:0px;--swg-thumb-radius:12px;--swg-n-ar:0.8;--swg-m-ar:1;--swg-w-ar:1', $style );
	}

	public function test_focal_point_is_emitted_only_off_centre_and_clamped(): void {
		$styles = $this->item_styles(
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
		$this->assertStringEndsWith( '--swg-fx:25.5%;--swg-fy:75%', $styles[0] );
		$this->assertStringNotContainsString( '--swg-fx', $styles[1] );
		$this->assertStringEndsWith( '--swg-fx:100%;--swg-fy:0%', $styles[2] );
		$this->assertStringNotContainsString( '--swg-fx', $styles[3] );
		$this->assertStringNotContainsString( '--swg-fx', $styles[4] );
	}

	public function test_emphasis_gives_larger_tiles_and_bad_values_fall_back_to_normal(): void {
		$styles = $this->item_styles( $this->layout_html( array( $this->item( 1, array( 'emphasis' => 3 ), 1000, 1000 ), $this->item( 2, array( 'emphasis' => 'big' ), 1000, 1000 ), $this->item( 3, array( 'emphasis' => 1 ), 1000, 1000 ), $this->item( 4, array( 'emphasis' => 99 ), 1000, 1000 ) ) ) );
		$area   = static function ( string $style ): float {
			preg_match( '/--swg-m-w:([\d.]+);--swg-m-h:([\d.]+)/', $style, $m );
			return (float) $m[1] * (float) $m[2];
		};
		$this->assertEqualsWithDelta( 3.0, $area( $styles[0] ) / $area( $styles[2] ), 0.05 );
		$this->assertEqualsWithDelta( 1.0, $area( $styles[1] ) / $area( $styles[2] ), 0.05, 'A non-integer emphasis counts as normal.' );
		$this->assertEqualsWithDelta( 3.0, $area( $styles[3] ) / $area( $styles[2] ), 0.05, 'Out of range clamps to 3.' );
	}

	public function test_the_plan_is_deterministic_and_follows_gallery_and_revision(): void {
		$a = $this->layout_html( $this->six() );
		$this->assertSame( $a, $this->layout_html( $this->six() ) );
		$many = array();
		for ( $i = 1; $i <= 30; $i++ ) {
			$many[] = $this->item( $i, array( 'emphasis' => 1 + $i % 3 ), 900 + 37 * $i, 700 + 11 * $i );
		}
		$base    = $this->layout_html( $many, array(), 1 );
		$changed = false;
		foreach ( range( 2, 12 ) as $revision ) {
			$changed = $changed || $this->layout_html( $many, array(), $revision ) !== $base;
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

	public function test_the_schema_validation_matrix_including_item_settings(): void {
		$schema    = ( new MosaicLayout() )->getSettingsSchema();
		$validator = new SettingsSchemaValidator();

		$this->assertTrue( $validator->validate( $schema, array( 'gap' => 0, 'radius' => 64, 'ratio' => '16:9' ) )->isValid() );
		$this->assertTrue( $validator->validate( $schema, array() )->isValid() );
		foreach ( array( array( 'gap' => 65 ), array( 'gap' => -1 ), array( 'radius' => 65 ), array( 'ratio' => '2:1' ), array( 'ratio' => 1 ), array( 'gap' => '6' ) ) as $bad ) {
			$this->assertFalse( $validator->validate( $schema, $bad )->isValid(), (string) wp_json_encode( $bad ) );
		}
		$result = $validator->validate( $schema, array() );
		$this->assertSame( array( 'gap' => 6, 'radius' => 0, 'ratio' => 'auto' ), $result->values );

		$item = $schema['x-item-settings'];
		$this->assertTrue( $validator->validate( $item, array( 'emphasis' => 3, 'focal' => array( 'x' => 0, 'y' => 100 ) ) )->isValid() );
		$this->assertTrue( $validator->validate( $item, array( 'focal' => array( 'x' => 33.3 ) ) )->isValid() );
		$defaults = $validator->validate( $item, array() )->values;
		$this->assertSame( 1, $defaults['emphasis'] );
		$this->assertSame( array( 'x' => 50, 'y' => 50 ), $defaults['focal'] );
		foreach ( array( array( 'emphasis' => 0 ), array( 'emphasis' => 4 ), array( 'emphasis' => '2' ), array( 'emphasis' => 1.5 ), array( 'focal' => array( 'x' => 101 ) ), array( 'focal' => array( 'y' => -1 ) ), array( 'focal' => array( 'x' => 'a' ) ), array( 'focal' => 5 ) ) as $bad ) {
			$this->assertFalse( $validator->validate( $item, $bad )->isValid(), (string) wp_json_encode( $bad ) );
		}
	}

	public function test_image_hints_describe_the_largest_realistic_tile(): void {
		$layout = new MosaicLayout();
		$hints  = $layout->imageHints( new GallerySettings( 'mosaic', SortMode::Manual, array() ) );
		$this->assertSame( 800, $hints->displayWidth );
		$this->assertSame( '(max-width: 479px) 50vw, (max-width: 1066px) 75vw, 800px', $hints->sizes );
		$this->assertSame( 3, $hints->firstRowCount );
		$this->assertSame( 'mosaic', $layout->getId() );
		$this->assertSame( 'Mosaic', $layout->getLabel() );
	}

	public function test_snapshot_with_fixed_ids(): void {
		$actual = rtrim( (string) preg_replace( '/>\s*</', ">\n<", $this->layout_html( $this->six(), array( 'gap' => 8, 'radius' => 4 ) ) ) ) . "\n";
		$file   = __DIR__ . '/../fixtures/render-snapshots/mosaic.html';
		if ( '' !== (string) getenv( 'UPDATE_SNAPSHOTS' ) ) {
			if ( false !== getenv( 'CI' ) && '' !== (string) getenv( 'CI' ) ) {
				$this->fail( 'UPDATE_SNAPSHOTS is refused in CI.' );
			}
			file_put_contents( $file, $actual );
			$this->addToAssertionCount( 1 );
			return;
		}
		$this->assertFileExists( $file );
		$this->assertSame( (string) file_get_contents( $file ), $actual, 'Mosaic snapshot changed (intended? UPDATE_SNAPSHOTS=1).' );
	}

	/**
	 * @param array<string, array<string, mixed>> $item_settings Item settings by 1-based position.
	 */
	private function gallery( int $count, array $item_settings = array(), array $settings = array() ): int {
		$gallery = $this->makeGalleryPost( $this->author, 'publish', '', 'mosaic', 'manual', $settings );
		$ids     = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$ids[] = $this->makeImage( "Image $i", 800 + 100 * ( $i % 4 ), 600 + 150 * ( $i % 3 ), array(), null, "Alt $i" );
		}
		$items = $this->addItems( $gallery, $ids );
		foreach ( $item_settings as $position => $values ) {
			Services::items()->saveItemSettings( $gallery, $items[ $position - 1 ], $values );
		}
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

	public function test_stored_item_settings_reach_the_plan_through_the_pipeline(): void {
		$gallery = $this->gallery( 6, array( 2 => array( 'emphasis' => 3, 'focal' => array( 'x' => 10, 'y' => 90 ) ) ) );
		$result  = $this->render( $gallery );
		$styles  = $this->item_styles( $result->html );

		$this->assertCount( 6, $styles );
		$this->assertStringEndsWith( '--swg-fx:10%;--swg-fy:90%', $styles[1] );
		$this->assertStringNotContainsString( '--swg-fx', $styles[0] );
		$this->assertSame( 'mosaic', $result->layoutId );
		$this->assertSame( array( \SWGallery\Core\Application\Render\RenderAsset::Lightbox ), $result->assets );
	}

	public function test_the_output_is_stable_across_requests_and_the_cache_key_follows_layout_and_settings(): void {
		$gallery = $this->gallery( 8 );
		$first   = $this->render( $gallery )->html;
		wp_cache_flush();
		$this->assertSame( $first, $this->render( $gallery )->html, 'Same gallery and revision give the identical plan.' );

		$key = function ( ?string $layout, array $overrides = array() ) use ( $gallery ): string {
			Services::reset();
			return Services::render_cache()->key( new RenderContext( $gallery, EntryPoint::Shortcode, new Actor( 0 ), 'swg-1-1', 1, 24, $layout, $overrides ), 1 );
		};
		$this->assertSame( $key( null ), $key( null ) );
		$this->assertNotSame( $key( null ), $key( 'grid' ) );
		$this->assertNotSame( $key( null ), $key( null, array( 'gap' => 20 ) ) );
		$this->assertNotSame( $key( null, array( 'ratio' => '1:1' ) ), $key( null, array( 'ratio' => '4:3' ) ) );
	}

	public function test_an_item_settings_change_bumps_the_revision_and_the_output(): void {
		$gallery = $this->gallery( 6 );
		$before  = $this->render( $gallery )->html;
		$items   = Services::items()->orderedIds( $gallery );
		$actor   = new Actor( $this->author );
		Services::item_edit_service()->setSettings( $actor, $gallery, $items[0], array( 'emphasis' => 3 ), Services::revisions()->current( $gallery ) );
		$after = $this->render( $gallery )->html;
		$this->assertNotSame( $before, $after );
	}

	public function test_each_page_gets_its_own_plan(): void {
		$gallery = $this->gallery( 9 );
		$one     = $this->render( $gallery, array( 'per_page' => 4, 'page' => 1 ) );
		$three   = $this->render( $gallery, array( 'per_page' => 4, 'page' => 3 ) );

		$this->assertCount( 4, $this->item_styles( $one->html ) );
		$this->assertCount( 1, $this->item_styles( $three->html ) );
		$this->assertStringContainsString( '--swg-m-w:100;--swg-m-h:100', $this->item_styles( $three->html )[0], 'A single picture fills its page plan.' );
	}

	public function test_a_layout_override_to_mosaic_renders_the_mosaic(): void {
		$gallery = $this->makeGalleryPost( $this->author );
		$this->addItems( $gallery, array( $this->makeImage( 'One', 800, 600, array(), null, 'Alt' ), $this->makeImage( 'Two', 600, 800, array(), null, 'Alt' ) ) );
		$result = $this->render( $gallery, array( 'layout' => 'mosaic' ) );
		$this->assertStringContainsString( 'swg-layout-mosaic', $result->html );
	}
}
