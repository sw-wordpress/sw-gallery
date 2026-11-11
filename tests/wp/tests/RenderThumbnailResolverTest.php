<?php
/**
 * Image variant selection: registered sizes only, srcset/sizes, dimensions, lightbox policy.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Domain\Layout\ImageRequest;
use SWGallery\Core\Domain\Layout\ImageRole;
use SWGallery\Tests\Support\RenderFixtures;
use SWGallery\WordPress\Media\ThumbnailResolver;

class RenderThumbnailResolverTest extends SwgTestCase {

	use RenderFixtures;

	private ThumbnailResolver $resolver;

	public function set_up(): void {
		parent::set_up();
		$this->resolver = new ThumbnailResolver();
	}

	public function tear_down(): void {
		$this->purgeGalleries();
		parent::tear_down();
	}

	private function thumb( int $id, int $width = 300, string $sizes = '33vw' ): ?\SWGallery\Core\Domain\Layout\ResolvedImage {
		return $this->resolver->resolve( new ImageRequest( $id, $width, $sizes ) );
	}

	public function test_it_picks_the_smallest_registered_size_that_covers_the_display_width(): void {
		$id    = $this->makeImage( 'A', 2000, 1500 );
		$image = $this->thumb( $id, 500 );

		$this->assertNotNull( $image );
		$this->assertStringEndsWith( "photo-$id-768x576.jpg", $image->url );
		$this->assertSame( 768, $image->width );
		$this->assertSame( 576, $image->height );
		$this->assertSame( '33vw', $image->sizes );
	}

	public function test_srcset_lists_the_same_ratio_sizes_and_never_the_hard_cropped_thumbnail(): void {
		$id    = $this->makeImage( 'A', 2000, 1500 );
		$image = $this->thumb( $id, 300 );

		$this->assertNotNull( $image );
		$this->assertNotNull( $image->srcset );
		$this->assertStringContainsString( "photo-$id-300x225.jpg 300w", $image->srcset );
		$this->assertStringContainsString( "photo-$id-768x576.jpg 768w", $image->srcset );
		$this->assertStringContainsString( "photo-$id-1024x768.jpg 1024w", $image->srcset );
		$this->assertStringContainsString( "photo-$id.jpg 2000w", $image->srcset );
		$this->assertStringNotContainsString( '150w', $image->srcset );
	}

	public function test_a_size_that_is_no_longer_registered_is_ignored(): void {
		$sizes = array(
			'plugin_size' => array(
				'file'   => 'photo-x-400x300.jpg',
				'width'  => 400,
				'height' => 300,
			),
			'medium'      => array(
				'file'   => 'photo-x-300x225.jpg',
				'width'  => 300,
				'height' => 225,
			),
		);
		$id    = $this->makeImage( 'A', 2000, 1500, array(), $sizes );
		$image = $this->thumb( $id, 350 );

		$this->assertNotNull( $image );
		$this->assertStringNotContainsString( '400x300', (string) $image->srcset );
		$this->assertSame( 2000, $image->width, 'The next registered candidate is the full file.' );
	}

	public function test_a_tiny_image_without_subsizes_has_no_srcset_but_keeps_its_dimensions(): void {
		$id    = $this->makeImage( 'Tiny', 120, 80, array(), array() );
		$image = $this->thumb( $id, 400 );

		$this->assertNotNull( $image );
		$this->assertStringEndsWith( "photo-$id.jpg", $image->url );
		$this->assertSame( array( 120, 80 ), array( $image->width, $image->height ) );
		$this->assertNull( $image->srcset );
		$this->assertNull( $image->sizes );
	}

	public function test_missing_subsizes_fall_back_to_the_full_file(): void {
		$id    = $this->makeImage( 'Plain', 1600, 900, array(), array() );
		$image = $this->thumb( $id, 300 );

		$this->assertNotNull( $image );
		$this->assertSame( 1600, $image->width );
	}

	public function test_when_no_candidate_is_wide_enough_the_largest_is_used(): void {
		$id    = $this->makeImage( 'Small', 500, 375, array(), array() );
		$image = $this->thumb( $id, 1200 );

		$this->assertNotNull( $image );
		$this->assertSame( 500, $image->width );
	}

	public function test_the_lightbox_uses_the_scaled_original_and_never_the_unscaled_one(): void {
		$id   = $this->makeImage( 'Big', 2560, 1920 );
		$meta = wp_get_attachment_metadata( $id );
		$meta['original_image'] = "photo-$id-unscaled.jpg";
		wp_update_attachment_metadata( $id, $meta );

		$image = $this->resolver->resolve( new ImageRequest( $id, 2560, '100vw', ImageRole::Lightbox ) );

		$this->assertNotNull( $image );
		$this->assertStringEndsWith( "photo-$id.jpg", $image->url );
		$this->assertSame( 2560, $image->width );
		$this->assertStringNotContainsString( 'unscaled', $image->url . $image->srcset );
		$this->assertSame( '100vw', $image->sizes );
	}

	public function test_the_lightbox_stays_below_the_cap_when_the_full_file_is_larger(): void {
		$id    = $this->makeImage( 'Huge', 5000, 3000 );
		$image = $this->resolver->resolve( new ImageRequest( $id, 2560, '100vw', ImageRole::Lightbox ) );

		$this->assertNotNull( $image );
		$this->assertLessThanOrEqual( ThumbnailResolver::LIGHTBOX_MAX_WIDTH, $image->width );
		$this->assertStringEndsWith( "photo-$id-1024x614.jpg", $image->url, 'Largest registered size below the cap (large).' );
	}

	public function test_the_srcset_never_lists_candidates_above_the_limit(): void {
		$id    = $this->makeImage( 'Huge', 5000, 3000 );
		$image = $this->thumb( $id, 300 );

		$this->assertNotNull( $image );
		$this->assertStringNotContainsString( '5000w', (string) $image->srcset );
	}

	public function test_unusable_attachments_resolve_to_null(): void {
		$trashed = $this->makeImage( 'Trashed', 800, 600, array( 'post_status' => 'trash' ) );
		$pdf     = $this->makeImage( 'Pdf', 800, 600, array(), null, '', 'application/pdf' );
		$svg     = $this->makeImage( 'Svg', 800, 600, array(), null, '', 'image/svg+xml' );
		$no_meta = $this->makeAttachment();
		$text    = $this->factory()->post->create();

		foreach ( array( $trashed, $pdf, $svg, $no_meta, $text, 987654 ) as $id ) {
			$this->assertNull( $this->thumb( $id ), "Attachment $id must not resolve." );
		}
	}

	public function test_an_image_without_dimensions_is_unusable(): void {
		$id = $this->makeImage( 'Broken', 0, 0, array(), array() );

		$this->assertNull( $this->thumb( $id ) );
	}

	public function test_an_empty_url_from_a_filter_makes_the_image_unusable(): void {
		$id = $this->makeImage( 'Filtered' );
		add_filter( 'wp_get_attachment_url', '__return_empty_string' );

		$this->assertNull( $this->thumb( $id ) );
		remove_filter( 'wp_get_attachment_url', '__return_empty_string' );
	}

	public function test_core_srcset_and_size_filters_cannot_change_the_contract(): void {
		$id     = $this->makeImage( 'A', 2000, 1500 );
		$before = $this->thumb( $id, 300 );
		add_filter( 'wp_calculate_image_srcset', '__return_empty_array' );
		add_filter( 'wp_calculate_image_sizes', '__return_empty_string' );
		add_filter( 'max_srcset_image_width', '__return_zero' );
		add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );

		$after = $this->thumb( $id, 300 );

		$this->assertEquals( $before, $after );
		remove_filter( 'wp_calculate_image_srcset', '__return_empty_array' );
		remove_filter( 'wp_calculate_image_sizes', '__return_empty_string' );
		remove_filter( 'max_srcset_image_width', '__return_zero' );
		remove_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
	}

	public function test_a_cdn_style_url_filter_is_respected_for_all_variants(): void {
		$id = $this->makeImage( 'Cdn' );
		$to = static fn( $url ) => str_replace( 'example.org', 'cdn.example.test', (string) $url );
		add_filter( 'wp_get_attachment_url', $to );

		$image = $this->thumb( $id, 300 );

		$this->assertNotNull( $image );
		$this->assertStringContainsString( 'cdn.example.test', $image->url );
		$this->assertStringContainsString( 'cdn.example.test', (string) $image->srcset );
		remove_filter( 'wp_get_attachment_url', $to );
	}

	public function test_it_registers_no_image_size_of_its_own(): void {
		$before = wp_get_registered_image_subsizes();
		$id     = $this->makeImage();
		$this->thumb( $id );
		$this->resolver->resolve( new ImageRequest( $id, 2560, '100vw', ImageRole::Lightbox ) );

		$this->assertSame( $before, wp_get_registered_image_subsizes() );
	}

	public function test_resolving_many_images_does_not_query_per_image(): void {
		global $wpdb;
		$ids = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$ids[] = $this->makeImage( "Img $i" );
		}
		// Prime posts and meta like the reference lookup of the pipeline does.
		( new \SWGallery\WordPress\Media\WpMediaRepository() )->findReferences( $ids );
		$this->thumb( $ids[0] );
		$before = $wpdb->num_queries;
		foreach ( $ids as $id ) {
			$this->thumb( $id );
			$this->resolver->resolve( new ImageRequest( $id, 2560, '100vw', ImageRole::Lightbox ) );
		}

		$this->assertSame( 0, $wpdb->num_queries - $before );
	}
}
