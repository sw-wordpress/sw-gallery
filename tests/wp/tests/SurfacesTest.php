<?php
/**
 * All output surfaces (shortcode, PHP API, block, renderer) go through the same renderer and the same access decision:
 * identical HTML, identical visibility, and every surface tells the asset collector which frontend assets it needs.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Application\Render\EntryPoint;
use SWGallery\Core\Application\Render\RenderAsset;
use SWGallery\Tests\Support\RenderFixtures;
use SWGallery\WordPress\Infrastructure\Services;
use SWGallery\WordPress\Shortcode\ShortcodeRegistrar;

class SurfacesTest extends SwgTestCase {

	use RenderFixtures;

	private int $author;
	private int $editor;

	public function set_up(): void {
		parent::set_up();
		$this->purgeGalleries();
		Services::reset();
		( new ShortcodeRegistrar() )->register();
		$this->author = $this->makeUser( 'author' );
		$this->editor = $this->makeUser( 'editor' );
		$this->lockPassword();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->lockPassword();
		$this->purgeGalleries();
		Services::reset();
		parent::tear_down();
	}

	private function gallery( string $status = 'publish', string $password = '' ): int {
		$gallery = $this->makeGalleryPost( $this->author, $status, $password );
		$images  = array();
		for ( $i = 1; $i <= 3; $i++ ) {
			$images[] = $this->makeImage( "Image $i", 1600, 1200, array(), null, "Alt $i" );
		}
		$this->addItems( $gallery, $images );
		return $gallery;
	}

	/**
	 * Runs one surface as the given user with fresh per-request state and returns its output without the per-request
	 * instance id (the only part that legitimately differs between two renders).
	 */
	private function surface_html( string $surface, int $gallery, int $user = 0, string $extra = '' ): string {
		wp_set_current_user( $user );
		Services::reset();
		switch ( $surface ) {
			case 'shortcode':
				$html = do_shortcode( '[sw_gallery id="' . $gallery . '"' . $extra . ']' );
				break;
			case 'api':
				$html = swg_render_gallery( $gallery, '' === $extra ? array() : array( 'columns' => 2 ) );
				break;
			case 'block':
				// Through WordPress' block rendering, exactly like a post would be rendered.
				$attributes = wp_json_encode( array_merge( array( 'galleryId' => $gallery ), '' === $extra ? array() : array( 'columns' => 2 ) ) );
				$html       = trim( do_blocks( '<!-- wp:sw-gallery/gallery ' . $attributes . ' /-->' ) );
				$html       = (string) preg_replace( '#^<div [^>]*>(.*)</div>$#s', '$1', $html );
				break;
			default:
				$html = Services::gallery_renderer()->html( $gallery, EntryPoint::Block, '' === $extra ? array() : array( 'settings' => array( 'columns' => 2 ) ) );
		}
		return (string) preg_replace( '/data-swg-instance="[^"]*"/', 'data-swg-instance="X"', $html );
	}

	public function test_every_surface_renders_identical_html_for_a_published_gallery(): void {
		$id        = $this->gallery();
		$reference = $this->surface_html( 'renderer', $id );

		$this->assertStringContainsString( 'data-swg-gallery', $reference );
		foreach ( array( 'shortcode', 'api', 'block' ) as $surface ) {
			$this->assertSame( $reference, $this->surface_html( $surface, $id ), "The {$surface} surface differs from the renderer." );
		}
	}

	public function test_every_surface_applies_the_same_presentation_overrides(): void {
		$id        = $this->gallery();
		$reference = $this->surface_html( 'renderer', $id, 0, ' columns="2"' );

		$this->assertStringContainsString( '--swg-columns:2', $reference );
		foreach ( array( 'shortcode', 'api', 'block' ) as $surface ) {
			$this->assertSame( $reference, $this->surface_html( $surface, $id, 0, ' columns="2"' ), "The {$surface} surface differs from the renderer." );
		}
	}

	/**
	 * @return iterable<string, array{string, string, bool, bool}>
	 */
	public static function visibility(): iterable {
		// status, password, visible to a visitor, visible to the editor who can edit.
		yield 'published' => array( 'publish', '', true, true );
		yield 'draft' => array( 'draft', '', false, false );
		yield 'private' => array( 'private', '', false, true );
	}

	/**
	 * @dataProvider visibility
	 */
	public function test_every_surface_has_the_same_visibility_as_the_renderer( string $status, string $password, bool $visitor_sees, bool $editor_sees ): void {
		$id = $this->gallery( $status, $password );

		foreach ( array(
			array( 0, $visitor_sees ),
			array( $this->editor, $editor_sees ),
		) as $case ) {
			list( $user, $sees ) = $case;
			$reference           = $this->surface_html( 'renderer', $id, $user );
			foreach ( array( 'shortcode', 'api', 'block' ) as $surface ) {
				$html = $this->surface_html( $surface, $id, $user );
				if ( $sees ) {
					$this->assertSame( $reference, $html, "{$surface} as user {$user}" );
					$this->assertStringContainsString( 'data-swg-gallery', $html );
				} else {
					$this->assertStringNotContainsString( 'data-swg-gallery', $html, "{$surface} must not leak the gallery to user {$user}" );
				}
			}
		}
	}

	public function test_a_password_protected_gallery_asks_for_the_password_on_every_surface(): void {
		$id = $this->gallery( 'publish', 'secret-pass' );

		foreach ( array( 'renderer', 'shortcode', 'api', 'block' ) as $surface ) {
			$html = $this->surface_html( $surface, $id );
			$this->assertStringNotContainsString( 'data-swg-gallery', $html, $surface );
			$this->assertStringContainsString( 'type="password"', $html, $surface );
		}
	}

	public function test_every_surface_reports_the_needed_assets_to_the_collector(): void {
		$id = $this->gallery();

		foreach ( array( 'shortcode', 'api', 'block' ) as $surface ) {
			$this->surface_html( $surface, $id );
			$collector = Services::asset_collector();
			$this->assertTrue( $collector->has_rendered(), $surface );
			$this->assertSame( array( $id ), $collector->gallery_ids(), $surface );
			$this->assertTrue( $collector->needs( RenderAsset::Lightbox ), $surface );
		}
	}

	public function test_a_gallery_nobody_may_see_reports_no_assets(): void {
		$id = $this->gallery( 'draft' );

		foreach ( array( 'shortcode', 'api', 'block' ) as $surface ) {
			$this->surface_html( $surface, $id );
			$this->assertFalse( Services::asset_collector()->has_rendered(), $surface );
		}
	}

	public function test_the_page_request_argument_reaches_every_surface_and_wins_over_the_page_attribute(): void {
		$id = $this->gallery();
		for ( $i = 0; $i < 30; $i++ ) {
			$this->addItems( $id, array( $this->makeImage( "More $i", 800, 600 ) ) );
		}
		$_SERVER['REQUEST_URI'] = '/sample/';

		// A visitor clicked "page 2": the request argument reaches shortcode, PHP API and block alike.
		$_GET[ 'swg_page_' . $id ] = '2';
		try {
			$shortcode  = $this->surface_html( 'shortcode', $id );
			$api        = $this->surface_html( 'api', $id );
			$block      = $this->surface_html( 'block', $id );
			$overridden = $this->surface_html( 'shortcode', $id, 0, ' page="1"' );
		} finally {
			unset( $_GET[ 'swg_page_' . $id ] );
		}
		$first        = $this->surface_html( 'shortcode', $id );
		$by_attribute = $this->surface_html( 'shortcode', $id, 0, ' page="2"' );

		$this->assertStringContainsString( 'aria-current="page"', $shortcode );
		$this->assertSame( $shortcode, $api );
		$this->assertSame( $shortcode, $block );
		// The attribute only sets the initial page: the visitor's request argument wins (decision for the shortcode).
		$this->assertSame( $shortcode, $overridden );
		$this->assertNotSame( $shortcode, $first, 'Without a request argument the gallery starts on page 1.' );
		$this->assertSame( $shortcode, $by_attribute, 'The page attribute selects the initial page.' );
	}
}
