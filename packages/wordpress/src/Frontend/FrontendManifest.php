<?php
/**
 * Validated reader of the Vite build manifest of the frontend assets.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Frontend;

/**
 * Resolves the hashed lightbox script and the stylesheets of `assets/build`. Everything read from the manifest is
 * untrusted: only plain relative file names below the build directory that exist on disk (after resolving symlinks),
 * carry the expected extension and are not symlinks themselves are accepted. A missing or hostile manifest yields
 * null entries, so nothing is enqueued and no path ever reaches the output.
 */
final class FrontendManifest {

	public const SCRIPT_KEY = 'lightbox/src/main.ts';
	public const STYLE_KEY  = 'style.css';
	/** Optional: the layout stylesheet (frontend CSS stream). */
	public const LAYOUT_KEY = 'frontend/src/layouts.css';
	/** Optional: the masonry progressive-enhancement script (classic script); all layout scripts are listed in {@see LayoutScripts}. */
	public const MASONRY_KEY = LayoutScripts::MANIFEST_KEYS['masonry'];

	private const MAX_MANIFEST_BYTES = 262144;
	private const FILE_PATTERN       = '#^[A-Za-z0-9_][A-Za-z0-9_./-]*\.(js|css)$#';

	private string $build_dir;

	/** @var array{script: ?string, style: ?string, layout: ?string, masonry: ?string}|null */
	private ?array $resolved = null;

	/** @var array<string, ?string>|null */
	private ?array $layout_scripts = null;

	public function __construct( string $build_dir ) {
		$this->build_dir = rtrim( $build_dir, '/\\' );
	}

	/**
	 * Build-relative file names; an entry is null when it is absent or not trustworthy.
	 *
	 * @return array{script: ?string, style: ?string, layout: ?string, masonry: ?string}
	 */
	public function resolve(): array {
		if ( null !== $this->resolved ) {
			return $this->resolved;
		}
		$manifest = $this->read();
		if ( null === $manifest ) {
			$this->resolved = array(
				'script'  => null,
				'style'   => null,
				'layout'  => null,
				'masonry' => null,
			);
			return $this->resolved;
		}
		$this->resolved = array(
			'script'  => $this->entry_file( $manifest, self::SCRIPT_KEY, 'js' ),
			'style'   => $this->entry_file( $manifest, self::STYLE_KEY, 'css' ),
			'layout'  => $this->entry_file( $manifest, self::LAYOUT_KEY, 'css' ),
			'masonry' => $this->entry_file( $manifest, self::MASONRY_KEY, 'js' ),
		);
		return $this->resolved;
	}

	/**
	 * Build-relative file of every layout script ({@see LayoutScripts}) keyed by RenderAsset value; null when the entry
	 * is absent or not trustworthy.
	 *
	 * @return array<string, ?string>
	 */
	public function layout_scripts(): array {
		if ( null !== $this->layout_scripts ) {
			return $this->layout_scripts;
		}
		$manifest = $this->read();
		$files    = array();
		foreach ( LayoutScripts::MANIFEST_KEYS as $value => $key ) {
			$files[ $value ] = null === $manifest ? null : $this->entry_file( $manifest, $key, 'js' );
		}
		$this->layout_scripts = $files;
		return $files;
	}

	/**
	 * @return array<mixed>|null
	 */
	private function read(): ?array {
		$file = $this->build_dir . '/.vite/manifest.json';
		$base = realpath( $this->build_dir );
		$real = realpath( $file );
		if ( false === $base || false === $real || is_link( $file ) || ! is_file( $real ) || ! str_starts_with( $real, $base . DIRECTORY_SEPARATOR ) ) {
			return null;
		}
		$size = filesize( $real );
		if ( false === $size || $size > self::MAX_MANIFEST_BYTES ) {
			return null;
		}
		// Local build file, size capped above. wp_json_file_decode() would raise a notice on broken JSON.
		$content  = file_get_contents( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$manifest = false === $content ? null : json_decode( $content, true );
		return is_array( $manifest ) ? $manifest : null;
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
		if ( false === $base || false === $path || ! is_file( $path ) || is_link( $this->build_dir . '/' . $file ) ) {
			return null;
		}
		return str_starts_with( $path, $base . DIRECTORY_SEPARATOR ) ? $file : null;
	}
}
