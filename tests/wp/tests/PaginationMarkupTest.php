<?php
/**
 * Link construction, window and escaping of the pagination markup.
 *
 * @package sw-gallery
 */

use SWGallery\WordPress\Rendering\View\PaginationMarkup;

class PaginationMarkupTest extends SwgTestCase {

	private string $request_uri = '';

	public function set_up(): void {
		parent::set_up();
		$this->request_uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
	}

	public function tear_down(): void {
		unset( $_GET['swg_page_7'] );
		$_SERVER['REQUEST_URI'] = $this->request_uri;
		parent::tear_down();
	}

	public function test_page_urls_keep_other_arguments_and_drop_the_argument_on_page_one(): void {
		$base = '/gallery/?a=1&swg_page_7=4&swg_page_8=2#top';

		$this->assertSame( '/gallery/?a=1&swg_page_7=3&swg_page_8=2#top', PaginationMarkup::page_url( $base, 7, 3 ) );
		$this->assertSame( '/gallery/?a=1&swg_page_8=2#top', PaginationMarkup::page_url( $base, 7, 1 ) );
		$this->assertSame( '/gallery/?swg_page_7=2', PaginationMarkup::page_url( '/gallery/', 7, 2 ) );
		$this->assertSame( '?swg_page_7=2', PaginationMarkup::page_url( '', 7, 2 ) );
	}

	/**
	 * @return iterable<string, array{int, int, list<int|null>}>
	 */
	public static function windows(): iterable {
		yield 'one page' => array( 1, 1, array( 1 ) );
		yield 'few pages' => array( 2, 4, array( 1, 2, 3, 4 ) );
		yield 'start of many' => array( 1, 20, array( 1, 2, 3, null, 20 ) );
		yield 'middle of many' => array( 10, 20, array( 1, null, 8, 9, 10, 11, 12, null, 20 ) );
		yield 'gap of one is filled' => array( 5, 20, array( 1, 2, 3, 4, 5, 6, 7, null, 20 ) );
		yield 'end of many' => array( 20, 20, array( 1, null, 18, 19, 20 ) );
	}

	/**
	 * @dataProvider windows
	 * @param list<int|null> $expected Expected numbers, null for an ellipsis.
	 */
	public function test_the_window_is_bounded_with_ellipses( int $page, int $total, array $expected ): void {
		$this->assertSame( $expected, PaginationMarkup::window( $page, $total ) );
	}

	public function test_the_nav_has_real_links_prev_next_and_the_current_page_marked(): void {
		$html = PaginationMarkup::nav( 7, 'My "<b>" gallery', 3, 6, '/p/?x=1&y=a b' );

		$document = new DOMDocument();
		$document->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>', LIBXML_NOERROR );
		$xpath = new DOMXPath( $document );

		$this->assertSame( 1, $xpath->query( '//nav[@class="swg-pagination"]' )->length );
		$this->assertStringContainsString( 'Pages of gallery &quot;My &quot;&lt;b&gt;&quot; gallery&quot;', $html );
		$this->assertSame( '/p/?x=1&y=a+b&swg_page_7=2', $xpath->query( '//a[@rel="prev"]' )->item( 0 )->getAttribute( 'href' ) );
		$this->assertSame( '/p/?x=1&y=a+b&swg_page_7=4', $xpath->query( '//a[@rel="next"]' )->item( 0 )->getAttribute( 'href' ) );
		$this->assertSame( '3', $xpath->query( '//span[@aria-current="page"]' )->item( 0 )->textContent );
		$this->assertSame( 1, $xpath->query( '//a[text()="1"]' )->length );
		$this->assertSame( '/p/?x=1&y=a+b', $xpath->query( '//a[text()="1"]' )->item( 0 )->getAttribute( 'href' ) );
		$this->assertSame( 0, $xpath->query( '//span[@aria-disabled]' )->length );
		$this->assertStringContainsString( '&#038;', $html, 'ampersands in URLs are escaped' );
		$this->assertStringNotContainsString( '<b>', $html );
	}

	public function test_the_ends_are_disabled_text_not_links(): void {
		$first = PaginationMarkup::nav( 7, '', 1, 3, '/' );
		$last  = PaginationMarkup::nav( 7, '', 3, 3, '/' );

		$this->assertStringNotContainsString( 'rel="prev"', $first );
		$this->assertStringContainsString( '<span class="swg-pagination__disabled" aria-disabled="true">Previous page</span>', $first );
		$this->assertStringContainsString( 'rel="next"', $first );
		$this->assertStringNotContainsString( 'rel="next"', $last );
		$this->assertStringContainsString( 'aria-disabled="true">Next page</span>', $last );
		$this->assertStringContainsString( 'aria-label="Gallery pages"', $first );
	}

	public function test_a_single_page_has_no_controls_and_the_current_page_is_clamped(): void {
		$this->assertSame( '', PaginationMarkup::nav( 7, 'T', 1, 1, '/' ) );
		$this->assertSame( '', PaginationMarkup::status( 1, 1 ) );
		$this->assertStringContainsString( 'aria-current="page">3</span>', PaginationMarkup::nav( 7, 'T', 99, 3, '/' ) );
		$this->assertSame( '<p class="swg-pagination__status">Page 2 of 5</p>', PaginationMarkup::status( 2, 5 ) );
	}

	/**
	 * @return iterable<string, array{mixed, int}>
	 */
	public static function requests(): iterable {
		yield 'absent' => array( null, 1 );
		yield 'valid' => array( '4', 4 );
		yield 'zero' => array( '0', 1 );
		yield 'negative' => array( '-3', 1 );
		yield 'text' => array( 'abc', 1 );
		yield 'array' => array( array( '2' ), 1 );
		yield 'huge' => array( '99999999999999999999', 1 );
		yield 'upper bound' => array( '999999', PaginationMarkup::MAX_PAGE );
	}

	/**
	 * @dataProvider requests
	 * @param mixed $value Raw query value.
	 */
	public function test_the_requested_page_is_validated( $value, int $expected ): void {
		if ( null !== $value ) {
			$_GET['swg_page_7'] = $value;
		}
		$this->assertSame( $expected, PaginationMarkup::requested_page( 7 ) );
	}

	public function test_the_current_url_comes_from_the_sanitised_request_uri(): void {
		$_SERVER['REQUEST_URI'] = '/blog/post/?a=1&b=<script>';
		$url                    = PaginationMarkup::current_url();
		$this->assertStringStartsWith( '/blog/post/?a=1', $url );
		$this->assertStringNotContainsString( '<script>', $url );

		unset( $_SERVER['REQUEST_URI'] );
		$this->assertSame( '', PaginationMarkup::current_url() );
		$_SERVER['REQUEST_URI'] = $this->request_uri;
	}
}
