<?php
/**
 * The validated frontend build manifest reader, including hostile manifests.
 *
 * @package sw-gallery
 */

use SWGallery\WordPress\Frontend\FrontendManifest;

class FrontendManifestTest extends SwgTestCase {

	private string $root;
	private string $build;

	public function set_up(): void {
		parent::set_up();
		$this->root  = sys_get_temp_dir() . '/swg-manifest-' . wp_generate_password( 8, false );
		$this->build = $this->root . '/build';
		mkdir( $this->build . '/.vite', 0777, true );
	}

	public function tear_down(): void {
		$this->remove( $this->root );
		parent::tear_down();
	}

	private function remove( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		if ( is_dir( $path ) ) {
			foreach ( (array) scandir( $path ) as $entry ) {
				if ( '.' !== $entry && '..' !== $entry ) {
					$this->remove( $path . '/' . $entry );
				}
			}
			rmdir( $path );
		}
	}

	/**
	 * @param array<string, mixed> $manifest Manifest content.
	 */
	private function write_manifest( array $manifest ): void {
		file_put_contents( $this->build . '/.vite/manifest.json', (string) wp_json_encode( $manifest ) );
	}

	private function touch_build( string ...$files ): void {
		foreach ( $files as $file ) {
			file_put_contents( $this->build . '/' . $file, '/* build */' );
		}
	}

	/**
	 * @return array<string, array<string, string>>
	 */
	private function entries( string $script = 'lightbox-abc.js', string $style = 'lightbox-abc.css', string $layout = 'layouts-abc.css' ): array {
		return array(
			FrontendManifest::SCRIPT_KEY => array( 'file' => $script ),
			FrontendManifest::STYLE_KEY  => array( 'file' => $style ),
			FrontendManifest::LAYOUT_KEY => array( 'file' => $layout ),
		);
	}

	public function test_a_valid_manifest_resolves_all_entries(): void {
		$this->touch_build( 'lightbox-abc.js', 'lightbox-abc.css', 'layouts-abc.css' );
		$this->write_manifest( $this->entries() );

		$this->assertSame(
			array(
				'script' => 'lightbox-abc.js',
				'style'  => 'lightbox-abc.css',
				'layout' => 'layouts-abc.css',
			),
			( new FrontendManifest( $this->build ) )->resolve()
		);
	}

	public function test_the_layout_stylesheet_is_optional(): void {
		$this->touch_build( 'lightbox-abc.js', 'lightbox-abc.css' );
		$manifest = $this->entries();
		unset( $manifest[ FrontendManifest::LAYOUT_KEY ] );
		$this->write_manifest( $manifest );

		$files = ( new FrontendManifest( $this->build ) )->resolve();
		$this->assertNull( $files['layout'] );
		$this->assertSame( 'lightbox-abc.js', $files['script'] );
		$this->assertSame( 'lightbox-abc.css', $files['style'] );
	}

	public function test_a_missing_build_or_manifest_yields_nothing(): void {
		$none = array(
			'script' => null,
			'style'  => null,
			'layout' => null,
		);
		$this->assertSame( $none, ( new FrontendManifest( $this->root . '/does-not-exist' ) )->resolve() );
		$this->assertSame( $none, ( new FrontendManifest( $this->build ) )->resolve() );
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function broken_manifests(): iterable {
		yield 'not json' => array( '{not json' );
		yield 'scalar' => array( '"text"' );
		yield 'empty' => array( '' );
	}

	/**
	 * @dataProvider broken_manifests
	 */
	public function test_a_broken_manifest_yields_nothing( string $content ): void {
		file_put_contents( $this->build . '/.vite/manifest.json', $content );

		$this->assertSame( array( null, null, null ), array_values( ( new FrontendManifest( $this->build ) )->resolve() ) );
	}

	public function test_an_oversized_manifest_is_refused(): void {
		$this->touch_build( 'lightbox-abc.js', 'lightbox-abc.css', 'layouts-abc.css' );
		$manifest             = $this->entries();
		$manifest['padding']  = array( 'file' => str_repeat( 'a', 300000 ) );
		$this->write_manifest( $manifest );

		$this->assertSame( array( null, null, null ), array_values( ( new FrontendManifest( $this->build ) )->resolve() ) );
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function hostile_files(): iterable {
		yield 'parent traversal' => array( '../outside.js' );
		yield 'nested traversal' => array( 'a/../../outside.js' );
		yield 'absolute path' => array( '/etc/passwd.js' );
		yield 'double slash' => array( 'a//b.js' );
		yield 'url' => array( 'https://evil.example/x.js' );
		yield 'wrong extension' => array( 'lightbox-abc.php' );
		yield 'double extension' => array( 'lightbox-abc.js.php' );
		yield 'null byte' => array( "lightbox-abc.js\0.php" );
		yield 'backslash' => array( 'a\\b.js' );
		yield 'missing file' => array( 'ghost.js' );
		yield 'hidden dot start' => array( '.hidden.js' );
	}

	/**
	 * @dataProvider hostile_files
	 */
	public function test_hostile_script_entries_are_refused( string $file ): void {
		file_put_contents( $this->root . '/outside.js', '// outside' );
		$this->touch_build( 'lightbox-abc.css' );
		$this->write_manifest( $this->entries( $file ) );

		$files = ( new FrontendManifest( $this->build ) )->resolve();
		$this->assertNull( $files['script'], $file );
		$this->assertSame( 'lightbox-abc.css', $files['style'], 'The other entries stay usable.' );
	}

	public function test_the_extension_must_match_the_entry_kind(): void {
		$this->touch_build( 'lightbox-abc.js', 'lightbox-abc.css', 'layouts-abc.css' );
		$this->write_manifest( $this->entries( 'lightbox-abc.css', 'lightbox-abc.js', 'lightbox-abc.js' ) );

		$this->assertSame( array( null, null, null ), array_values( ( new FrontendManifest( $this->build ) )->resolve() ) );
	}

	public function test_a_symlink_leaving_the_build_directory_is_refused(): void {
		if ( ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'symlink() unavailable.' );
		}
		file_put_contents( $this->root . '/secret.js', '// secret' );
		mkdir( $this->root . '/elsewhere' );
		file_put_contents( $this->root . '/elsewhere/x.js', '// x' );
		symlink( $this->root . '/secret.js', $this->build . '/link.js' );
		symlink( $this->root . '/elsewhere', $this->build . '/dir' );

		$this->write_manifest( $this->entries( 'link.js' ) );
		$this->assertNull( ( new FrontendManifest( $this->build ) )->resolve()['script'], 'file symlink' );

		$this->write_manifest( $this->entries( 'dir/x.js' ) );
		$this->assertNull( ( new FrontendManifest( $this->build ) )->resolve()['script'], 'directory symlink' );
	}

	public function test_a_symlink_inside_the_build_directory_is_refused_too(): void {
		$this->touch_build( 'real.js' );
		symlink( $this->build . '/real.js', $this->build . '/alias.js' );
		$this->write_manifest( $this->entries( 'alias.js' ) );

		$this->assertNull( ( new FrontendManifest( $this->build ) )->resolve()['script'] );
	}

	public function test_a_symlinked_manifest_is_refused(): void {
		$this->touch_build( 'lightbox-abc.js', 'lightbox-abc.css' );
		file_put_contents( $this->root . '/evil-manifest.json', (string) wp_json_encode( $this->entries() ) );
		symlink( $this->root . '/evil-manifest.json', $this->build . '/.vite/manifest.json' );

		$this->assertSame( array( null, null, null ), array_values( ( new FrontendManifest( $this->build ) )->resolve() ) );
	}

	public function test_the_result_is_memoised(): void {
		$this->touch_build( 'lightbox-abc.js', 'lightbox-abc.css' );
		$this->write_manifest( $this->entries() );
		$reader = new FrontendManifest( $this->build );
		$first  = $reader->resolve();
		unlink( $this->build . '/lightbox-abc.js' );

		$this->assertSame( $first, $reader->resolve() );
	}
}
