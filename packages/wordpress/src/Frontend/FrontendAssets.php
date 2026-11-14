<?php
/**
 * Registers and enqueues the frontend stylesheets and the lightbox and masonry scripts.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Frontend;

use SWGallery\Core\Application\Render\RenderAsset;
use SWGallery\WordPress\Settings\PluginSettings;

/**
 * Assets load only for galleries that were really printed (never on admin screens, never on pages without a gallery):
 *
 * - `wp_enqueue_scripts`: the handles are registered; when a queried post contains the shortcode or the block, the
 *   stylesheets are enqueued right away so they print in the `<head>` (no flash of an unstyled gallery).
 * - Otherwise (widgets, PHP API, template code, content filters) the collector decides: `wp_print_footer_scripts`
 *   (priority 1) enqueues from it and the stylesheets print late in the footer (decision F-17: a short flash of
 *   unstyled markup is the accepted trade-off for such embeds).
 * - The lightbox script (classic, deferred, in the footer; never a module) and its stylesheet need the plugin setting
 *   `lightbox`. Every asset carries the plugin version. A missing or hostile build enqueues nothing; editors see a
 *   note without paths.
 */
final class FrontendAssets {

	public const HANDLE_LAYOUT = 'swg-frontend-layouts';
	public const HANDLE_STYLE  = 'swg-lightbox';
	public const HANDLE_SCRIPT = 'swg-lightbox';
	/** Handle of the masonry script; the handles of all layout scripts come from {@see LayoutScripts::handle()}. */
	public const HANDLE_MASONRY = 'swg-masonry';
	public const SHORTCODE      = 'sw_gallery';
	public const BLOCK          = 'sw-gallery/gallery';

	private const MAX_SCANNED_POSTS = 50;

	private string $build_url;

	public function __construct(
		private readonly FrontendManifest $manifest,
		private readonly AssetCollector $collector,
		private readonly PluginSettings $settings,
		string $build_url
	) {
		$this->build_url = rtrim( $build_url, '/' );
	}

	/** `wp_enqueue_scripts`: registers the handles and enqueues the styles early for posts with a gallery. */
	public function on_enqueue_scripts(): void {
		if ( is_admin() || is_feed() ) {
			return;
		}
		$this->register_handles();
		if ( $this->queried_content_has_gallery() ) {
			$this->enqueue_styles();
		}
	}

	/** `wp_print_footer_scripts` (priority 1): enqueues what the rendered galleries need. */
	public function enqueue_collected(): void {
		if ( is_admin() || ! $this->collector->has_rendered() ) {
			return;
		}
		$this->register_handles();
		$this->enqueue_styles();
		if ( $this->lightbox_enabled() && $this->collector->needs( RenderAsset::Lightbox ) ) {
			wp_enqueue_script( self::HANDLE_SCRIPT );
		}
		// Independent of the lightbox setting: without it a masonry gallery would stay a plain grid.
		foreach ( LayoutScripts::assets() as $asset ) {
			$handle = LayoutScripts::handle( $asset );
			if ( $this->collector->needs( $asset ) && wp_script_is( $handle, 'registered' ) ) {
				wp_enqueue_script( $handle );
			}
		}
	}

	/** Editor-only note when galleries were rendered but the build is unusable. No paths are printed. */
	public function print_build_notice(): void {
		if ( is_admin() || ! $this->collector->has_rendered() || $this->build_usable() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		printf(
			'<p class="swg-diagnostic" role="note">%s</p>',
			esc_html__( 'Gallery diagnostic (visible to editors only): the gallery frontend assets are missing or invalid. Run "npm run build" in assets or install the release package.', 'sw-gallery' )
		);
	}

	/**
	 * Assets for the editor's sandboxed preview: absolute URLs with the plugin version, empty when the build is missing.
	 *
	 * @return array{css: list<string>, js: list<string>}
	 */
	public function preview_assets(): array {
		$files = $this->manifest->resolve();
		$css   = array();
		$js    = array();
		if ( null !== $files['layout'] ) {
			$css[] = $this->versioned_url( $files['layout'] );
		}
		if ( $this->lightbox_enabled() ) {
			if ( null !== $files['style'] ) {
				$css[] = $this->versioned_url( $files['style'] );
			}
			if ( null !== $files['script'] ) {
				$js[] = $this->versioned_url( $files['script'] );
			}
		}
		foreach ( $this->manifest->layout_scripts() as $file ) {
			if ( null !== $file ) {
				$js[] = $this->versioned_url( $file );
			}
		}
		return array(
			'css' => $css,
			'js'  => $js,
		);
	}

	private function register_handles(): void {
		$files = $this->manifest->resolve();
		if ( null !== $files['layout'] && ! wp_style_is( self::HANDLE_LAYOUT, 'registered' ) ) {
			wp_register_style( self::HANDLE_LAYOUT, $this->build_url . '/' . $files['layout'], array(), SWGALLERY_VERSION );
		}
		if ( null !== $files['style'] && ! wp_style_is( self::HANDLE_STYLE, 'registered' ) ) {
			wp_register_style( self::HANDLE_STYLE, $this->build_url . '/' . $files['style'], array(), SWGALLERY_VERSION );
		}
		if ( null !== $files['script'] && ! wp_script_is( self::HANDLE_SCRIPT, 'registered' ) ) {
			$this->register_script( self::HANDLE_SCRIPT, $files['script'] );
		}
		foreach ( LayoutScripts::assets() as $asset ) {
			$file   = $this->manifest->layout_scripts()[ $asset->value ] ?? null;
			$handle = LayoutScripts::handle( $asset );
			if ( null !== $file && ! wp_script_is( $handle, 'registered' ) ) {
				$this->register_script( $handle, $file );
			}
		}
	}

	/**
	 * @param non-empty-string $handle Script handle.
	 * @param string           $file   Build-relative file name from the validated manifest.
	 */
	private function register_script( string $handle, string $file ): void {
		wp_register_script(
			$handle,
			$this->build_url . '/' . $file,
			array(),
			SWGALLERY_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	private function enqueue_styles(): void {
		if ( wp_style_is( self::HANDLE_LAYOUT, 'registered' ) ) {
			wp_enqueue_style( self::HANDLE_LAYOUT );
		}
		if ( $this->lightbox_enabled() && wp_style_is( self::HANDLE_STYLE, 'registered' ) ) {
			wp_enqueue_style( self::HANDLE_STYLE );
		}
	}

	private function lightbox_enabled(): bool {
		return true === $this->settings->get()['lightbox'];
	}

	private function build_usable(): bool {
		$files = $this->manifest->resolve();
		return null !== $files['layout'] || null !== $files['style'] || null !== $files['script'] || array() !== array_filter( $this->manifest->layout_scripts() );
	}

	private function versioned_url( string $file ): string {
		return esc_url_raw( add_query_arg( 'ver', SWGALLERY_VERSION, $this->build_url . '/' . $file ) );
	}

	/** Whether a queried post contains the shortcode or the block (cheap string scans, bounded). */
	private function queried_content_has_gallery(): bool {
		$query = $GLOBALS['wp_query'] ?? null;
		if ( ! $query instanceof \WP_Query || ! is_array( $query->posts ) ) {
			return false;
		}
		foreach ( array_slice( $query->posts, 0, self::MAX_SCANNED_POSTS ) as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			if ( has_shortcode( $post->post_content, self::SHORTCODE ) || has_block( self::BLOCK, $post->post_content ) ) {
				return true;
			}
		}
		return false;
	}
}
