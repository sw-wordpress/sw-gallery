<?php
/**
 * Justified and masonry through the full pipeline: reported assets, fallback, cache and pagination interplay.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Application\Render\EntryPoint;
use SWGallery\Core\Application\Render\RenderAsset;
use SWGallery\Core\Application\Render\RenderContext;
use SWGallery\Core\Application\Render\RenderResult;
use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Tests\Support\RenderFixtures;
use SWGallery\WordPress\Infrastructure\Services;

class RenderLayoutsPipelineTest extends SwgTestCase {

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

	private function gallery( int $count = 4 ): int {
		$gallery = $this->makeGalleryPost( $this->author );
		$ids     = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$ids[] = $this->makeImage( "Image $i", 400 * $i, 300, array(), null, "Alt $i" );
		}
		$this->addItems( $gallery, $ids );
		return $gallery;
	}

	/**
	 * @param array<string, mixed> $options Render options.
	 */
	private function render( int $gallery, array $options = array() ): RenderResult {
		wp_set_current_user( 0 );
		Services::reset();
		return Services::gallery_renderer()->render( $gallery, EntryPoint::Shortcode, $options );
	}

	/**
	 * @param array<string, mixed> $settings Layout settings override.
	 */
	private function key( int $gallery, string $layout, array $settings = array() ): string {
		Services::reset();
		$context = new RenderContext( $gallery, EntryPoint::Shortcode, new Actor( 0 ), 'swg-1-1', 1, 24, $layout, $settings );
		return Services::render_cache()->key( $context, 1 );
	}

	public function test_only_masonry_galleries_report_the_masonry_asset(): void {
		$gallery = $this->gallery();

		$grid      = $this->render( $gallery, array( 'layout' => 'grid' ) );
		$justified = $this->render( $gallery, array( 'layout' => 'justified' ) );
		$masonry   = $this->render( $gallery, array( 'layout' => 'masonry' ) );

		$this->assertSame( array( RenderAsset::Lightbox ), $grid->assets );
		$this->assertSame( array( RenderAsset::Lightbox ), $justified->assets );
		$this->assertSame( array( RenderAsset::Lightbox, RenderAsset::Masonry ), $masonry->assets );
		$this->assertStringContainsString( 'swg-layout-justified', $justified->html );
		$this->assertStringContainsString( 'swg-layout-masonry', $masonry->html );
	}

	public function test_the_masonry_asset_survives_the_cache(): void {
		$gallery = $this->gallery();

		$first  = $this->render( $gallery, array( 'layout' => 'masonry' ) );
		$second = $this->render( $gallery, array( 'layout' => 'masonry' ) );

		$this->assertSame( array( RenderAsset::Lightbox, RenderAsset::Masonry ), $second->assets, 'A cache hit reports the same assets.' );
		$this->assertSame( 'masonry', $second->layoutId );
		$this->assertSame( $first->html, $second->html );
	}

	public function test_the_cache_key_differs_per_layout_and_settings(): void {
		$gallery = $this->gallery();

		$keys = array(
			$this->key( $gallery, 'grid' ),
			$this->key( $gallery, 'justified' ),
			$this->key( $gallery, 'masonry' ),
			$this->key( $gallery, 'justified', array( 'rowHeight' => 300 ) ),
			$this->key( $gallery, 'justified', array( 'lastRow' => 'justify' ) ),
			$this->key( $gallery, 'masonry', array( 'columns' => 5 ) ),
		);
		$this->assertCount( count( $keys ), array_unique( $keys ) );
	}

	public function test_item_settings_reach_the_markup_and_ratios_come_from_the_thumbnails(): void {
		$gallery = $this->gallery( 3 );
		$result  = $this->render(
			$gallery,
			array(
				'layout'   => 'justified',
				'settings' => array(
					'rowHeight' => 150,
					'lastRow'   => 'justify',
				),
			)
		);

		$this->assertStringContainsString( '--swg-row-height:150px', $result->html );
		$this->assertStringContainsString( 'swg-last-justify', $result->html );
		$this->assertSame( 3, preg_match_all( '/<li class="swg-item" style="--swg-ar:[0-9.]+">/', $result->html ) );
		$this->assertEqualsWithDelta( 4 / 3, (float) ( preg_match( '/--swg-ar:([0-9.]+)/', $result->html, $m ) ? $m[1] : 0 ), 0.0001 );
	}

	public function test_pagination_works_with_both_layouts(): void {
		$gallery = $this->gallery( 5 );
		foreach ( array( 'justified', 'masonry' ) as $layout ) {
			$_SERVER['REQUEST_URI'] = '/photos/';
			$_GET                   = array();
			$page_one               = $this->render(
				$gallery,
				array(
					'layout'   => $layout,
					'per_page' => 2,
				)
			);
			$this->assertSame( 3, $page_one->totalPages, $layout );
			$this->assertSame( 2, substr_count( $page_one->html, 'class="swg-item"' ), $layout );
			$this->assertStringContainsString( "swg_page_{$gallery}=2", $page_one->html, $layout );

			$_GET[ 'swg_page_' . $gallery ] = '3';
			$page_three                     = $this->render(
				$gallery,
				array(
					'layout'   => $layout,
					'per_page' => 2,
				)
			);
			$this->assertSame( 3, $page_three->page, $layout );
			$this->assertSame( 1, substr_count( $page_three->html, 'class="swg-item"' ), $layout );
		}
		$_GET = array();
	}

	public function test_an_unknown_layout_still_falls_back_to_the_grid(): void {
		$result = $this->render( $this->gallery( 2 ), array( 'layout' => 'mosaic' ) );

		$this->assertSame( 'grid', $result->layoutId );
		$this->assertSame( array( RenderAsset::Lightbox ), $result->assets );
	}
}
