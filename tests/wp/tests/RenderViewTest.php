<?php
/**
 * View helpers: diagnostics texts, caption policy, markup edge cases, grid image hints.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Application\Render\RenderDiagnostic;
use SWGallery\Core\Domain\Gallery\GallerySettings;
use SWGallery\Core\Domain\Gallery\SortMode;
use SWGallery\Core\Domain\Layout\LayoutRenderRequest;
use SWGallery\Core\Domain\Layout\LoadingStrategy;
use SWGallery\Core\Domain\Layout\RenderItem;
use SWGallery\Core\Domain\Layout\ResolvedImage;
use SWGallery\WordPress\Rendering\DiagnosticMarkup;
use SWGallery\WordPress\Rendering\Layout\GridLayout;
use SWGallery\WordPress\Rendering\View\CaptionPolicy;
use SWGallery\WordPress\Rendering\View\FeedMarkup;
use SWGallery\WordPress\Rendering\View\ImageMarkup;

class RenderViewTest extends SwgTestCase {

	public function test_every_diagnostic_code_has_its_own_translatable_sentence(): void {
		$codes = array(
			RenderDiagnostic::GALLERY_NOT_FOUND,
			RenderDiagnostic::GALLERY_NOT_PUBLIC,
			RenderDiagnostic::UNKNOWN_LAYOUT,
			RenderDiagnostic::INVALID_OVERRIDE,
			RenderDiagnostic::EMPTY_GALLERY,
			RenderDiagnostic::IMAGES_UNAVAILABLE,
			'something_new',
		);
		$texts = array();
		foreach ( $codes as $code ) {
			$texts[] = DiagnosticMarkup::message( new RenderDiagnostic( $code, array( 'layout' => 'mosaic', 'count' => 2 ) ) );
		}

		$this->assertSame( count( $codes ), count( array_unique( $texts ) ) );
		foreach ( $texts as $text ) {
			$this->assertStringContainsString( 'visible to editors only', $text );
		}
		$this->assertStringContainsString( 'mosaic', $texts[2] );
		$this->assertStringContainsString( '2 images', $texts[5] );
	}

	public function test_the_unavailable_images_sentence_has_a_singular_form(): void {
		$this->assertStringContainsString( '1 image is', DiagnosticMarkup::message( new RenderDiagnostic( RenderDiagnostic::IMAGES_UNAVAILABLE, array( 'count' => 1 ) ) ) );
	}

	public function test_diagnostic_markup_escapes_context_values(): void {
		$html = DiagnosticMarkup::render( array( new RenderDiagnostic( RenderDiagnostic::UNKNOWN_LAYOUT, array( 'layout' => '<b>x</b>' ) ) ) );

		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertSame( '', DiagnosticMarkup::render( array() ) );
	}

	public function test_the_caption_policy_allows_inline_markup_only(): void {
		$html = CaptionPolicy::html( '<strong>a</strong> <em>b</em><br><a href="https://example.test" onclick="x()" rel="nofollow">c</a><p>d</p><script>e</script>' );

		$this->assertStringContainsString( '<strong>a</strong>', $html );
		$this->assertStringContainsString( '<a href="https://example.test" rel="nofollow">c</a>', $html );
		$this->assertStringNotContainsString( '<p>', $html );
		$this->assertStringNotContainsString( 'onclick', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertSame( array( 'a', 'em', 'strong', 'br' ), array_keys( CaptionPolicy::allowed_html() ) );
		$this->assertSame( 'a &amp; b', CaptionPolicy::text( '<b>a</b> & b' ) );
	}

	public function test_images_without_dimensions_or_srcset_omit_those_attributes(): void {
		$html = ImageMarkup::image( new ResolvedImage( 'https://example.test/a.jpg', 0, 0 ), 'x', LoadingStrategy::Lazy );

		$this->assertStringNotContainsString( 'width=', $html );
		$this->assertStringNotContainsString( 'srcset=', $html );
		$this->assertStringNotContainsString( 'sizes=', $html );
		$this->assertStringContainsString( 'loading="lazy"', $html );
		$this->assertStringContainsString( 'decoding="async"', $html );
	}

	public function test_loading_strategies_map_to_attributes(): void {
		$image = new ResolvedImage( 'https://example.test/a.jpg', 10, 10 );

		$this->assertStringContainsString( 'loading="eager"', ImageMarkup::image( $image, '', LoadingStrategy::Eager ) );
		$this->assertStringNotContainsString( 'fetchpriority', ImageMarkup::image( $image, '', LoadingStrategy::Eager ) );
		$this->assertStringContainsString( 'fetchpriority="high"', ImageMarkup::image( $image, '', LoadingStrategy::Priority ) );
	}

	public function test_a_link_without_known_full_dimensions_omits_the_data_attributes(): void {
		$item = new RenderItem( 1, 2, 't', '', 'a', new ResolvedImage( 'https://example.test/a.jpg', 0, 0 ) );

		$html = ImageMarkup::lightbox_item( $item );

		$this->assertStringNotContainsString( 'data-swg-full', $html );
		$this->assertStringContainsString( 'href="https://example.test/a.jpg"', $html );
	}

	/**
	 * FU-25: the link only gets an aria-label when the image has no alt text; title first, then the position.
	 */
	public function test_the_link_gets_an_accessible_name_only_for_images_without_alt_text(): void {
		$image = new ResolvedImage( 'https://example.test/a.jpg', 10, 10 );

		$with_alt = ImageMarkup::lightbox_item( new RenderItem( 1, 2, 'Title', '', 'Alt', $image, null, LoadingStrategy::Lazy, 3, 9 ) );
		$this->assertStringNotContainsString( 'aria-label', $with_alt, 'a non-empty alt names the link; no duplicate' );

		$titled = ImageMarkup::lightbox_item( new RenderItem( 1, 2, 'A "quoted" <b>title</b>', '', '', $image, null, LoadingStrategy::Lazy, 3, 9 ) );
		$this->assertStringContainsString( 'data-swg-full-height="10" aria-label="A &quot;quoted&quot; &lt;b&gt;title&lt;/b&gt;">', $titled );
		$this->assertStringContainsString( 'alt=""', $titled );

		$numbered = ImageMarkup::lightbox_item( new RenderItem( 1, 2, '  ', '', ' ', $image, null, LoadingStrategy::Lazy, 3, 9 ) );
		$this->assertStringContainsString( 'aria-label="Image 3 of 9"', $numbered );

		$unknown = ImageMarkup::lightbox_item( new RenderItem( 1, 2, '', '', '', $image ) );
		$this->assertStringNotContainsString( 'aria-label', $unknown, 'no position known: nothing invented' );
	}

	public function test_the_numbered_link_name_is_translated(): void {
		$image    = new ResolvedImage( 'https://example.test/a.jpg', 10, 10 );
		$switched = switch_to_locale( 'de_DE' );
		load_textdomain( 'sw-gallery', SWGALLERY_DIR . 'languages/sw-gallery-de_DE.mo' );
		try {
			$html = ImageMarkup::lightbox_item( new RenderItem( 1, 2, '', '', '', $image, null, LoadingStrategy::Lazy, 2, 5 ) );
			$this->assertStringContainsString( 'aria-label="Bild 2 von 5"', $html );
		} finally {
			unload_textdomain( 'sw-gallery' );
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

	public function test_empty_feed_markup_is_empty(): void {
		$request = new LayoutRenderRequest( 1, 'swg-1-1', array(), new GallerySettings() );

		$this->assertSame( '', ( new FeedMarkup() )->render( $request ) );
	}

	public function test_grid_image_hints_follow_the_responsive_columns(): void {
		$layout  = new GridLayout();
		$default = $layout->imageHints( new GallerySettings() );
		$resp    = $layout->imageHints( new GallerySettings( 'grid', SortMode::Manual, array( 'columns' => array( 'base' => 1, 'md' => 3, 'xl' => 6 ) ) ) );
		$single  = $layout->imageHints( new GallerySettings( 'grid', SortMode::Manual, array( 'columns' => 4 ) ) );
		$garbage = $layout->imageHints( new GallerySettings( 'grid', SortMode::Manual, array( 'columns' => 'many' ) ) );
		$huge    = $layout->imageHints( new GallerySettings( 'grid', SortMode::Manual, array( 'columns' => 12 ) ) );

		$this->assertSame( array( 400, 'calc(100vw / 3)', 3 ), array( $default->displayWidth, $default->sizes, $default->firstRowCount ) );
		$this->assertSame( array( 200, 6 ), array( $resp->displayWidth, $resp->firstRowCount ) );
		$this->assertSame( '(min-width: 1280px) calc(100vw / 6), (min-width: 768px) calc(100vw / 3), calc(100vw / 1)', $resp->sizes );
		$this->assertSame( array( 300, 4 ), array( $single->displayWidth, $single->firstRowCount ) );
		$this->assertSame( 3, $garbage->firstRowCount );
		$this->assertSame( 6, $huge->firstRowCount, 'The first row is capped.' );
	}
}
