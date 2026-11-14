<?php
/**
 * Output escaping against DATA THAT BYPASSED THE REST SANITISING (M5-04 e): forged post meta, settings, item overrides,
 * sub-size file names and gallery titles, rendered through every layout and entry point. The renderer is the last line
 * of defence, so it must be safe even when the database holds hostile values (import, SQL access, another plugin).
 *
 * @package sw-gallery
 */

use SWGallery\Core\Application\Render\EntryPoint;
use SWGallery\Core\Domain\Gallery\ItemOverrides;
use SWGallery\Tests\Support\RenderFixtures;
use SWGallery\WordPress\Infrastructure\Services;
use SWGallery\WordPress\PostTypes;

class RenderForgedDataEscapingTest extends SwgTestCase {

	use RenderFixtures;

	private const HOSTILE = '"><svg onload=alert(1)><script>alert(2)</script><a href="javascript:alert(3)">x</a>\'`${7*7}';

	public function set_up(): void {
		parent::set_up();
		$this->purgeGalleries();
		Services::reset();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->purgeGalleries();
		Services::reset();
		parent::tear_down();
	}

	/**
	 * @return array<string, array{0: string, 1: EntryPoint}>
	 */
	public static function surfaces(): array {
		$cases = array();
		foreach ( array( 'grid', 'masonry', 'justified' ) as $layout ) {
			foreach ( array( EntryPoint::Shortcode, EntryPoint::Block, EntryPoint::PhpApi, EntryPoint::Preview, EntryPoint::Feed ) as $entry ) {
				$cases[ $layout . ' / ' . $entry->value ] = array( $layout, $entry );
			}
		}
		return $cases;
	}

	/**
	 * @dataProvider surfaces
	 */
	public function test_forged_stored_values_never_become_markup( string $layout, EntryPoint $entry ): void {
		$author  = $this->makeUser( 'author' );
		$gallery = $this->makeGalleryPost( $author, 'publish', '', $layout );
		wp_update_post( array( 'ID' => $gallery, 'post_title' => self::HOSTILE ) );
		// Forged settings: CSS injection through every scalar the layouts turn into inline style.
		update_post_meta(
			$gallery,
			PostTypes::META_SETTINGS,
			wp_slash(
				(string) wp_json_encode(
					array(
						'columns'  => '3;background:url(//evil.test/x)',
						'gap'      => '8px;position:fixed',
						'aspect'   => '1/1;background:url(//evil.test/y)',
						'radius'   => 'x"onmouseover="alert(1)',
						'rowHeight' => '120;}</style><script>alert(1)</script>',
					)
				)
			)
		);
		$images = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$images[] = $this->makeImage(
				self::HOSTILE,
				1600,
				1200,
				array( 'post_excerpt' => self::HOSTILE . '<strong onclick=x()>b</strong>' ),
				array(
					'large' => array( 'file' => 'a"onerror="alert(1).jpg', 'width' => 1024, 'height' => 768, 'mime-type' => 'image/jpeg' ),
					'medium' => array( 'file' => "b b,c.jpg\" srcset=\"//evil.test/z 1w", 'width' => 300, 'height' => 225, 'mime-type' => 'image/jpeg' ),
				),
				self::HOSTILE
			);
		}
		$items = $this->addItems( $gallery, $images );
		foreach ( $items as $item ) {
			Services::items()->saveOverrides( $gallery, $item, new ItemOverrides( self::HOSTILE, self::HOSTILE, self::HOSTILE ) );
		}

		// Everybody sees the public surfaces; the preview is for editors.
		wp_set_current_user( EntryPoint::Preview === $entry ? $this->makeUser( 'administrator' ) : 0 );
		Services::reset();
		$result = Services::gallery_renderer()->render( $gallery, $entry, array( 'per_page' => 2 ) );
		$html   = $result->html;
		$this->assertNotSame( '', $html, 'the gallery rendered' );

		$document = new DOMDocument();
		$document->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>', LIBXML_NOERROR );
		$xpath = new DOMXPath( $document );
		$this->assertSame( 0, $xpath->query( '//script|//svg|//style|//iframe|//object|//embed|//form' )->length, 'no active element: ' . $html );
		foreach ( $xpath->query( '//@*' ) as $attribute ) {
			$name  = strtolower( $attribute->nodeName );
			$value = strtolower( html_entity_decode( $attribute->nodeValue ) );
			$this->assertStringStartsNotWith( 'on', $name, 'event handler attribute ' . $name );
			$this->assertNotContains( $name, array( 'srcdoc', 'formaction' ) );
			if ( in_array( $name, array( 'href', 'src', 'action' ), true ) ) {
				$this->assertDoesNotMatchRegularExpression( '/^\s*(javascript|data|vbscript):/', $value, $name );
			}
			if ( 'style' === $name ) {
				$this->assertDoesNotMatchRegularExpression( '/url\s*\(|expression\s*\(|@import|position\s*:|[<>"]/', $value, 'inline style is whitelisted numbers only: ' . $value );
			}
			if ( 'srcset' === $name ) {
				foreach ( explode( ',', $value ) as $candidate ) {
					$this->assertStringNotContainsString( 'evil.test', $candidate );
				}
			}
		}
		$this->assertStringNotContainsString( 'evil.test', $html );
		// The hostile texts are present only as text/attribute DATA (escaped), never as raw characters.
		$this->assertStringNotContainsString( '<svg', $html );
		$this->assertStringNotContainsString( '<script', $html );
	}
}
