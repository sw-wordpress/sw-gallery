<?php
/**
 * The shattered layout (weighted Voronoi shards): markup contract, validated numbers only in `style` (exact polygon
 * format), schemas incl. the item settings, image hints, a snapshot with fixed ids, and the pipeline (item settings
 * reach the layout, revision and variation seed, cache keys, one plan per page).
 *
 * Update the snapshot after an intended change: UPDATE_SNAPSHOTS=1 composer test:wp -- --filter RenderShatteredLayoutTest
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
use SWGallery\WordPress\Rendering\Layout\ShatteredLayout;

class RenderShatteredLayoutTest extends SwgTestCase {

	use RenderFixtures;

	/** One number as the view writes it. */
	private const NUM = '\d{1,3}(?:\.\d{1,3})?';

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
		return ( new ShatteredLayout() )->render( new LayoutRenderRequest( 12, 'swg-12-1', $items, new GallerySettings( 'shattered', SortMode::Manual, $values ), $revision ) );
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

	/** The exact shape of one item's style: three variants of box and polygon, then optionally the focal point. */
	private function style_pattern(): string {
		$n       = self::NUM;
		$polygon = 'polygon\(' . $n . '% ' . $n . '%(?:,' . $n . '% ' . $n . '%){2,}\)';
		$variant = static fn ( string $k ): string => "--swg-$k-x:$n;--swg-$k-y:$n;--swg-$k-w:$n;--swg-$k-h:$n;--swg-$k-clip:$polygon";
		return '/^' . $variant( 'n' ) . ';' . $variant( 'm' ) . ';' . $variant( 'w' ) . "(?:;--swg-fx:$n%;--swg-fy:$n%)?$/";
	}

	public function test_it_emits_the_lightbox_markup_contract_in_stored_order(): void {
		$xp = $this->dom( $this->layout_html( $this->six() ) );

		$this->assertSame( 1, $xp->query( '//div[@class="swg-gallery swg-layout-shattered"][@data-swg-gallery][@data-swg-instance="swg-12-1"][@data-swg-labels]' )->length );
		$links = $xp->query( '//ul[@class="swg-items"][@role="list"]/li[@class="swg-item"]/a[@class="swg-link"]' );
		$this->assertSame( 6, $links->length );
		for ( $i = 0; $i < 6; $i++ ) {
			$this->assertSame( 'https://example.test/t' . ( $i + 1 ) . '.jpg', $links->item( $i )->getAttribute( 'href' ), 'Stored order.' );
		}
		$this->assertSame( 6, $xp->query( '//a[@class="swg-link"]/img' )->length, 'Every link once, with its image.' );
		$this->assertSame( '1200', $xp->query( '//a/img' )->item( 1 )->getAttribute( 'width' ) );
		$this->assertSame( 6, $xp->query( '//p[@class="swg-caption"]/em' )->length, 'Captions stay in the markup (the lightbox reads them), the stylesheet hides them in plan mode.' );
		$this->assertSame( '', $this->layout_html( array() ) );
	}

	public function test_the_style_attributes_have_an_exact_format(): void {
		$html = $this->layout_html( $this->six() );
		$this->assertMatchesRegularExpression( '/^--swg-n-ar:0\.8;--swg-m-ar:1;--swg-w-ar:1\.6$/', $this->container_style( $html ) );
		$styles = $this->item_styles( $html );
		$this->assertCount( 6, $styles );
		foreach ( $styles as $style ) {
			$this->assertMatchesRegularExpression( $this->style_pattern(), $style );
		}
	}

	public function test_hostile_settings_never_reach_the_style_and_polygons_hold_numbers_only(): void {
		$html = $this->layout_html(
			$this->six(),
			array(
				'grout'     => '5px;background:red',
				'variation' => 'x" onclick="y',
				'ratio'     => '1:1;color:red',
			)
		);
		$this->assertSame( $this->layout_html( $this->six() ), $html, 'Unusable values fall back to the defaults.' );
		$this->assertMatchesRegularExpression( '/^--swg-n-ar:0\.8;--swg-m-ar:1;--swg-w-ar:1\.6$/', $this->container_style( $html ) );
		foreach ( $this->item_styles( $html ) as $style ) {
			$this->assertMatchesRegularExpression( $this->style_pattern(), $style );
			$this->assertSame( 0, preg_match( '/[^-a-z0-9.:;%(), ]/', $style ), 'Only plan numbers and fixed punctuation.' );
		}
		$this->assertStringNotContainsString( 'onclick', $html );
		$this->assertStringNotContainsString( 'background:red', $html );
	}

	public function test_hostile_item_settings_cannot_inject_into_the_style(): void {
		$styles = $this->item_styles(
			$this->layout_html(
				array(
					$this->item( 1, array( 'emphasis' => '3;x', 'focal' => array( 'x' => '1%;color:red', 'y' => "50\"><b>" ) ) ),
					$this->item( 2, array( 'focal' => array( 'x' => NAN, 'y' => INF ) ) ),
					$this->item( 3, array( 'emphasis' => array( 3 ), 'focal' => 'evil' ) ),
				)
			)
		);
		foreach ( $styles as $style ) {
			$this->assertMatchesRegularExpression( $this->style_pattern(), $style );
			$this->assertStringNotContainsString( '--swg-fx', $style, 'Unusable focal values mean the centre.' );
		}
	}

	public function test_the_polygon_is_in_tile_percent_and_its_box_lies_inside_the_container(): void {
		foreach ( $this->item_styles( $this->layout_html( $this->six() ) ) as $style ) {
			foreach ( array( 'n', 'm', 'w' ) as $key ) {
				$this->assertSame( 1, preg_match( "/--swg-$key-x:([\d.]+);--swg-$key-y:([\d.]+);--swg-$key-w:([\d.]+);--swg-$key-h:([\d.]+);--swg-$key-clip:polygon\(([^)]+)\)/", $style, $m ) );
				$this->assertLessThanOrEqual( 100.001, (float) $m[1] + (float) $m[3] );
				$this->assertLessThanOrEqual( 100.001, (float) $m[2] + (float) $m[4] );
				$this->assertGreaterThan( 0.0, (float) $m[3] );
				$xs = array();
				$ys = array();
				foreach ( explode( ',', $m[5] ) as $point ) {
					preg_match( '/^([\d.]+)% ([\d.]+)%$/', $point, $p );
					$this->assertLessThanOrEqual( 100.0, (float) $p[1] );
					$this->assertLessThanOrEqual( 100.0, (float) $p[2] );
					$xs[] = (float) $p[1];
					$ys[] = (float) $p[2];
				}
				$this->assertEqualsWithDelta( 0.0, min( $xs ), 0.001, 'The tile is the bounding box of its polygon.' );
				$this->assertEqualsWithDelta( 100.0, max( $xs ), 0.001 );
				$this->assertEqualsWithDelta( 0.0, min( $ys ), 0.001 );
				$this->assertEqualsWithDelta( 100.0, max( $ys ), 0.001 );
			}
		}
	}

	public function test_grout_ratio_and_variation_are_applied(): void {
		$base = $this->layout_html( $this->six() );
		$this->assertNotSame( $base, $this->layout_html( $this->six(), array( 'grout' => 12 ) ) );
		$this->assertNotSame( $base, $this->layout_html( $this->six(), array( 'variation' => 1 ) ) );
		$this->assertSame( $this->layout_html( $this->six(), array( 'grout' => 24 ) ), $this->layout_html( $this->six(), array( 'grout' => 99 ) ), 'Grout is clamped to 24.' );
		$this->assertSame( $this->layout_html( $this->six(), array( 'variation' => 99 ) ), $this->layout_html( $this->six(), array( 'variation' => 500 ) ), 'Variation is clamped to 99.' );
		$this->assertSame( $this->layout_html( $this->six(), array( 'grout' => 0, 'variation' => 0 ) ), $this->layout_html( $this->six(), array( 'grout' => -4, 'variation' => -1 ) ), 'Negative values clamp to 0.' );
		$this->assertMatchesRegularExpression( '/^--swg-n-ar:0\.8;--swg-m-ar:1;--swg-w-ar:1\.778$/', $this->container_style( $this->layout_html( array( $this->item() ), array( 'ratio' => '16:9' ) ) ) );
		$this->assertSame( '--swg-n-ar:0.8;--swg-m-ar:1;--swg-w-ar:1', $this->container_style( $this->layout_html( array( $this->item() ), array( 'ratio' => '1:1' ) ) ) );
		$this->assertSame( '--swg-n-ar:0.8;--swg-m-ar:1;--swg-w-ar:1', $this->container_style( $this->layout_html( array( $this->item() ), array( 'ratio' => '1:1' ) ) ) );
	}

	public function test_focal_point_is_emitted_only_off_centre_and_clamped(): void {
		$styles = $this->item_styles(
			$this->layout_html(
				array(
					$this->item( 1, array( 'focal' => array( 'x' => 25.5, 'y' => 75 ) ) ),
					$this->item( 2, array( 'focal' => array( 'x' => 50, 'y' => 50 ) ) ),
					$this->item( 3, array( 'focal' => array( 'x' => 900, 'y' => -3 ) ) ),
				)
			)
		);
		$this->assertStringEndsWith( '--swg-fx:25.5%;--swg-fy:75%', $styles[0] );
		$this->assertStringNotContainsString( '--swg-fx', $styles[1] );
		$this->assertStringEndsWith( '--swg-fx:100%;--swg-fy:0%', $styles[2] );
	}

	public function test_emphasis_gives_larger_shards(): void {
		$styles = $this->item_styles( $this->layout_html( array( $this->item( 1, array( 'emphasis' => 3 ), 1000, 1000 ), $this->item( 2, array(), 1000, 1000 ), $this->item( 3, array(), 1000, 1000 ), $this->item( 4, array(), 1000, 1000 ) ), array( 'grout' => 0 ) ) );
		$area   = static function ( string $style ): float {
			preg_match( '/--swg-m-w:([\d.]+);--swg-m-h:([\d.]+)/', $style, $m );
			return (float) $m[1] * (float) $m[2];
		};
		$this->assertGreaterThan( $area( $styles[1] ), $area( $styles[0] ), 'The bounding box of the extra large shard is bigger.' );
	}

	public function test_the_plan_is_deterministic_and_follows_gallery_revision_and_variation(): void {
		$this->assertSame( $this->layout_html( $this->six() ), $this->layout_html( $this->six() ) );
		$many = array();
		for ( $i = 1; $i <= 20; $i++ ) {
			$many[] = $this->item( $i, array( 'emphasis' => 1 + $i % 3 ), 900 + 37 * $i, 700 + 11 * $i );
		}
		$base = $this->layout_html( $many, array(), 1 );
		$this->assertNotSame( $base, $this->layout_html( $many, array(), 2 ), 'The revision is part of the seed.' );
		$this->assertNotSame( $base, $this->layout_html( $many, array( 'variation' => 5 ), 1 ), 'The variation re-shapes the shards.' );
		$this->assertNotSame( $this->layout_html( $many, array( 'variation' => 5 ), 1 ), $this->layout_html( $many, array( 'variation' => 6 ), 1 ) );
	}

	public function test_hostile_text_and_urls_are_escaped(): void {
		$item = new RenderItem( 1, 5, '"><script>alert(1)</script>', '<img src=x onerror=alert(1)><script>alert(2)</script>', '"><script>alert(4)</script>', new ResolvedImage( 'https://example.test/t.jpg?a=1&b="2"', 10, 10 ), new ResolvedImage( 'javascript:alert(1)', 10, 10 ) );
		$html = $this->layout_html( array( $item ) );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'onerror', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
	}

	public function test_one_to_sixty_items_always_render(): void {
		foreach ( array( 1, 2, 3, 7, 60 ) as $count ) {
			$items = array();
			for ( $i = 1; $i <= $count; $i++ ) {
				$items[] = $this->item( $i, array( 'emphasis' => 1 + $i % 3 ), 400 + 20 * $i, 300 + 13 * $i );
			}
			$html = $this->layout_html( $items );
			$this->assertSame( $count, $this->dom( $html )->query( '//li[@class="swg-item"]' )->length );
			$this->assertStringNotContainsString( 'NaN', $html );
			$this->assertStringNotContainsString( 'INF', $html );
		}
	}

	public function test_the_schema_validation_matrix_including_item_settings(): void {
		$layout    = new ShatteredLayout();
		$schema    = $layout->getSettingsSchema();
		$validator = new SettingsSchemaValidator();

		$this->assertTrue( $validator->validate( $schema, array( 'grout' => 0, 'variation' => 99, 'ratio' => '16:9' ) )->isValid() );
		$this->assertTrue( $validator->validate( $schema, array( 'grout' => 24, 'variation' => 0 ) )->isValid() );
		$this->assertTrue( $validator->validate( $schema, array() )->isValid() );
		foreach ( array( array( 'grout' => 25 ), array( 'grout' => -1 ), array( 'variation' => 100 ), array( 'variation' => -1 ), array( 'variation' => 1.5 ), array( 'ratio' => '2:1' ), array( 'ratio' => 1 ), array( 'grout' => '3' ) ) as $bad ) {
			$this->assertFalse( $validator->validate( $schema, $bad )->isValid(), (string) wp_json_encode( $bad ) );
		}
		$this->assertSame( array( 'grout' => 3, 'variation' => 0, 'ratio' => 'auto' ), $validator->validate( $schema, array() )->values );
		$this->assertSame( array( 'auto', '1:1', '4:3', '3:2', '16:9' ), $schema['properties']['ratio']['enum'] );
		$this->assertSame( $schema['properties']['ratio']['enum'], array_keys( $schema['properties']['ratio']['x-enum-titles'] ) );

		$item = $schema['x-item-settings'];
		$this->assertEquals( ( new \SWGallery\WordPress\Rendering\Layout\MosaicLayout() )->getSettingsSchema()['x-item-settings'], $item, 'Same item settings as the mosaic, so the editor form works.' );
		$this->assertTrue( $validator->validate( $item, array( 'emphasis' => 3, 'focal' => array( 'x' => 0, 'y' => 100 ) ) )->isValid() );
		foreach ( array( array( 'emphasis' => 0 ), array( 'emphasis' => 4 ), array( 'emphasis' => '2' ), array( 'focal' => array( 'x' => 101 ) ), array( 'focal' => array( 'y' => -1 ) ), array( 'focal' => 5 ) ) as $bad ) {
			$this->assertFalse( $validator->validate( $item, $bad )->isValid(), (string) wp_json_encode( $bad ) );
		}
	}

	public function test_image_hints_describe_a_large_shard(): void {
		$layout = new ShatteredLayout();
		$hints  = $layout->imageHints( new GallerySettings( 'shattered', SortMode::Manual, array() ) );
		$this->assertSame( 640, $hints->displayWidth );
		$this->assertSame( '(max-width: 479px) 50vw, (max-width: 1066px) 60vw, 640px', $hints->sizes );
		$this->assertSame( 3, $hints->firstRowCount );
		$this->assertSame( 'shattered', $layout->getId() );
		$this->assertSame( 'Shattered', $layout->getLabel() );
	}

	public function test_snapshot_with_fixed_ids(): void {
		$actual = rtrim( (string) preg_replace( '/>\s*</', ">\n<", $this->layout_html( $this->six(), array( 'grout' => 4, 'variation' => 2 ) ) ) ) . "\n";
		$file   = __DIR__ . '/../fixtures/render-snapshots/shattered.html';
		if ( '' !== (string) getenv( 'UPDATE_SNAPSHOTS' ) ) {
			if ( false !== getenv( 'CI' ) && '' !== (string) getenv( 'CI' ) ) {
				$this->fail( 'UPDATE_SNAPSHOTS is refused in CI.' );
			}
			file_put_contents( $file, $actual );
			$this->addToAssertionCount( 1 );
			return;
		}
		$this->assertFileExists( $file );
		$this->assertSame( (string) file_get_contents( $file ), $actual, 'Shattered snapshot changed (intended? UPDATE_SNAPSHOTS=1).' );
	}

	/**
	 * @param array<int, array<string, mixed>> $item_settings Item settings by 1-based position.
	 * @param array<string, mixed>             $settings      Layout settings.
	 */
	private function gallery( int $count, array $item_settings = array(), array $settings = array() ): int {
		$gallery = $this->makeGalleryPost( $this->author, 'publish', '', 'shattered', 'manual', $settings );
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
		$this->assertSame( 'shattered', $result->layoutId );
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
		$this->assertNotSame( $key( null ), $key( null, array( 'grout' => 9 ) ) );
		$this->assertNotSame( $key( null, array( 'variation' => 1 ) ), $key( null, array( 'variation' => 2 ) ) );
		$this->assertNotSame( $key( null, array( 'ratio' => '1:1' ) ), $key( null, array( 'ratio' => '4:3' ) ) );
	}

	public function test_variation_through_the_pipeline_changes_the_plan_not_the_pictures(): void {
		$gallery = $this->gallery( 10 );
		$a       = $this->render( $gallery );
		$b       = $this->render( $gallery, array( 'settings' => array( 'variation' => 4 ) ) );
		$this->assertNotSame( $this->item_styles( $a->html ), $this->item_styles( $b->html ) );
		preg_match_all( '/href="([^"]+)"/', $a->html, $one );
		preg_match_all( '/href="([^"]+)"/', $b->html, $two );
		$this->assertSame( $one[1], $two[1], 'The pictures keep their order.' );
	}

	public function test_an_item_settings_change_bumps_the_revision_and_the_output(): void {
		$gallery = $this->gallery( 6 );
		$before  = $this->render( $gallery )->html;
		$items   = Services::items()->orderedIds( $gallery );
		$actor   = new Actor( $this->author );
		Services::item_edit_service()->setSettings( $actor, $gallery, $items[0], array( 'emphasis' => 3 ), Services::revisions()->current( $gallery ) );
		$this->assertNotSame( $before, $this->render( $gallery )->html );
	}

	public function test_each_page_gets_its_own_plan(): void {
		$gallery = $this->gallery( 9 );
		$one     = $this->render( $gallery, array( 'per_page' => 4, 'page' => 1 ) );
		$three   = $this->render( $gallery, array( 'per_page' => 4, 'page' => 3 ) );

		$this->assertCount( 4, $this->item_styles( $one->html ) );
		$this->assertCount( 1, $this->item_styles( $three->html ) );
		$this->assertStringContainsString( '--swg-m-w:100;--swg-m-h:100;--swg-m-clip:polygon(0% 0%,100% 0%,100% 100%,0% 100%)', $this->item_styles( $three->html )[0], 'A single picture fills its page plan.' );
	}

	public function test_a_layout_override_to_shattered_renders_it(): void {
		$gallery = $this->makeGalleryPost( $this->author );
		$this->addItems( $gallery, array( $this->makeImage( 'One', 800, 600, array(), null, 'Alt' ), $this->makeImage( 'Two', 600, 800, array(), null, 'Alt' ) ) );
		$result = $this->render( $gallery, array( 'layout' => 'shattered' ) );
		$this->assertStringContainsString( 'swg-layout-shattered', $result->html );
	}
}
