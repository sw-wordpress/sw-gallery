<?php
/**
 * Admin page: menu, versioned assets only on the plugin screen, bootstrap data for the Vue app, safe diagnostics.
 *
 * @package sw-gallery
 */

use SWGallery\WordPress\Admin\AdminRegistrar;
use SWGallery\WordPress\Admin\AssetManifest;

class AdminPageTest extends SwgTestCase {

	private string $build_dir;

	public function set_up(): void {
		parent::set_up();
		$this->build_dir = sys_get_temp_dir() . '/swg-admin-build-' . uniqid( '', true );
		mkdir( $this->build_dir . '/.vite', 0777, true );
		wp_dequeue_script( AdminRegistrar::ASSET_HANDLE );
		wp_deregister_script( AdminRegistrar::ASSET_HANDLE );
		wp_dequeue_style( AdminRegistrar::ASSET_HANDLE );
		wp_deregister_style( AdminRegistrar::ASSET_HANDLE );
	}

	public function tear_down(): void {
		$this->remove_dir( $this->build_dir );
		parent::tear_down();
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

	/**
	 * @param array<string, mixed>|null $manifest Manifest content; null writes none.
	 */
	private function write_build( ?array $manifest = null ): void {
		file_put_contents( $this->build_dir . '/app-abc.js', 'export {};' );
		file_put_contents( $this->build_dir . '/style-abc.css', '.swg-root{}' );
		$manifest ??= array(
			'src/main.ts' => array( 'file' => 'app-abc.js' ),
			'style.css'   => array( 'file' => 'style-abc.css' ),
		);
		file_put_contents( $this->build_dir . '/.vite/manifest.json', wp_json_encode( $manifest ) );
	}

	private function registrar(): AdminRegistrar {
		return new AdminRegistrar( $this->build_dir, 'https://example.test/build/' );
	}

	/**
	 * Opens the plugin screen as the given role and returns the hook suffix.
	 */
	private function open_screen( AdminRegistrar $registrar, string $role = 'editor' ): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
		$registrar->add_menu();
		global $_registered_pages;
		$suffix = get_plugin_page_hookname( AdminRegistrar::PAGE_SLUG, '' );
		$this->assertNotEmpty( $suffix );
		$this->assertArrayHasKey( $suffix, $_registered_pages );
		return $suffix;
	}

	public function test_the_registrar_hooks_menu_assets_and_module_tag(): void {
		$registrar = new AdminRegistrar();
		$registrar->register();

		$this->assertNotFalse( has_action( 'admin_menu', array( $registrar, 'add_menu' ) ) );
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', array( $registrar, 'enqueue' ) ) );
		$this->assertNotFalse( has_filter( 'script_loader_tag', array( $registrar, 'mark_as_module' ) ) );
	}

	public function test_assets_load_only_on_the_plugin_screen_and_carry_the_plugin_version(): void {
		$this->write_build();
		$registrar = $this->registrar();
		$suffix    = $this->open_screen( $registrar );

		$registrar->enqueue( 'edit.php' );
		$this->assertFalse( wp_script_is( AdminRegistrar::ASSET_HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_style_is( AdminRegistrar::ASSET_HANDLE, 'enqueued' ) );

		$registrar->enqueue( $suffix );
		$this->assertTrue( wp_script_is( AdminRegistrar::ASSET_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( AdminRegistrar::ASSET_HANDLE, 'enqueued' ) );
		$script = wp_scripts()->registered[ AdminRegistrar::ASSET_HANDLE ];
		$style  = wp_styles()->registered[ AdminRegistrar::ASSET_HANDLE ];
		$this->assertSame( SWGALLERY_VERSION, $script->ver );
		$this->assertSame( SWGALLERY_VERSION, $style->ver );
		$this->assertSame( 'https://example.test/build/app-abc.js', $script->src );
		$this->assertSame( 'https://example.test/build/style-abc.css', $style->src );
	}

	public function test_the_script_tag_becomes_a_module_without_a_second_type_attribute(): void {
		$registrar = $this->registrar();
		$tag       = $registrar->mark_as_module( "<script type='text/javascript' src='a.js?ver=1'></script>", AdminRegistrar::ASSET_HANDLE );

		$this->assertSame( 1, substr_count( $tag, 'type=' ) );
		$this->assertStringStartsWith( '<script type="module"', $tag );
		$other = "<script src='b.js'></script>";
		$this->assertSame( $other, $registrar->mark_as_module( $other, 'jquery' ) );
	}

	public function test_the_bootstrap_matches_the_contract_of_the_vue_app(): void {
		$this->write_build();
		$registrar = $this->registrar();
		$suffix    = $this->open_screen( $registrar );
		$registrar->enqueue( $suffix );

		$inline = array_values( array_filter( (array) wp_scripts()->get_data( AdminRegistrar::ASSET_HANDLE, 'before' ) ) );
		$this->assertCount( 1, $inline );
		$this->assertStringStartsWith( 'window.swgAdmin = {', (string) $inline[0] );
		$data = json_decode( substr( (string) $inline[0], strlen( 'window.swgAdmin = ' ), -1 ), true );

		$this->assertIsArray( $data );
		$this->assertSame( rest_url( 'swg/v1/' ), $data['restRoot'] );
		$this->assertSame( 1, wp_verify_nonce( $data['nonce'], 'wp_rest' ) ? 1 : 0 );
		$this->assertSame( get_user_locale(), $data['locale'] );
		$this->assertSame( SWGALLERY_VERSION, $data['version'] );
		$this->assertSame( admin_url(), $data['adminUrl'] );
		$this->assertIsBool( $data['isRtl'] );
		$this->assertNotSame( '', $data['timezone'] );
		$this->assertContains( 'edit_posts', $data['capabilities'] );
		$this->assertContains( 'upload_files', $data['capabilities'] );
		$this->assertNotContains( 'manage_options', $data['capabilities'] );
		$this->assertSame( array( 'restRoot', 'nonce', 'locale', 'version', 'adminUrl', 'capabilities', 'isRtl', 'timezone' ), array_keys( $data ) );
	}

	public function test_an_author_does_not_get_capabilities_of_an_editor(): void {
		$registrar = $this->registrar();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$caps = $registrar->bootstrap()['capabilities'];
		$this->assertContains( 'edit_posts', $caps );
		$this->assertNotContains( 'edit_others_posts', $caps );
		$this->assertNotContains( 'manage_options', $caps );
	}

	public function test_a_user_without_the_capability_gets_no_assets_and_no_page(): void {
		$this->write_build();
		$registrar = $this->registrar();
		$suffix    = $this->open_screen( $registrar );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$registrar->enqueue( $suffix );
		$this->assertFalse( wp_script_is( AdminRegistrar::ASSET_HANDLE, 'enqueued' ) );

		$this->expectException( WPDieException::class );
		$registrar->render();
	}

	public function test_the_page_renders_only_the_mount_node_for_a_valid_build(): void {
		$this->write_build();
		$registrar = $this->registrar();
		$suffix    = $this->open_screen( $registrar );
		$registrar->enqueue( $suffix );

		ob_start();
		$registrar->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="swg-admin-root"', $html );
		$this->assertStringNotContainsString( 'notice-error', $html );
		$this->assertStringContainsString( '<noscript>', $html );
	}

	public function test_a_missing_build_shows_a_safe_diagnostic_and_loads_nothing(): void {
		$registrar = $this->registrar();
		$suffix    = $this->open_screen( $registrar );
		$registrar->enqueue( $suffix );

		ob_start();
		$registrar->render();
		$html = (string) ob_get_clean();

		$this->assertFalse( wp_script_is( AdminRegistrar::ASSET_HANDLE, 'enqueued' ) );
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringNotContainsString( $this->build_dir, $html );
	}

	/**
	 * @return array<string, array{array<string, mixed>|string}>
	 */
	public static function untrusted_manifests(): array {
		$ok_style = array( 'file' => 'style-abc.css' );
		return array(
			'no script entry'           => array( array( 'style.css' => $ok_style ) ),
			'no style entry'            => array( array( 'src/main.ts' => array( 'file' => 'app-abc.js' ) ) ),
			'path traversal'            => array(
				array(
					'src/main.ts' => array( 'file' => '../app-abc.js' ),
					'style.css'   => $ok_style,
				),
			),
			'absolute path'             => array(
				array(
					'src/main.ts' => array( 'file' => '/etc/passwd.js' ),
					'style.css'   => $ok_style,
				),
			),
			'url scheme'                => array(
				array(
					'src/main.ts' => array( 'file' => 'https://evil.test/a.js' ),
					'style.css'   => $ok_style,
				),
			),
			'wrong extension'           => array(
				array(
					'src/main.ts' => array( 'file' => 'app-abc.php' ),
					'style.css'   => $ok_style,
				),
			),
			'script entry points to css' => array(
				array(
					'src/main.ts' => array( 'file' => 'style-abc.css' ),
					'style.css'   => $ok_style,
				),
			),
			'file does not exist'       => array(
				array(
					'src/main.ts' => array( 'file' => 'missing.js' ),
					'style.css'   => $ok_style,
				),
			),
			'file is not a string'      => array(
				array(
					'src/main.ts' => array( 'file' => array( 'x' ) ),
					'style.css'   => $ok_style,
				),
			),
			'entry is not an object'    => array(
				array(
					'src/main.ts' => 'app-abc.js',
					'style.css'   => $ok_style,
				),
			),
			'not json'                  => array( '{ not json' ),
			'json scalar'               => array( '"text"' ),
		);
	}

	/**
	 * @dataProvider untrusted_manifests
	 * @param array<string, mixed>|string $manifest Manifest content.
	 */
	public function test_an_untrusted_manifest_is_rejected( $manifest ): void {
		$this->write_build();
		file_put_contents( $this->build_dir . '/.vite/manifest.json', is_string( $manifest ) ? $manifest : wp_json_encode( $manifest ) );

		$this->assertNull( ( new AssetManifest( $this->build_dir ) )->resolve() );
	}

	public function test_a_symlink_escaping_the_build_directory_is_rejected(): void {
		$outside = sys_get_temp_dir() . '/swg-outside-' . uniqid( '', true ) . '.js';
		file_put_contents( $outside, 'x' );
		$this->write_build();
		symlink( $outside, $this->build_dir . '/link.js' );
		file_put_contents(
			$this->build_dir . '/.vite/manifest.json',
			wp_json_encode(
				array(
					'src/main.ts' => array( 'file' => 'link.js' ),
					'style.css'   => array( 'file' => 'style-abc.css' ),
				)
			)
		);

		$this->assertNull( ( new AssetManifest( $this->build_dir ) )->resolve() );
		unlink( $outside );
	}

	public function test_an_oversized_or_missing_manifest_is_rejected(): void {
		$this->assertNull( ( new AssetManifest( $this->build_dir ) )->resolve() );

		$this->write_build();
		file_put_contents( $this->build_dir . '/.vite/manifest.json', str_repeat( ' ', 1048577 ) );
		$this->assertNull( ( new AssetManifest( $this->build_dir ) )->resolve() );
	}

	public function test_a_valid_manifest_resolves_to_build_relative_names(): void {
		$this->write_build();

		$this->assertSame(
			array(
				'script' => 'app-abc.js',
				'style'  => 'style-abc.css',
			),
			( new AssetManifest( $this->build_dir ) )->resolve()
		);
	}
}
