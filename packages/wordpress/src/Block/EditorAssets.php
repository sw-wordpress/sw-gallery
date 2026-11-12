<?php
/**
 * Locates the built editor script and stylesheet of the block.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Block;

/**
 * The block build (assets/blocks, Vite) emits fixed file names; the cache-busting version is the plugin version.
 * Nothing is read from the build directory, only existence and location are checked: a file must be a regular file
 * whose real path stays below the real build directory (no symlink escape), otherwise it is treated as missing so the
 * editor never loads unexpected files.
 */
final class EditorAssets {

	public const SCRIPT = 'index.js';
	public const STYLE  = 'index.css';

	private string $build_dir;

	public function __construct( string $build_dir ) {
		$this->build_dir = rtrim( $build_dir, '/\\' );
	}

	/**
	 * Build-relative file names; null when the script is missing. The stylesheet is optional.
	 *
	 * @return array{script: string, style: string|null}|null
	 */
	public function resolve(): ?array {
		$script = $this->file( self::SCRIPT );
		if ( null === $script ) {
			return null;
		}
		return array(
			'script' => $script,
			'style'  => $this->file( self::STYLE ),
		);
	}

	private function file( string $name ): ?string {
		$base = realpath( $this->build_dir );
		$path = realpath( $this->build_dir . '/' . $name );
		if ( false === $base || false === $path || ! is_file( $path ) || ! str_starts_with( $path, $base . DIRECTORY_SEPARATOR ) ) {
			return null;
		}
		return $name;
	}
}
