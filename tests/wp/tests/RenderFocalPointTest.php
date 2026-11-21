<?php
/**
 * Focal point of an image: layout independent custom properties on the item, schema of the cropping layouts.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Application\Render\EntryPoint;
use SWGallery\Core\Domain\Layout\LoadingStrategy;
use SWGallery\Core\Domain\Layout\RenderItem;
use SWGallery\Core\Domain\Layout\ResolvedImage;
use SWGallery\Tests\Support\RenderFixtures;
use SWGallery\WordPress\Infrastructure\Services;
use SWGallery\WordPress\Rendering\View\FocalPoint;

class RenderFocalPointTest extends SwgTestCase {

	use RenderFixtures;

	/**
	 * @param array<string, mixed> $settings Item settings.
	 */
	private function item( array $settings ): RenderItem {
		return new RenderItem( 1, 5, 'T', 'C', 'A', new ResolvedImage( 'https://example.test/t.jpg', 10, 10 ), null, LoadingStrategy::Lazy, 1, 1, $settings );
	}

	public function test_the_style_is_empty_for_no_setting_and_for_the_centre(): void {
		$this->assertSame( '', FocalPoint::style( $this->item( array() ) ) );
		$this->assertSame( '', FocalPoint::style( $this->item( array( 'focal' => 'x' ) ) ) );
		$this->assertSame( '', FocalPoint::style( $this->item( array( 'focal' => array( 'x' => 50, 'y' => 50 ) ) ) ) );
		$this->assertSame( '', FocalPoint::style( $this->item( array( 'focal' => array() ) ) ) );
	}

	public function test_off_centre_values_become_clamped_custom_properties(): void {
		$this->assertSame( '--swg-fx:20%;--swg-fy:75.5%;', FocalPoint::style( $this->item( array( 'focal' => array( 'x' => 20, 'y' => 75.5 ) ) ) ) );
		$this->assertSame( '--swg-fx:0%;--swg-fy:100%;', FocalPoint::style( $this->item( array( 'focal' => array( 'x' => -40, 'y' => 900 ) ) ) ) );
		$this->assertSame( '--swg-fx:30%;--swg-fy:50%;', FocalPoint::style( $this->item( array( 'focal' => array( 'x' => 30 ) ) ) ) );
	}

	public function test_hostile_values_never_reach_the_style(): void {
		$style = FocalPoint::style( $this->item( array( 'focal' => array( 'x' => '1%;background:url(x)', 'y' => array( 5 ) ) ) ) );

		$this->assertSame( '', $style, 'non-numbers fall back to the centre' );
		$this->assertSame( '', FocalPoint::style( $this->item( array( 'focal' => array( 'x' => NAN, 'y' => INF ) ) ) ) );
	}

	public function test_the_cropping_layouts_declare_the_focal_point_in_their_item_schema(): void {
		foreach ( array( 'grid', 'honeycomb', 'jigsaw', 'scattered', 'mosaic', 'bubbles', 'shattered', 'magazine' ) as $id ) {
			$schema = Services::layouts()->get( $id )->getSettingsSchema();
			$this->assertArrayHasKey( 'focal', $schema['x-item-settings']['properties'] ?? array(), $id );
		}
	}

	public function test_a_gallery_renders_the_stored_focal_point_in_the_layouts_that_crop(): void {
		$author = $this->makeUser( 'author' );
		foreach ( array( 'grid', 'honeycomb', 'jigsaw', 'scattered' ) as $layout ) {
			$gallery = $this->makeGalleryPost( $author, 'publish', '', $layout );
			$ids     = $this->addItems( $gallery, array( $this->makeImage( 'P', 1600, 1200 ) ) );
			Services::items()->saveItemSettings( $gallery, $ids[0], array( 'focal' => array( 'x' => 12, 'y' => 88 ) ) );
			Services::revisions()->compareAndIncrement( $gallery, Services::revisions()->current( $gallery ) );

			wp_set_current_user( 0 );
			Services::reset();
			$html = Services::gallery_renderer()->render( $gallery, EntryPoint::Shortcode )->html;

			$this->assertStringContainsString( '--swg-fx:12%;--swg-fy:88%', $html, $layout );
		}
	}
}
