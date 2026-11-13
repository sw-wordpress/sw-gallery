<?php
/**
 * FU-20: the layout stylesheet reaches the block editor (admin only), versioned, from the validated manifest.
 *
 * @package sw-gallery
 */

use SWGallery\Tests\Support\WpFixtures;
use SWGallery\WordPress\Block\BlockRegistrar;
use SWGallery\WordPress\Frontend\FrontendManifest;

class BlockEditorLayoutStyleTest extends SwgTestCase {

	use WpFixtures;

	private string $dir;

	public function set_up(): void {
		parent::set_up();
		$this->dir = sys_get_temp_dir() . '/swg-editor-layout-' . uniqid( '', true );
		mkdir( $this->dir . '/.vite', 0777, true );
		wp_dequeue_style( BlockRegistrar::LAYOUT_STYLE_HANDLE );
		wp_deregister_style( BlockRegistrar::LAYOUT_STYLE_HANDLE );
	}

	public function tear_down(): void {
		set_current_screen( 'front' );
		wp_set_current_user( 0 );
		wp_dequeue_style( BlockRegistrar::LAYOUT_STYLE_HANDLE );
		wp_deregister_style( BlockRegistrar::LAYOUT_STYLE_HANDLE );
		foreach ( glob( $this->dir . '/{,.vite/}*', GLOB_BRACE ) ?: array() as $file ) {
			is_file( $file ) && unlink( $file );
		}
		is_dir( $this->dir . '/.vite' ) && rmdir( $this->dir . '/.vite' );
		is_dir( $this->dir ) && rmdir( $this->dir );
		parent::tear_down();
	}

	private function build( bool $with_layout = true ): BlockRegistrar {
		$manifest = array();
		if ( $with_layout ) {
			file_put_contents( $this->dir . '/layouts-abc123.css', '.swg-grid{}' );
			$manifest[ FrontendManifest::LAYOUT_KEY ] = array( 'file' => 'layouts-abc123.css' );
		}
		file_put_contents( $this->dir . '/.vite/manifest.json', (string) wp_json_encode( $manifest ) );
		return new BlockRegistrar( null, null, null, null, new FrontendManifest( $this->dir ), 'https://example.test/build/' );
	}

	public function test_the_registrar_hooks_the_editor_style_callback(): void {
		$registrar = $this->build();
		$registrar->register();
		$this->assertNotFalse( has_action( 'enqueue_block_assets', array( $registrar, 'enqueue_editor_layout_styles' ) ) );
	}

	public function test_the_layout_stylesheet_is_enqueued_in_the_editor_with_the_plugin_version(): void {
		$registrar = $this->build();
		wp_set_current_user( $this->makeUser( 'author' ) );
		set_current_screen( 'post' ); // Makes is_admin() true like the block editor request.

		$registrar->enqueue_editor_layout_styles();

		$style = wp_styles()->registered[ BlockRegistrar::LAYOUT_STYLE_HANDLE ] ?? null;
		$this->assertNotNull( $style );
		$this->assertSame( 'https://example.test/build/layouts-abc123.css', $style->src );
		$this->assertSame( SWGALLERY_VERSION, $style->ver );
		$this->assertTrue( wp_style_is( BlockRegistrar::LAYOUT_STYLE_HANDLE, 'enqueued' ) );
	}

	public function test_other_admin_screens_do_not_get_the_editor_stylesheet(): void {
		wp_set_current_user( $this->makeUser( 'administrator' ) );
		set_current_screen( 'upload' );
		$this->build()->enqueue_editor_layout_styles();
		$this->assertFalse( wp_style_is( BlockRegistrar::LAYOUT_STYLE_HANDLE, 'enqueued' ) );
	}

	public function test_nothing_is_enqueued_on_the_frontend_without_the_build_or_for_visitors(): void {
		wp_set_current_user( $this->makeUser( 'author' ) );
		set_current_screen( 'front' );
		$this->build()->enqueue_editor_layout_styles();
		$this->assertFalse( wp_style_is( BlockRegistrar::LAYOUT_STYLE_HANDLE, 'enqueued' ), 'Frontend: FrontendAssets decides.' );

		set_current_screen( 'post' );
		$this->build( false )->enqueue_editor_layout_styles();
		$this->assertFalse( wp_style_is( BlockRegistrar::LAYOUT_STYLE_HANDLE, 'enqueued' ), 'Missing build.' );

		wp_set_current_user( 0 );
		$this->build()->enqueue_editor_layout_styles();
		$this->assertFalse( wp_style_is( BlockRegistrar::LAYOUT_STYLE_HANDLE, 'enqueued' ), 'Needs edit_posts.' );
	}

	public function test_a_hostile_manifest_entry_enqueues_nothing(): void {
		file_put_contents( $this->dir . '/.vite/manifest.json', (string) wp_json_encode( array( FrontendManifest::LAYOUT_KEY => array( 'file' => '../../evil.css' ) ) ) );
		wp_set_current_user( $this->makeUser( 'author' ) );
		set_current_screen( 'post' );

		( new BlockRegistrar( null, null, null, null, new FrontendManifest( $this->dir ), 'https://example.test/build' ) )->enqueue_editor_layout_styles();

		$this->assertFalse( wp_style_is( BlockRegistrar::LAYOUT_STYLE_HANDLE, 'enqueued' ) );
	}
}
