<?php
/**
 * The [sw_gallery] shortcode: strict attributes, hostile values, the access matrix, nesting and output identity with
 * the PHP API and the renderer.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Application\Render\EntryPoint;
use SWGallery\Tests\Support\RenderFixtures;
use SWGallery\WordPress\Infrastructure\Services;
use SWGallery\WordPress\Shortcode\ShortcodeRegistrar;

class ShortcodeTest extends SwgTestCase {

	use RenderFixtures;

	private int $author;
	private int $other_author;
	private int $editor;
	private int $admin;

	public function set_up(): void {
		parent::set_up();
		$this->purgeGalleries();
		Services::reset();
		( new ShortcodeRegistrar() )->register();
		$this->author       = $this->makeUser( 'author' );
		$this->other_author = $this->makeUser( 'author' );
		$this->editor       = $this->makeUser( 'editor' );
		$this->admin        = $this->makeUser( 'administrator' );
		$this->lockPassword();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->lockPassword();
		$this->purgeGalleries();
		remove_shortcode( 'swg_wrap' );
		Services::reset();
		parent::tear_down();
	}

	private function gallery_with_images( int $count = 3, string $status = 'publish', string $password = '' ): int {
		$gallery = $this->makeGalleryPost( $this->author, $status, $password );
		$ids     = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$ids[] = $this->makeImage( "Image $i", 1600, 1200, array(), null, "Alt $i" );
		}
		$this->addItems( $gallery, $ids );
		return $gallery;
	}

	private function shortcode( string $text, int $user = 0 ): string {
		wp_set_current_user( $user );
		Services::reset();
		return do_shortcode( $text );
	}

	public function test_a_valid_shortcode_returns_the_gallery_and_never_echoes(): void {
		$id = $this->gallery_with_images();
		wp_set_current_user( 0 );
		Services::reset();

		ob_start();
		$html   = do_shortcode( '[sw_gallery id="' . $id . '"]' );
		$echoed = ob_get_clean();

		$this->assertSame( '', $echoed );
		$this->assertStringContainsString( 'data-swg-gallery', $html );
		$this->assertSame( 3, substr_count( $html, '<img' ) );
		$this->assertTrue( shortcode_exists( 'sw_gallery' ) );
		$this->assertSame( array( $id ), Services::asset_collector()->gallery_ids() );
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function invalid_ids(): iterable {
		yield 'missing' => array( '[sw_gallery]' );
		yield 'empty' => array( '[sw_gallery id=""]' );
		yield 'zero' => array( '[sw_gallery id="0"]' );
		yield 'negative' => array( '[sw_gallery id="-4"]' );
		yield 'float' => array( '[sw_gallery id="1.5"]' );
		yield 'text' => array( '[sw_gallery id="abc"]' );
		yield 'leading zero' => array( '[sw_gallery id="007"]' );
		yield 'too long' => array( '[sw_gallery id="99999999999999"]' );
		yield 'list' => array( '[sw_gallery id="1,2"]' );
		yield 'sql' => array( '[sw_gallery id="1 OR 1=1"]' );
		yield 'xss' => array( '[sw_gallery id=\'"><script>alert(1)</script>\']' );
		yield 'positional' => array( '[sw_gallery 5]' );
	}

	/**
	 * @dataProvider invalid_ids
	 */
	public function test_an_invalid_id_renders_nothing_for_visitors_and_a_note_for_editors( string $text ): void {
		$this->assertSame( '', $this->shortcode( $text, 0 ), 'visitor' );
		$this->assertSame( '', $this->shortcode( $text, $this->makeUser( 'subscriber' ) ), 'subscriber' );

		$note = $this->shortcode( $text, $this->author );
		$this->assertStringContainsString( 'swg-diagnostic', $note );
		$this->assertStringNotContainsString( '<script', $note );
		$this->assertStringNotContainsString( 'OR 1=1', $note, 'The raw value is never echoed.' );
		$this->assertSame( array(), Services::asset_collector()->gallery_ids() );
	}

	public function test_a_missing_gallery_renders_nothing_for_visitors(): void {
		$this->assertSame( '', $this->shortcode( '[sw_gallery id="9999999"]' ) );
		$this->assertStringContainsString( 'swg-diagnostic', $this->shortcode( '[sw_gallery id="9999999"]', $this->editor ) );
	}

	public function test_whitespace_around_a_valid_id_is_tolerated(): void {
		$id = $this->gallery_with_images( 1 );
		$this->assertStringContainsString( 'data-swg-gallery', $this->shortcode( '[sw_gallery id=" ' . $id . ' "]' ) );
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function dropped_overrides(): iterable {
		yield 'unknown layout' => array( 'layout="doesnotexist"' );
		yield 'layout xss' => array( 'layout=\'"><script>alert(1)</script>\'' );
		yield 'layout uppercase' => array( 'layout="GRID"' );
		yield 'columns zero' => array( 'columns="0"' );
		yield 'columns too many' => array( 'columns="13"' );
		yield 'columns text' => array( 'columns="four"' );
		yield 'columns float' => array( 'columns="2.5"' );
		yield 'columns css' => array( 'columns="3;background:url(x)"' );
		yield 'columns xss' => array( 'columns=\'"><img src=x onerror=alert(1)>\'' );
		yield 'gap negative' => array( 'gap="-1"' );
		yield 'gap too big' => array( 'gap="65"' );
		yield 'gap unit' => array( 'gap="8px"' );
		yield 'page zero' => array( 'page="0"' );
		yield 'page text' => array( 'page="next"' );
		yield 'unknown attribute' => array( 'onclick="alert(1)" class="evil" per_page="999" eager="0" style="x:y"' );
	}

	/**
	 * @dataProvider dropped_overrides
	 */
	public function test_invalid_overrides_are_dropped_and_unknown_attributes_ignored( string $attributes ): void {
		$id       = $this->gallery_with_images();
		$baseline = $this->shortcode( '[sw_gallery id="' . $id . '"]' );
		$html     = $this->shortcode( '[sw_gallery id="' . $id . '" ' . $attributes . ']' );

		$this->assertSame( $baseline, $html );
		$this->assertStringNotContainsString( 'alert(1)', $html );
		$this->assertStringNotContainsString( 'onclick', $html );
		$this->assertStringNotContainsString( 'swg-diagnostic', $html, 'Dropped values raise no diagnostic.' );
	}

	public function test_valid_overrides_reach_the_renderer(): void {
		$id   = $this->gallery_with_images();
		$html = $this->shortcode( '[sw_gallery id="' . $id . '" layout="grid" columns="5" gap="12"]' );

		$this->assertStringContainsString( '--swg-columns:5;', $html );
		$this->assertStringContainsString( '--swg-gap:12px', $html );
		$this->assertSame( $this->renderer_html( $id, array( 'layout' => 'grid', 'settings' => array( 'columns' => 5, 'gap' => 12 ) ) ), $html );
	}

	public function test_the_page_comes_from_the_request_argument_and_hostile_values_are_ignored(): void {
		$id      = $this->gallery_with_images( 5 );
		$default = $this->shortcode( '[sw_gallery id="' . $id . '"]' );

		foreach ( array( 'abc', '-1', '0', '2;DROP', '99999999999999999999' ) as $hostile ) {
			$_GET[ 'swg_page_' . $id ] = $hostile;
			$this->assertSame( $default, $this->shortcode( '[sw_gallery id="' . $id . '"]' ), $hostile );
		}
		unset( $_GET[ 'swg_page_' . $id ] );
	}

	/**
	 * @return iterable<string, array{string, string, string, string}>
	 */
	public static function access_matrix(): iterable {
		// status, password, user key, expected: render | form | diagnostic | nothing.
		$editors = array( 'author', 'editor', 'admin' );
		foreach ( array( 'visitor', 'author', 'other', 'editor', 'admin' ) as $user ) {
			yield "publish $user" => array( 'publish', '', $user, 'render' );
			yield "publish password $user" => array( 'publish', 'secret', $user, 'form' );
			yield "draft $user" => array( 'draft', '', $user, in_array( $user, $editors, true ) ? 'diagnostic' : 'nothing' );
			yield "pending $user" => array( 'pending', '', $user, in_array( $user, $editors, true ) ? 'diagnostic' : 'nothing' );
			yield "trash $user" => array( 'trash', '', $user, in_array( $user, $editors, true ) ? 'diagnostic' : 'nothing' );
			yield "private $user" => array( 'private', '', $user, in_array( $user, $editors, true ) ? 'render' : 'nothing' );
		}
	}

	/**
	 * @dataProvider access_matrix
	 */
	public function test_the_access_matrix( string $status, string $password, string $user, string $expected ): void {
		$ids  = array(
			'visitor' => 0,
			'author'  => $this->author,
			'other'   => $this->other_author,
			'editor'  => $this->editor,
			'admin'   => $this->admin,
		);
		$id   = $this->gallery_with_images( 2, $status, $password );
		$html = $this->shortcode( '[sw_gallery id="' . $id . '"]', $ids[ $user ] );

		$label = "$status/$password/$user";
		switch ( $expected ) {
			case 'render':
				$this->assertStringContainsString( 'data-swg-gallery', $html, $label );
				break;
			case 'form':
				$this->assertStringContainsString( 'post_password', $html, $label );
				$this->assertStringNotContainsString( '<img', $html, $label );
				break;
			case 'diagnostic':
				$this->assertStringContainsString( 'swg-diagnostic', $html, $label );
				$this->assertStringNotContainsString( '<img', $html, $label );
				break;
			default:
				$this->assertSame( '', $html, $label );
		}
		$this->assertSame( 'render' === $expected ? array( $id ) : array(), Services::asset_collector()->gallery_ids(), $label );
	}

	public function test_an_unlocked_password_renders_the_gallery(): void {
		$id = $this->gallery_with_images( 2, 'publish', 'secret' );
		$this->unlockPassword( 'secret' );

		$this->assertStringContainsString( 'data-swg-gallery', $this->shortcode( '[sw_gallery id="' . $id . '"]' ) );
	}

	public function test_it_nests_in_other_shortcodes_and_ignores_enclosed_content(): void {
		add_shortcode(
			'swg_wrap',
			static fn( $atts, $content = '' ): string => '<section>' . do_shortcode( (string) $content ) . '</section>'
		);
		$id = $this->gallery_with_images( 1 );

		ob_start();
		$html   = $this->shortcode( '[swg_wrap]before [sw_gallery id="' . $id . '"]<b>ignored</b>[/sw_gallery] after[/swg_wrap]' );
		$echoed = ob_get_clean();

		$this->assertSame( '', $echoed );
		$this->assertMatchesRegularExpression( '#^<section>before .*data-swg-gallery.* after</section>$#s', $html );

		$twice = $this->shortcode( '[sw_gallery id="' . $id . '"][sw_gallery id="' . $id . '"]' );
		$this->assertSame( 1, substr_count( $twice, 'data-swg-instance="swg-' . $id . '-1"' ) );
		$this->assertSame( 1, substr_count( $twice, 'data-swg-instance="swg-' . $id . '-2"' ) );
	}

	public function test_it_works_in_block_content_and_widget_text(): void {
		$id = $this->gallery_with_images( 1 );
		wp_set_current_user( 0 );
		Services::reset();

		$block = do_blocks( '<!-- wp:shortcode -->[sw_gallery id="' . $id . '"]<!-- /wp:shortcode -->' );
		$this->assertStringContainsString( 'data-swg-gallery', do_shortcode( $block ) );

		Services::reset();
		$this->assertStringContainsString( 'data-swg-gallery', do_shortcode( apply_filters( 'widget_text', '[sw_gallery id="' . $id . '"]' ) ) );
	}

	public function test_feeds_get_the_plain_markup_without_assets(): void {
		$id = $this->gallery_with_images( 2 );
		wp_set_current_user( 0 );
		Services::reset();
		$GLOBALS['wp_query']->is_feed = true;

		$html = do_shortcode( '[sw_gallery id="' . $id . '"]' );
		$GLOBALS['wp_query']->is_feed = false;

		$this->assertStringNotContainsString( 'data-swg-gallery', $html );
		$this->assertStringContainsString( '<img', $html );
		$this->assertSame( array(), Services::asset_collector()->gallery_ids() );
	}

	public function test_shortcode_php_api_and_renderer_produce_the_same_markup(): void {
		$id      = $this->gallery_with_images( 4 );
		$options = array(
			'layout'   => 'grid',
			'settings' => array(
				'columns' => 4,
				'gap'     => 10,
			),
		);

		$by_shortcode = $this->shortcode( '[sw_gallery id="' . $id . '" layout="grid" columns="4" gap="10"]' );
		Services::reset();
		$by_api = swg_render_gallery( $id, array( 'layout' => 'grid', 'columns' => 4, 'gap' => 10 ) );

		$this->assertNotSame( '', $by_shortcode );
		$this->assertSame( $by_shortcode, $by_api );
		$this->assertSame( $by_shortcode, $this->renderer_html( $id, $options ) );
	}

	public function test_two_embeds_in_one_request_differ_only_in_the_instance_counter(): void {
		$id = $this->gallery_with_images( 3 );
		wp_set_current_user( 0 );
		Services::reset();

		$first  = do_shortcode( '[sw_gallery id="' . $id . '"]' );
		$second = swg_render_gallery( $id );

		$this->assertNotSame( $first, $second );
		$normalised = str_replace( 'swg-' . $id . '-2', 'swg-' . $id . '-1', $second );
		// Only the first embed of a request may carry fetchpriority="high" (LCP hint).
		$this->assertSame( str_replace( ' fetchpriority="high"', '', $first ), $normalised );
		$this->assertStringContainsString( 'fetchpriority="high"', $first );
		$this->assertStringNotContainsString( 'fetchpriority="high"', $second );
	}

	/**
	 * @param array<string, mixed> $options Renderer options.
	 */
	private function renderer_html( int $id, array $options ): string {
		wp_set_current_user( 0 );
		Services::reset();
		return Services::gallery_renderer()->html( $id, EntryPoint::Shortcode, $options );
	}
}
