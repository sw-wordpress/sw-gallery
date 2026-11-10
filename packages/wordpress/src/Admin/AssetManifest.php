<?php
/**
 * Validated reader of the Vite build manifest of the admin app.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Admin;

/**
 * Resolves the hashed script and stylesheet of the admin build. Everything read from the manifest is treated as
 * untrusted: only plain relative file names below the build directory that exist on disk are accepted, anything else
 * yields null so the page can show a safe diagnostic instead of loading unexpected files.
 */
final class AssetManifest {

	public const SCRIPT_KEY = 'src/main.ts';
	public const STYLE_KEY  = 'style.css';

	private const MAX_MANIFEST_BYTES = 1048576;
	private const FILE_PATTERN       = '#^[A-Za-z0-9_][A-Za-z0-9_./-]*\.(js|css)$#';

	private string $build_dir;

	public function __construct( string $build_dir ) {
		$this->build_dir = rtrim( $build_dir, '/\\' );
	}

	/**
	 * Returns the build-relative file names of the entry script and the stylesheet, or null when the build is
	 * missing or the manifest is not trustworthy.
	 *
	 * @return array{script: string, style: string}|null
	 */
	public function resolve(): ?array {
		$manifest_file = $this->build_dir . '/.vite/manifest.json';
		if ( ! is_file( $manifest_file ) || filesize( $manifest_file ) > self::MAX_MANIFEST_BYTES ) {
			return null;
		}
		// Local build file, size capped above. wp_json_file_decode() would raise a notice on broken JSON.
		$content  = file_get_contents( $manifest_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$manifest = false === $content ? null : json_decode( $content, true );
		if ( ! is_array( $manifest ) ) {
			return null;
		}

		$script = $this->entry_file( $manifest, self::SCRIPT_KEY, 'js' );
		$style  = $this->entry_file( $manifest, self::STYLE_KEY, 'css' );
		if ( null === $script || null === $style ) {
			return null;
		}
		return array(
			'script' => $script,
			'style'  => $style,
		);
	}

	/**
	 * @param array<mixed> $manifest Decoded manifest.
	 */
	private function entry_file( array $manifest, string $key, string $extension ): ?string {
		$entry = $manifest[ $key ] ?? null;
		$file  = is_array( $entry ) ? ( $entry['file'] ?? null ) : null;
		if ( ! is_string( $file ) || 1 !== preg_match( self::FILE_PATTERN, $file ) ) {
			return null;
		}
		if ( ! str_ends_with( $file, '.' . $extension ) || str_contains( $file, '..' ) || str_contains( $file, '//' ) ) {
			return null;
		}

		$base = realpath( $this->build_dir );
		$path = realpath( $this->build_dir . '/' . $file );
		if ( false === $base || false === $path || ! is_file( $path ) ) {
			return null;
		}
		if ( ! str_starts_with( $path, $base . DIRECTORY_SEPARATOR ) ) {
			return null;
		}
		return $file;
	}
}
