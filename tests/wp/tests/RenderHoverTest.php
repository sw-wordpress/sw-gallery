<?php
/**
 * Hover texts: markup of the overlay, precedence gallery over site default, feeds and the render cache.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Application\Render\EntryPoint;
use SWGallery\Core\Domain\Gallery\HoverAnimation;
use SWGallery\Core\Domain\Gallery\HoverEffect;
use SWGallery\Core\Domain\Layout\LoadingStrategy;
use SWGallery\Core\Domain\Layout\RenderItem;
use SWGallery\Core\Domain\Layout\ResolvedImage;
use SWGallery\Tests\Support\RenderFixtures;
use SWGallery\WordPress\Infrastructure\Services;
use SWGallery\WordPress\PostTypes;
use SWGallery\WordPress\Rendering\View\ImageMarkup;
use SWGallery\WordPress\Settings\PluginSettings;

class RenderHoverTest extends SwgTestCase {

	use RenderFixtures;

	private int $author;

	public function set_up(): void {
		parent::set_up();
		$this->purgeGalleries();
		delete_option( PluginSettings::OPTION );
		Services::reset();
		$this->author = $this->makeUser( 'author' );
		$this->lockPassword();
		wp_cache_flush();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		delete_option( PluginSettings::OPTION );
		$this->lockPassword();
		$this->purgeGalleries();
		Services::reset();
		parent::tear_down();
	}

	private function gallery( string $caption = 'Sunset <em>over</em> the sea' ): int {
		$gallery = $this->makeGalleryPost( $this->author );
		$this->addItems( $gallery, array( $this->makeImage( 'Beach', 1600, 1200, array( 'post_excerpt' => $caption ), null, 'Alt' ) ) );
		return $gallery;
	}

	private function render( int $gallery, EntryPoint $entry = EntryPoint::Shortcode ): string {
		wp_set_current_user( 0 );
		Services::reset();
		return Services::gallery_renderer()->render( $gallery, $entry )->html;
	}

	/**
	 * @param array<string, mixed> $values Site settings to store.
	 */
	private function site( array $values ): void {
		update_option( PluginSettings::OPTION, (string) wp_json_encode( $values + array( 'schemaVersion' => 1 ) ) );
	}

	private function item( string $title, string $caption, ?HoverEffect $hover ): RenderItem {
		return new RenderItem( 1, 5, $title, $caption, 'Alt', new ResolvedImage( 'https://example.test/t.jpg', 10, 10 ), null, LoadingStrategy::Lazy, 1, 1, array(), $hover );
	}

	public function test_nothing_is_rendered_by_default(): void {
		$this->assertStringNotContainsString( 'swg-hover', $this->render( $this->gallery() ) );
	}

	public function test_the_site_default_applies_to_galleries_without_their_own_setting(): void {
		$this->site( array( 'hoverTitle' => true, 'hoverCaption' => true, 'hoverAnimation' => 'cover' ) );
		$html = $this->render( $this->gallery() );

		$this->assertStringContainsString( '<span class="swg-hover swg-hover--cover" aria-hidden="true">', $html );
		$this->assertStringContainsString( '<span class="swg-hover__title">Beach</span>', $html );
		$this->assertStringContainsString( '<span class="swg-hover__caption">Sunset over the sea</span>', $html );
		$this->assertStringContainsString( '<p class="swg-caption">Sunset <em>over</em> the sea</p>', $html, 'the accessible caption stays in the DOM' );
	}

	public function test_the_gallery_setting_beats_the_site_default_even_when_switching_off(): void {
		$this->site( array( 'hoverTitle' => true, 'hoverAnimation' => 'fade' ) );
		$gallery = $this->gallery();

		update_post_meta( $gallery, PostTypes::META_HOVER, wp_slash( '{"title":false,"caption":true,"animation":"slide-down"}' ) );
		$html = $this->render( $gallery );
		$this->assertStringContainsString( 'swg-hover--slide-down', $html );
		$this->assertStringContainsString( 'swg-hover__caption', $html );
		$this->assertStringNotContainsString( 'swg-hover__title', $html );

		update_post_meta( $gallery, PostTypes::META_HOVER, wp_slash( '{"title":false,"caption":false,"animation":"fade"}' ) );
		$this->assertStringNotContainsString( 'swg-hover', $this->render( $gallery ), 'an explicit "off" beats the site default' );
	}

	public function test_changing_the_site_default_is_not_served_from_a_stale_cache(): void {
		$gallery = $this->gallery();
		$this->assertStringNotContainsString( 'swg-hover', $this->render( $gallery ) );

		$this->site( array( 'hoverTitle' => true ) );
		$this->assertStringContainsString( 'swg-hover__title', $this->render( $gallery ) );
	}

	public function test_feeds_stay_plain(): void {
		$this->site( array( 'hoverTitle' => true, 'hoverCaption' => true ) );

		$this->assertStringNotContainsString( 'swg-hover', $this->render( $this->gallery(), EntryPoint::Feed ) );
	}

	public function test_texts_are_plain_and_escaped_and_empty_texts_are_skipped(): void {
		$all = new HoverEffect( true, true, HoverAnimation::SlideUp );

		$html = ImageMarkup::hover( $this->item( '"><script>alert(1)</script>Ti & tle', '<a href="x">Link</a> <img src=x onerror=alert(2)>Caption', $all ) );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<a ', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( 'Ti &amp; tle', $html );
		$this->assertStringContainsString( '<span class="swg-hover__caption">Link', $html );

		$title_only = ImageMarkup::hover( $this->item( 'T', 'C', new HoverEffect( true, false ) ) );
		$this->assertStringContainsString( 'swg-hover__title', $title_only );
		$this->assertStringNotContainsString( 'swg-hover__caption', $title_only );

		$this->assertSame( '', ImageMarkup::hover( $this->item( '', '  ', $all ) ), 'no enabled text, no overlay' );
		$this->assertSame( '', ImageMarkup::hover( $this->item( 'T', 'C', new HoverEffect() ) ) );
		$this->assertSame( '', ImageMarkup::hover( $this->item( 'T', 'C', null ) ) );
	}

	public function test_every_animation_is_a_css_modifier_class(): void {
		foreach ( HoverAnimation::cases() as $animation ) {
			$this->assertStringContainsString( 'swg-hover--' . $animation->value . '"', ImageMarkup::hover( $this->item( 'T', 'C', new HoverEffect( true, false, $animation ) ) ) );
		}
	}
}
