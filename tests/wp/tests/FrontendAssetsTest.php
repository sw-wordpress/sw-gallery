<?php
/**
 * Frontend asset loading: only for rendered galleries, versioned, classic deferred lightbox script, the plugin
 * setting, head detection for posts with the shortcode or the block, no loading on admin screens.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Application\Render\RenderAsset;
use SWGallery\Core\Application\Render\RenderResult;
use SWGallery\Core\Domain\Access\RenderAccess;
use SWGallery\WordPress\Frontend\AssetCollector;
use SWGallery\WordPress\Frontend\FrontendAssets;
use SWGallery\WordPress\Frontend\FrontendManifest;
use SWGallery\WordPress\Infrastructure\Services;

class FrontendAssetsTest extends SwgTestCase {

	private string $root;
	private string $build;
	private AssetCollector $collector;

	public function set_up(): void {
		parent::set_up();
		delete_option( 'swg_settings' );
		Services::reset();
		$this->root  = sys_get_temp_dir() . '/swg-assets-' . wp_generate_password( 8, false );
		$this->build = $this->root . '/build';
		mkdir( $this->build . '/.vite', 0777, true );
		$this->collector = new AssetCollector();
		$this->reset_registries();
	}

	public function tear_down(): void {
		foreach ( (array) glob( $this->build . '/{,.vite/}*', GLOB_BRACE ) as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		@rmdir( $this->build . '/.vite' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		@rmdir( $this->build ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		@rmdir( $this->root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$this->reset_registries();
		delete_option( 'swg_settings' );
		wp_set_current_user( 0 );
		Services::reset();
		parent::tear_down();
	}

	private function reset_registries(): void {
		$GLOBALS['wp_scripts'] = new WP_Scripts();
		$GLOBALS['wp_styles']  = new WP_Styles();
		set_current_screen( 'front' );
	}

	private function write_build( bool $with_layout = true ): void {
		$manifest = array(
			FrontendManifest::SCRIPT_KEY => array( 'file' => 'lightbox-abc.js' ),
			FrontendManifest::STYLE_KEY  => array( 'file' => 'lightbox-abc.css' ),
		);
		file_put_contents( $this->build . '/lightbox-abc.js', '/* js */' );
		file_put_contents( $this->build . '/lightbox-abc.css', '/* css */' );
		if ( $with_layout ) {
			$manifest[ FrontendManifest::LAYOUT_KEY ] = array( 'file' => 'layouts-abc.css' );
			file_put_contents( $this->build . '/layouts-abc.css', '/* layouts */' );
		}
		file_put_contents( $this->build . '/.vite/manifest.json', (string) wp_json_encode( $manifest ) );
	}

	private function assets(): FrontendAssets {
		return new FrontendAssets( new FrontendManifest( $this->build ), $this->collector, Services::settings(), 'https://assets.example.test/build/' );
	}

	private function rendered( int $gallery = 5, bool $with_assets = true ): void {
		$this->collector->record(
			$gallery,
			new RenderResult( '<div></div>', RenderAccess::Render, array(), $with_assets ? array( RenderAsset::Lightbox ) : array(), 1, 1, 1, 1, 'grid' )
		);
	}

	public function test_nothing_is_enqueued_without_a_rendered_gallery(): void {
		$this->write_build();
		$assets = $this->assets();
		$assets->on_enqueue_scripts();
		$assets->enqueue_collected();

		$this->assertFalse( wp_script_is( FrontendAssets::HANDLE_SCRIPT, 'enqueued' ) );
		$this->assertFalse( wp_style_is( FrontendAssets::HANDLE_STYLE, 'enqueued' ) );
		$this->assertFalse( wp_style_is( FrontendAssets::HANDLE_LAYOUT, 'enqueued' ) );
	}

	public function test_results_without_assets_are_not_recorded(): void {
		$this->rendered( 5, false );
		$this->assertFalse( $this->collector->has_rendered() );
		$this->assertSame( array(), $this->collector->gallery_ids() );
		$this->collector->record( 0, new RenderResult( '', RenderAccess::Render, array(), array( RenderAsset::Lightbox ) ) );
		$this->assertFalse( $this->collector->has_rendered() );
	}

	public function test_a_rendered_gallery_enqueues_versioned_assets_with_a_classic_deferred_footer_script(): void {
		$this->write_build();
		$this->rendered();
		$this->rendered( 6 );
		$assets = $this->assets();
		$assets->enqueue_collected();

		$this->assertTrue( wp_script_is( FrontendAssets::HANDLE_SCRIPT, 'enqueued' ) );
		$this->assertTrue( wp_style_is( FrontendAssets::HANDLE_STYLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( FrontendAssets::HANDLE_LAYOUT, 'enqueued' ) );
		$this->assertSame( array( 5, 6 ), $this->collector->gallery_ids() );

		foreach ( array( wp_scripts()->registered[ FrontendAssets::HANDLE_SCRIPT ], wp_styles()->registered[ FrontendAssets::HANDLE_STYLE ], wp_styles()->registered[ FrontendAssets::HANDLE_LAYOUT ] ) as $registered ) {
			$this->assertSame( SWGALLERY_VERSION, $registered->ver );
			$this->assertStringStartsWith( 'https://assets.example.test/build/', $registered->src );
		}

		$this->assertSame( 1, wp_scripts()->get_data( FrontendAssets::HANDLE_SCRIPT, 'group' ), 'footer' );
		ob_start();
		wp_scripts()->do_item( FrontendAssets::HANDLE_SCRIPT, 1 );
		$tag = (string) ob_get_clean();
		$this->assertStringContainsString( 'lightbox-abc.js?ver=' . SWGALLERY_VERSION, $tag );
		$this->assertStringContainsString( 'defer', $tag );
		$this->assertStringNotContainsString( 'type="module"', $tag );
	}

	public function test_the_lightbox_assets_follow_the_plugin_setting_but_the_layout_style_does_not(): void {
		$this->write_build();
		Services::settings()->update( array( 'lightbox' => false ) );
		$this->rendered();
		$assets = $this->assets();
		$assets->enqueue_collected();

		$this->assertFalse( wp_script_is( FrontendAssets::HANDLE_SCRIPT, 'enqueued' ) );
		$this->assertFalse( wp_style_is( FrontendAssets::HANDLE_STYLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( FrontendAssets::HANDLE_LAYOUT, 'enqueued' ) );

		$preview = $assets->preview_assets();
		$this->assertSame( array(), $preview['js'] );
		$this->assertCount( 1, $preview['css'] );
	}

	public function test_a_missing_layout_stylesheet_is_skipped_silently(): void {
		$this->write_build( false );
		$this->rendered();
		$this->assets()->enqueue_collected();

		$this->assertFalse( wp_style_is( FrontendAssets::HANDLE_LAYOUT, 'registered' ) );
		$this->assertTrue( wp_style_is( FrontendAssets::HANDLE_STYLE, 'enqueued' ) );
		$this->assertTrue( wp_script_is( FrontendAssets::HANDLE_SCRIPT, 'enqueued' ) );
	}

	public function test_a_missing_build_enqueues_nothing_and_editors_get_a_note_without_paths(): void {
		$this->rendered();
		$assets = $this->assets();
		$assets->enqueue_collected();

		$this->assertFalse( wp_script_is( FrontendAssets::HANDLE_SCRIPT, 'enqueued' ) );
		$this->assertFalse( wp_style_is( FrontendAssets::HANDLE_STYLE, 'enqueued' ) );

		wp_set_current_user( 0 );
		ob_start();
		$assets->print_build_notice();
		$this->assertSame( '', ob_get_clean(), 'Visitors never see the note.' );

		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'editor' ) ) );
		ob_start();
		$assets->print_build_notice();
		$note = (string) ob_get_clean();
		$this->assertStringContainsString( 'swg-diagnostic', $note );
		$this->assertStringNotContainsString( $this->root, $note );
	}

	public function test_no_note_when_the_build_is_fine_or_nothing_was_rendered(): void {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'editor' ) ) );
		$assets = $this->assets();
		ob_start();
		$assets->print_build_notice();
		$this->assertSame( '', ob_get_clean(), 'nothing rendered' );

		$this->write_build();
		$this->rendered();
		ob_start();
		$this->assets()->print_build_notice();
		$this->assertSame( '', ob_get_clean(), 'usable build' );
	}

	public function test_nothing_loads_on_admin_screens(): void {
		$this->write_build();
		$this->rendered();
		set_current_screen( 'dashboard' );
		$assets = $this->assets();
		$assets->on_enqueue_scripts();
		$assets->enqueue_collected();

		$this->assertFalse( wp_script_is( FrontendAssets::HANDLE_SCRIPT, 'enqueued' ) );
		$this->assertFalse( wp_style_is( FrontendAssets::HANDLE_STYLE, 'enqueued' ) );
		$this->assertFalse( wp_style_is( FrontendAssets::HANDLE_LAYOUT, 'registered' ) );
	}

	/**
	 * @return iterable<string, array{string, bool}>
	 */
	public static function contents(): iterable {
		yield 'shortcode' => array( 'Intro [sw_gallery id="3"] outro', true );
		yield 'shortcode with attributes' => array( '[sw_gallery id="3" columns="2"]', true );
		yield 'block' => array( '<!-- wp:sw-gallery/gallery {"galleryId":3} /-->', true );
		yield 'plain text' => array( 'No gallery here.', false );
		yield 'foreign shortcode' => array( '[gallery ids="1,2"]', false );
		yield 'other block' => array( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->', false );
	}

	/**
	 * @dataProvider contents
	 */
	public function test_styles_are_enqueued_early_only_for_posts_containing_the_embed( string $content, bool $expected ): void {
		$this->write_build();
		( new SWGallery\WordPress\Shortcode\ShortcodeRegistrar() )->register();
		$post = self::factory()->post->create( array( 'post_content' => $content ) );
		$this->go_to( get_permalink( $post ) );
		$this->reset_registries_keep_query();

		$assets = $this->assets();
		$assets->on_enqueue_scripts();

		$this->assertSame( $expected, wp_style_is( FrontendAssets::HANDLE_LAYOUT, 'enqueued' ) );
		$this->assertSame( $expected, wp_style_is( FrontendAssets::HANDLE_STYLE, 'enqueued' ) );
		$this->assertFalse( wp_script_is( FrontendAssets::HANDLE_SCRIPT, 'enqueued' ), 'The script waits for a rendered gallery.' );
		$this->assertTrue( wp_script_is( FrontendAssets::HANDLE_SCRIPT, 'registered' ) );
	}

	private function reset_registries_keep_query(): void {
		$GLOBALS['wp_scripts'] = new WP_Scripts();
		$GLOBALS['wp_styles']  = new WP_Styles();
	}

	public function test_feeds_are_skipped(): void {
		$this->write_build();
		$post = self::factory()->post->create( array( 'post_content' => '[sw_gallery id="3"]' ) );
		$this->go_to( get_permalink( $post ) );
		$GLOBALS['wp_query']->is_feed = true;
		$this->reset_registries_keep_query();

		$this->assets()->on_enqueue_scripts();
		$this->assertFalse( wp_style_is( FrontendAssets::HANDLE_LAYOUT, 'registered' ) );
	}

	public function test_preview_assets_carry_the_version_and_are_empty_without_a_build(): void {
		$empty = $this->assets()->preview_assets();
		$this->assertSame( array(), $empty['css'] );
		$this->assertSame( array(), $empty['js'] );

		$this->write_build();
		$preview = $this->assets()->preview_assets();
		$this->assertSame(
			array(
				'https://assets.example.test/build/layouts-abc.css?ver=' . SWGALLERY_VERSION,
				'https://assets.example.test/build/lightbox-abc.css?ver=' . SWGALLERY_VERSION,
			),
			$preview['css']
		);
		$this->assertSame( array( 'https://assets.example.test/build/lightbox-abc.js?ver=' . SWGALLERY_VERSION ), $preview['js'] );
	}

	public function test_the_registrar_hooks_are_in_place(): void {
		$registrar = new SWGallery\WordPress\Frontend\FrontendRegistrar();
		$registrar->register();
		$this->assertSame( 1, has_action( 'wp_print_footer_scripts', array( $registrar, 'enqueue_collected' ) ) );
		$this->assertSame( 5, has_action( 'wp_enqueue_scripts', array( $registrar, 'enqueue_scripts' ) ) );
		$this->assertSame( 90, has_action( 'admin_bar_menu', array( $registrar, 'admin_bar' ) ) );
		foreach ( array( 'enqueue_scripts', 'enqueue_collected', 'print_build_notice' ) as $method ) {
			$registrar->$method();
		}
		$this->assertFalse( wp_script_is( FrontendAssets::HANDLE_SCRIPT, 'enqueued' ), 'The real build is absent and nothing was rendered.' );
	}
}
