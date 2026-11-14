<?php
/**
 * Pagination through the full render path: request URL, independent galleries, per-page bounds, feed and preview.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Application\Render\EntryPoint;
use SWGallery\Core\Application\Render\RenderResult;
use SWGallery\Tests\Support\RenderFixtures;
use SWGallery\WordPress\Infrastructure\Services;
use SWGallery\WordPress\Settings\PluginSettings;

class RenderPaginationTest extends SwgTestCase {

	use RenderFixtures;

	private int $author;
	private string $request_uri = '';

	public function set_up(): void {
		parent::set_up();
		$this->request_uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
		$this->purgeGalleries();
		Services::reset();
		$this->author = $this->makeUser( 'author' );
	}

	public function tear_down(): void {
		delete_option( PluginSettings::OPTION );
		$_GET                   = array();
		$_SERVER['REQUEST_URI'] = $this->request_uri;
		wp_set_current_user( 0 );
		$this->purgeGalleries();
		Services::reset();
		parent::tear_down();
	}

	/**
	 * @param int $count Images to add.
	 */
	private function gallery( int $count ): int {
		$gallery = $this->makeGalleryPost( $this->author );
		$ids     = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$ids[] = $this->makeImage( "Image $i", 1600, 1200, array(), null, "Alt $i" );
		}
		$this->addItems( $gallery, $ids );
		return $gallery;
	}

	/**
	 * @param array<string, mixed> $options Render options.
	 */
	private function render( int $gallery, EntryPoint $entry = EntryPoint::Shortcode, array $options = array() ): RenderResult {
		Services::reset();
		return Services::gallery_renderer()->render( $gallery, $entry, $options );
	}

	/**
	 * @return list<string> The hrefs of the pagination links.
	 */
	private function hrefs( string $html ): array {
		preg_match_all( '/<a class="swg-pagination__link" href="([^"]*)"/', $html, $matches );
		return array_map( 'html_entity_decode', $matches[1] );
	}

	/**
	 * FU-25: an image without alt text and title is named "Image N of M" by its position in the WHOLE gallery, on
	 * every layout and also for anonymous visitors.
	 */
	public function test_links_of_images_without_alt_text_are_numbered_by_the_position_in_the_gallery(): void {
		$gallery = $this->makeGalleryPost( $this->author );
		$ids     = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$ids[] = $this->makeImage( '', 1600, 1200 );
		}
		$ids[] = $this->makeImage( 'Titled', 1600, 1200 );
		$this->addItems( $gallery, $ids );
		$_SERVER['REQUEST_URI'] = "/photos/?swg_page_{$gallery}=2";
		$_GET                   = array( "swg_page_$gallery" => '2' );

		foreach ( array( 'grid', 'justified', 'masonry' ) as $layout ) {
			$html = $this->render( $gallery, EntryPoint::Shortcode, array( 'per_page' => 2, 'layout' => $layout ) )->html;
			$this->assertSame( 2, substr_count( $html, '<a class="swg-link"' ), $layout );
			$this->assertStringContainsString( 'aria-label="Image 3 of 6"', $html, $layout );
			$this->assertStringContainsString( 'aria-label="Image 4 of 6"', $html, $layout );
			$this->assertStringNotContainsString( 'aria-label="Image 1 of 6"', $html, $layout );
		}
	}

	public function test_a_multi_page_gallery_gets_a_nav_with_links_built_from_the_request_url(): void {
		$gallery                = $this->gallery( 5 );
		$_SERVER['REQUEST_URI'] = "/photos/?utm=1&swg_page_{$gallery}=2";
		$_GET                   = array(
			'utm'                    => '1',
			"swg_page_$gallery"      => '2',
		);

		$result = $this->render( $gallery, EntryPoint::Shortcode, array( 'per_page' => 2 ) );

		$this->assertSame( 2, $result->page );
		$this->assertSame( 3, $result->totalPages );
		$this->assertSame( 2, $result->renderedItems );
		$this->assertStringContainsString( '<nav class="swg-pagination"', $result->html );
		$this->assertStringContainsString( 'rel="prev"', $result->html );
		$this->assertStringContainsString( 'aria-current="page">2</span>', $result->html );
		$this->assertSame(
			array( '/photos/?utm=1', '/photos/?utm=1', "/photos/?utm=1&swg_page_{$gallery}=3", "/photos/?utm=1&swg_page_{$gallery}=3" ),
			$this->hrefs( $result->html )
		);
		$this->assertGreaterThan( strpos( $result->html, '</ul></div>' ), strpos( $result->html, '<nav' ), 'the nav follows the gallery' );
	}

	public function test_the_first_page_has_no_argument_and_a_single_page_no_nav(): void {
		$gallery                = $this->gallery( 5 );
		$_SERVER['REQUEST_URI'] = '/photos/';

		$first = $this->render( $gallery, EntryPoint::Block, array( 'per_page' => 2 ) );
		$this->assertSame( 1, $first->page );
		$this->assertContains( "/photos/?swg_page_{$gallery}=2", $this->hrefs( $first->html ) );
		$this->assertStringContainsString( 'aria-disabled="true">Previous page', $first->html );

		$single = $this->render( $gallery, EntryPoint::Block, array( 'per_page' => 50 ) );
		$this->assertStringNotContainsString( 'swg-pagination', $single->html );
	}

	public function test_several_galleries_paginate_independently(): void {
		$one                    = $this->gallery( 4 );
		$two                    = $this->gallery( 4 );
		$_SERVER['REQUEST_URI'] = "/x/?swg_page_{$one}=2";
		$_GET                   = array( "swg_page_$one" => '2' );

		Services::reset();
		$renderer = Services::gallery_renderer();
		$a        = $renderer->render( $one, EntryPoint::Shortcode, array( 'per_page' => 2 ) );
		$b        = $renderer->render( $two, EntryPoint::Shortcode, array( 'per_page' => 2 ) );

		$this->assertSame( 2, $a->page );
		$this->assertSame( 1, $b->page );
		$this->assertContains( "/x/?swg_page_{$one}=2&swg_page_{$two}=2", $this->hrefs( $b->html ), 'the second gallery keeps the first one\'s argument' );
		$this->assertSame( array( '/x/', '/x/' ), $this->hrefs( $a->html ), 'page 1 of the first gallery drops only its own argument' );
	}

	public function test_a_page_past_the_end_shows_the_last_page(): void {
		$gallery                = $this->gallery( 5 );
		$_SERVER['REQUEST_URI'] = "/p/?swg_page_{$gallery}=99";
		$_GET                   = array( "swg_page_$gallery" => '99' );

		$result = $this->render( $gallery, EntryPoint::Shortcode, array( 'per_page' => 2 ) );

		$this->assertSame( 3, $result->page );
		$this->assertSame( 1, $result->renderedItems );
		$this->assertStringContainsString( 'aria-current="page">3</span>', $result->html );
		$this->assertStringContainsString( 'aria-disabled="true">Next page', $result->html );
	}

	public function test_an_explicit_page_option_wins_over_the_request(): void {
		$gallery                = $this->gallery( 5 );
		$_GET                   = array( "swg_page_$gallery" => '3' );
		$_SERVER['REQUEST_URI'] = '/p/';

		$this->assertSame( 2, $this->render( $gallery, EntryPoint::Shortcode, array( 'per_page' => 2, 'page' => 2 ) )->page );
	}

	public function test_the_page_size_defaults_to_the_plugin_setting_and_is_bounded(): void {
		$gallery = $this->gallery( 5 );
		update_option( PluginSettings::OPTION, (string) wp_json_encode( array( 'itemsPerPage' => 2, 'schemaVersion' => 1 ) ) );

		$this->assertSame( 3, $this->render( $gallery )->totalPages, 'setting applies' );
		$this->assertSame( 5, $this->render( $gallery, EntryPoint::Shortcode, array( 'per_page' => '1' ) )->totalPages, 'embed overrides' );
		$this->assertSame( 1, $this->render( $gallery, EntryPoint::Shortcode, array( 'per_page' => 100000 ) )->totalPages, 'upper bound 200' );
		$this->assertSame( 5, $this->render( $gallery, EntryPoint::Shortcode, array( 'per_page' => 0 ) )->totalPages, 'lower bound 1' );

		delete_option( PluginSettings::OPTION );
		$this->assertSame( 1, $this->render( $gallery )->totalPages, 'default 24' );
	}

	public function test_feed_and_preview_show_page_text_instead_of_links_and_ignore_the_request(): void {
		$gallery                = $this->gallery( 5 );
		$_GET                   = array( "swg_page_$gallery" => '3' );
		$_SERVER['REQUEST_URI'] = "/p/?swg_page_{$gallery}=3";

		foreach ( array( EntryPoint::Feed, EntryPoint::Preview ) as $entry ) {
			wp_set_current_user( EntryPoint::Preview === $entry ? $this->makeUser( 'administrator' ) : 0 );
			$result = $this->render( $gallery, $entry, array( 'per_page' => 2 ) );
			$this->assertSame( 1, $result->page, $entry->value );
			$this->assertStringContainsString( '<p class="swg-pagination__status">Page 1 of 3</p>', $result->html, $entry->value );
			$this->assertStringNotContainsString( '<nav', $result->html, $entry->value );
			$this->assertSame( 0, preg_match( '/swg_page_/', $result->html ), $entry->value );
		}
	}

	public function test_an_unavailable_gallery_has_no_paging(): void {
		$result = $this->render( 987654 );
		$this->assertSame( '', $result->html );
	}
}
