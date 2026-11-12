<?php
/**
 * The dynamic block: registration, server render equals the one renderer, visibility, wrapper attributes and the
 * versioned editor assets.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Application\Render\EntryPoint;
use SWGallery\Tests\Support\RenderFixtures;
use SWGallery\WordPress\Block\BlockRegistrar;
use SWGallery\WordPress\Block\EditorAssets;
use SWGallery\WordPress\Infrastructure\Services;

class BlockTest extends SwgTestCase {

	use RenderFixtures;

	private int $author;
	private int $editor;
	private string $dir;

	public function set_up(): void {
		parent::set_up();
		$this->purgeGalleries();
		Services::reset();
		$this->author = $this->makeUser( 'author' );
		$this->editor = $this->makeUser( 'editor' );
		$this->lockPassword();
		$this->dir = sys_get_temp_dir() . '/swg-block-' . uniqid( '', true );
		mkdir( $this->dir . '/build', 0777, true );
		copy( SWGALLERY_DIR . 'assets/blocks/block.json', $this->dir . '/block.json' );
		$this->reset_registration();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->lockPassword();
		$this->purgeGalleries();
		Services::reset();
		$this->reset_registration();
		$this->remove_dir( $this->dir );
		( new BlockRegistrar() )->register_block();
		remove_all_filters( 'locale' );
		unset( $GLOBALS['current_screen'] );
		parent::tear_down();
	}

	private function reset_registration(): void {
		$registry = WP_Block_Type_Registry::get_instance();
		if ( $registry->is_registered( BlockRegistrar::BLOCK_NAME ) ) {
			unregister_block_type( BlockRegistrar::BLOCK_NAME );
		}
		wp_dequeue_script( BlockRegistrar::EDITOR_HANDLE );
		wp_deregister_script( BlockRegistrar::EDITOR_HANDLE );
		wp_dequeue_style( BlockRegistrar::EDITOR_HANDLE );
		wp_deregister_style( BlockRegistrar::EDITOR_HANDLE );
	}

	private function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $entry ) {
			$entry->isDir() && ! $entry->isLink() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
		}
		rmdir( $dir );
	}

	private function write_build( bool $with_style = true ): void {
		file_put_contents( $this->dir . '/build/index.js', '(function(){})();' );
		if ( $with_style ) {
			file_put_contents( $this->dir . '/build/index.css', '.swg-block{}' );
		}
	}

	private function register( ?callable $preview = null ): BlockRegistrar {
		$registrar = new BlockRegistrar( $this->dir, 'https://example.test/blocks/', SWGALLERY_DIR . 'languages', $preview );
		$registrar->register_block();
		return $registrar;
	}

	private function block_type(): WP_Block_Type {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( BlockRegistrar::BLOCK_NAME );
		$this->assertInstanceOf( WP_Block_Type::class, $type );
		return $type;
	}

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render_block_html( array $attributes ): string {
		return render_block(
			array(
				'blockName'    => BlockRegistrar::BLOCK_NAME,
				'attrs'        => $attributes,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
	}

	private function gallery( string $status = 'publish', string $password = '', int $images = 2 ): int {
		$gallery = $this->makeGalleryPost( $this->author, $status, $password );
		$ids     = array();
		for ( $i = 1; $i <= $images; $i++ ) {
			$ids[] = $this->makeImage( "Image $i", 1600, 1200, array(), null, "Alt $i" );
		}
		$this->addItems( $gallery, $ids );
		return $gallery;
	}

	/**
	 * The renderer's own output for the same options, from a fresh request state so the instance counter matches.
	 *
	 * @param array<string, mixed> $options Render options.
	 */
	private function direct_html( int $gallery, array $options = array() ): string {
		Services::reset();
		return Services::gallery_renderer()->html( $gallery, EntryPoint::Block, $options );
	}

	/** Strips the block wrapper (one div with the block's attributes) and returns its content. */
	private function unwrap( string $html ): string {
		$this->assertMatchesRegularExpression( '#^<div [^>]*class="[^"]*wp-block-sw-gallery-gallery[^"]*"[^>]*>#', $html );
		$this->assertStringEndsWith( '</div>', $html );
		return (string) preg_replace( '#^<div [^>]*>#', '', substr( $html, 0, -6 ), 1 );
	}

	public function test_the_plugin_registers_the_block_on_init(): void {
		$this->reset_registration();
		( new BlockRegistrar() )->register();
		do_action( 'init' );
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( BlockRegistrar::BLOCK_NAME ) );
		$this->assertContains( 'BlockRegistrar', array_map( static fn ( $r ): string => ( new ReflectionClass( $r ) )->getShortName(), \SWGallery\WordPress\Bootstrap\Registrars::all() ) );
	}

	public function test_the_block_metadata_matches_the_contract(): void {
		$this->register();
		$type = $this->block_type();

		$this->assertSame( 3, $type->api_version );
		$this->assertSame( 'media', $type->category );
		$this->assertTrue( $type->is_dynamic() );
		$this->assertNotEmpty( $type->title );
		$this->assertNotEmpty( $type->description );
		$this->assertSame( array( 'gallery', 'images', 'photos' ), $type->keywords );

		$this->assertSame( array( 'wide', 'full' ), $type->supports['align'] );
		$this->assertTrue( $type->supports['spacing']['margin'] );
		$this->assertFalse( $type->supports['html'] );
		$this->assertTrue( $type->supports['anchor'] );

		$own = array_diff( array_keys( $type->attributes ), array( 'align', 'anchor', 'className', 'style', 'lock', 'metadata' ) );
		$this->assertEqualsCanonicalizing( array( 'galleryId', 'layout', 'columns', 'gap', 'eager' ), $own, 'no media ids or other attributes' );
		$this->assertSame(
			array(
				'type'    => 'integer',
				'default' => 0,
			),
			$type->attributes['galleryId']
		);
		$this->assertSame( 'integer', $type->attributes['columns']['type'] );
		$this->assertSame( 12, $type->attributes['columns']['maximum'] );
		$this->assertSame( 1, $type->attributes['columns']['minimum'] );
		$this->assertSame( 64, $type->attributes['gap']['maximum'] );
		$this->assertSame( 0, $type->attributes['gap']['minimum'] );
		$this->assertSame( 'boolean', $type->attributes['eager']['type'] );
		$this->assertSame( 'string', $type->attributes['layout']['type'] );
	}

	public function test_registering_twice_is_harmless(): void {
		$this->register();
		$this->register();
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( BlockRegistrar::BLOCK_NAME ) );
	}

	public function test_the_output_equals_the_renderer_output_for_the_same_options(): void {
		$this->register();
		$gallery = $this->gallery( 'publish', '', 3 );
		wp_set_current_user( 0 );

		$cases = array(
			'plain'     => array( array( 'galleryId' => $gallery ), array() ),
			'overrides' => array(
				array(
					'galleryId' => $gallery,
					'layout'    => 'grid',
					'columns'   => 2,
					'gap'       => 12,
					'eager'     => false,
				),
				array(
					'layout'   => 'grid',
					'settings' => array(
						'columns' => 2,
						'gap'     => 12,
					),
					'eager'    => false,
				),
			),
			'hostile'   => array(
				array(
					'galleryId' => (string) $gallery,
					'layout'    => '"><script>',
					'columns'   => 99,
					'gap'       => '8px;x',
				),
				array(),
			),
		);
		foreach ( $cases as $label => [ $attributes, $options ] ) {
			Services::reset();
			$block  = $this->render_block_html( $attributes );
			$direct = $this->direct_html( $gallery, $options );
			$this->assertStringContainsString( '<img', $direct, $label );
			$this->assertSame( $direct, $this->unwrap( $block ), $label );
			$this->assertStringNotContainsString( '<script>', $block, $label );
		}
	}

	public function test_a_second_embed_gets_the_next_instance_like_any_other_surface(): void {
		$this->register();
		$gallery = $this->gallery();
		Services::reset();
		$first  = $this->render_block_html( array( 'galleryId' => $gallery ) );
		$second = $this->render_block_html( array( 'galleryId' => $gallery ) );
		$this->assertStringContainsString( "swg-$gallery-1", $first );
		$this->assertStringContainsString( "swg-$gallery-2", $second );
		// Only the first embed of a request gets fetchpriority=high (Rendering decision); the rest is identical.
		$this->assertSame( str_replace( ' fetchpriority="high"', '', $this->unwrap( $first ) ), str_replace( "swg-$gallery-2", "swg-$gallery-1", $this->unwrap( $second ) ) );
	}

	/**
	 * @return iterable<string, array{string, string, string, string}>
	 */
	public static function visibility(): iterable {
		// status, password, user, expectation: render | password | diagnostic | nothing.
		foreach ( array( 'visitor', 'author', 'editor' ) as $user ) {
			$sees = 'visitor' === $user ? 'nothing' : 'render';
			$note = 'visitor' === $user ? 'nothing' : 'diagnostic';
			yield "publish $user" => array( 'publish', '', $user, 'render' );
			yield "private $user" => array( 'private', '', $user, $sees );
			yield "draft $user" => array( 'draft', '', $user, $note );
			yield "password $user" => array( 'publish', 'secret', $user, 'password' );
		}
	}

	/**
	 * @dataProvider visibility
	 */
	public function test_the_renderer_decides_who_sees_the_gallery( string $status, string $password, string $user, string $expected ): void {
		$this->register();
		$gallery = $this->gallery( $status, $password );
		$ids     = array(
			'visitor' => 0,
			'author'  => $this->author,
			'editor'  => $this->editor,
		);
		wp_set_current_user( $ids[ $user ] );

		Services::reset();
		$block  = $this->render_block_html( array( 'galleryId' => $gallery ) );
		$direct = $this->direct_html( $gallery );
		$label  = "$status/$password/$user";

		$this->assertSame( $direct, '' === $block ? '' : $this->unwrap( $block ), $label );
		switch ( $expected ) {
			case 'render':
				$this->assertStringContainsString( '<img', $block, $label );
				break;
			case 'password':
				$this->assertStringContainsString( 'post_password', $block, $label );
				$this->assertStringNotContainsString( '<img', $block, $label );
				break;
			case 'diagnostic':
				$this->assertNotSame( '', $block, $label );
				$this->assertStringNotContainsString( '<img', $block, $label );
				break;
			default:
				$this->assertSame( '', $block, $label );
		}
	}

	public function test_nothing_is_rendered_without_a_usable_gallery(): void {
		$this->register();
		wp_set_current_user( 0 );
		foreach ( array( array(), array( 'galleryId' => 0 ), array( 'galleryId' => -3 ), array( 'galleryId' => 'abc' ), array( 'galleryId' => 999999 ) ) as $attributes ) {
			$this->assertSame( '', $this->render_block_html( $attributes ), (string) wp_json_encode( $attributes ) );
		}
	}

	public function test_editors_get_a_hint_only_in_the_editor_preview(): void {
		$this->register( static fn (): bool => true );
		wp_set_current_user( $this->editor );
		$hint = $this->render_block_html( array() );
		$this->assertStringContainsString( 'swg-block-hint', $hint );
		$this->assertStringContainsString( 'No gallery selected', $hint );

		wp_set_current_user( 0 );
		$this->assertSame( '', $this->render_block_html( array() ), 'visitors never see the hint' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( '', $this->render_block_html( array() ), 'users without edit rights never see the hint' );
	}

	public function test_no_hint_outside_the_editor_preview(): void {
		$this->register( static fn (): bool => false );
		wp_set_current_user( $this->editor );
		$this->assertSame( '', $this->render_block_html( array() ) );
	}

	public function test_the_default_preview_check_reads_the_rest_request_flag(): void {
		$this->register();
		wp_set_current_user( $this->editor );
		$expects_hint = defined( 'REST_REQUEST' ) && REST_REQUEST;
		$this->assertSame( $expects_hint, '' !== $this->render_block_html( array() ) );
	}

	public function test_wrapper_attributes_carry_alignment_class_anchor_and_margin(): void {
		$this->register();
		$gallery = $this->gallery();
		$html    = $this->render_block_html(
			array(
				'galleryId' => $gallery,
				'align'     => 'wide',
				'className' => 'my-extra',
				'anchor'    => 'pictures',
				'style'     => array( 'spacing' => array( 'margin' => array( 'top' => '10px' ) ) ),
			)
		);
		$this->assertMatchesRegularExpression( '#^<div [^>]*class="[^"]*wp-block-sw-gallery-gallery[^"]*"#', $html );
		$this->assertStringContainsString( 'alignwide', $html );
		$this->assertStringContainsString( 'my-extra', $html );
		$this->assertStringContainsString( 'id="pictures"', $html );
		$this->assertStringContainsString( 'margin-top:10px', $html );
	}

	public function test_a_hostile_class_or_anchor_is_escaped_by_the_wrapper(): void {
		$this->register();
		$html = $this->render_block_html(
			array(
				'galleryId' => $this->gallery(),
				'className' => '"><script>alert(1)</script>',
				'anchor'    => '"><img src=x onerror=alert(1)>',
			)
		);
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
	}

	public function test_the_block_renders_through_the_content_pipeline(): void {
		$this->register();
		$gallery = $this->gallery();
		$content = do_blocks( '<!-- wp:sw-gallery/gallery {"galleryId":' . $gallery . ',"align":"full","columns":3} /-->' );
		$this->assertStringContainsString( 'alignfull', $content );
		$this->assertStringContainsString( '<img', $content );
	}

	public function test_editor_assets_use_the_plugin_version_explicit_dependencies_and_translations(): void {
		$this->write_build();
		$this->register();

		$script = wp_scripts()->registered[ BlockRegistrar::EDITOR_HANDLE ] ?? null;
		$this->assertNotNull( $script );
		$this->assertSame( SWGALLERY_VERSION, $script->ver );
		$this->assertSame( 'https://example.test/blocks/build/index.js', $script->src );
		$this->assertEqualsCanonicalizing(
			array( 'wp-api-fetch', 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render' ),
			$script->deps
		);
		$this->assertTrue( (bool) ( $script->extra['group'] ?? 0 ), 'loaded in the footer' );
		$this->assertSame( 'sw-gallery', $script->textdomain );
		$this->assertSame( SWGALLERY_DIR . 'languages', $script->translations_path );

		$style = wp_styles()->registered[ BlockRegistrar::EDITOR_HANDLE ] ?? null;
		$this->assertNotNull( $style );
		$this->assertSame( SWGALLERY_VERSION, $style->ver );
		$this->assertSame( 'https://example.test/blocks/build/index.css', $style->src );
		$this->assertSame( array( 'wp-components' ), $style->deps );

		$inline = wp_scripts()->get_data( BlockRegistrar::EDITOR_HANDLE, 'before' );
		$this->assertIsArray( $inline );
		$this->assertStringContainsString( 'window.swgBlock = {"galleriesUrl":', implode( '', $inline ) );
		$this->assertStringContainsString( 'page=swg-galleries', implode( '', $inline ) );
		$this->assertStringNotContainsString( 'nonce', strtolower( implode( '', $inline ) ) );
	}

	public function test_the_editor_script_loads_in_the_block_editor_only(): void {
		$this->write_build();
		$this->register();

		do_action( 'wp_enqueue_scripts' );
		$this->assertFalse( wp_script_is( BlockRegistrar::EDITOR_HANDLE, 'enqueued' ), 'not on the frontend' );
		remove_all_actions( 'admin_enqueue_scripts' ); // Unrelated admin callbacks need a current screen the test does not have.
		add_action( 'admin_enqueue_scripts', 'wp_enqueue_registered_block_scripts_and_styles' );
		do_action( 'admin_enqueue_scripts', 'index.php' );
		$this->assertFalse( wp_script_is( BlockRegistrar::EDITOR_HANDLE, 'enqueued' ), 'not on other admin screens' );

		// Core hooks this function to the editor action; unrelated admin callbacks are not part of this test.
		set_current_screen( 'post' );
		get_current_screen()->is_block_editor( true );
		remove_all_actions( 'enqueue_block_editor_assets' );
		add_action( 'enqueue_block_editor_assets', 'wp_enqueue_registered_block_scripts_and_styles' );
		do_action( 'enqueue_block_editor_assets' );
		$this->assertTrue( wp_script_is( BlockRegistrar::EDITOR_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( BlockRegistrar::EDITOR_HANDLE, 'enqueued' ) );
	}

	public function test_without_a_build_the_block_still_renders_and_no_editor_handles_exist(): void {
		$this->register();
		$this->assertArrayNotHasKey( BlockRegistrar::EDITOR_HANDLE, wp_scripts()->registered );
		$this->assertArrayNotHasKey( BlockRegistrar::EDITOR_HANDLE, wp_styles()->registered );
		$gallery = $this->gallery();
		wp_set_current_user( 0 );
		$this->assertStringContainsString( '<img', $this->render_block_html( array( 'galleryId' => $gallery ) ) );
		remove_all_actions( 'enqueue_block_editor_assets' );
		add_action( 'enqueue_block_editor_assets', 'wp_enqueue_registered_block_scripts_and_styles' );
		do_action( 'enqueue_block_editor_assets' );
		$this->assertFalse( wp_script_is( BlockRegistrar::EDITOR_HANDLE, 'enqueued' ) );
	}

	public function test_a_build_without_stylesheet_registers_only_the_script(): void {
		$this->write_build( false );
		$this->register();
		$this->assertArrayHasKey( BlockRegistrar::EDITOR_HANDLE, wp_scripts()->registered );
		$this->assertArrayNotHasKey( BlockRegistrar::EDITOR_HANDLE, wp_styles()->registered );
	}

	public function test_editor_assets_ignore_symlinks_and_directories_outside_the_build(): void {
		$outside = sys_get_temp_dir() . '/swg-outside-' . uniqid( '', true ) . '.js';
		file_put_contents( $outside, 'alert(1)' );
		symlink( $outside, $this->dir . '/build/index.js' );
		$this->assertNull( ( new EditorAssets( $this->dir . '/build' ) )->resolve(), 'symlink out of the build directory' );
		unlink( $this->dir . '/build/index.js' );
		unlink( $outside );

		mkdir( $this->dir . '/build/index.js' );
		$this->assertNull( ( new EditorAssets( $this->dir . '/build' ) )->resolve(), 'a directory is no script' );
		rmdir( $this->dir . '/build/index.js' );

		$this->assertNull( ( new EditorAssets( $this->dir . '/nope' ) )->resolve(), 'missing build directory' );

		$this->write_build();
		$this->assertSame(
			array(
				'script' => 'index.js',
				'style'  => 'index.css',
			),
			( new EditorAssets( $this->dir . '/build/' ) )->resolve()
		);
	}

	public function test_the_script_translations_resolve_to_the_german_catalog(): void {
		$this->write_build();
		$registrar = $this->register();
		unset( $registrar );
		add_filter( 'locale', static fn (): string => 'de_DE' );
		// The catalog is keyed by the md5 of the script path relative to the plugin; WordPress derives it from the URL, so use an installed-plugin URL.
		$handle                                  = BlockRegistrar::EDITOR_HANDLE;
		wp_scripts()->registered[ $handle ]->src = content_url( 'plugins/sw-gallery/assets/blocks/build/index.js' );
		$json                                    = load_script_textdomain( $handle, 'sw-gallery', SWGALLERY_DIR . 'languages' );
		$this->assertIsString( $json );
		$data = json_decode( $json, true );
		$this->assertIsArray( $data );
		$this->assertSame( 'messages', $data['domain'] );
		$messages = $data['locale_data']['messages'];
		$this->assertSame( array( 'Galerie' ), $messages['Gallery'] );
		$this->assertSame( array( 'Layout' ), $messages['Layout'] );
	}
}
