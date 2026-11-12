<?php
/**
 * The "Galleries" admin page that mounts the Vue/Quasar admin app.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Admin;

use SWGallery\WordPress\Bootstrap\RegistrarInterface;

/**
 * Registers the admin menu entry and loads the built admin app only on that screen. Every asset is versioned with
 * the plugin version, the REST root, nonce, locale and capabilities reach the app through `window.swgAdmin`
 * (contract: apps/admin/README.md). The server enforces permissions; the capability list is only a UX hint.
 */
final class AdminRegistrar implements RegistrarInterface {

	public const PAGE_SLUG      = 'swg-galleries';
	public const CAPABILITY     = 'edit_posts';
	public const ASSET_HANDLE   = 'swg-admin';
	public const MOUNT_ID       = 'swg-admin-root';
	private const HINT_CAPS     = array( 'edit_posts', 'edit_others_posts', 'delete_others_posts', 'upload_files', 'manage_options' );
	private const MENU_POSITION = 26;

	private string $assets_dir;
	private string $assets_url;
	private string $hook_suffix = '';
	private bool $build_missing = false;

	/**
	 * @param string|null $assets_dir Build directory; defaults to the plugin's assets/admin.
	 * @param string|null $assets_url Public URL of the build directory.
	 */
	public function __construct( ?string $assets_dir = null, ?string $assets_url = null ) {
		$this->assets_dir = $assets_dir ?? SWGALLERY_DIR . 'assets/admin';
		$this->assets_url = rtrim( $assets_url ?? SWGALLERY_URL . 'assets/admin', '/' );
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'script_loader_tag', array( $this, 'mark_as_module' ), 10, 2 );
	}

	public function add_menu(): void {
		$hook              = add_menu_page(
			__( 'Gallery Management', 'sw-gallery' ),
			__( 'Galleries', 'sw-gallery' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' ),
			'dashicons-format-gallery',
			self::MENU_POSITION
		);
		$this->hook_suffix = $hook;
	}

	/**
	 * Loads the admin app, but only on this plugin's screen.
	 *
	 * @param string $hook_suffix Hook suffix of the screen being loaded.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$files = ( new AssetManifest( $this->assets_dir ) )->resolve();
		if ( null === $files ) {
			$this->build_missing = true;
			return;
		}

		wp_enqueue_style( self::ASSET_HANDLE, $this->assets_url . '/' . $files['style'], array(), SWGALLERY_VERSION );
		wp_enqueue_script( self::ASSET_HANDLE, $this->assets_url . '/' . $files['script'], array(), SWGALLERY_VERSION, true );
		wp_add_inline_script(
			self::ASSET_HANDLE,
			'window.swgAdmin = ' . wp_json_encode( $this->bootstrap() ) . ';',
			'before'
		);

		// Decision D2: the editor picks images with the native `wp.media` modal. Plugin screen only (guard above);
		// users who may not upload (contributors) cannot use the media modal, so they do not get its scripts.
		if ( current_user_can( 'upload_files' ) ) {
			wp_enqueue_media();
		}
	}

	/**
	 * The admin app is an ES module build; WordPress 6.4 has no module API, so the tag is adjusted here.
	 * WordPress hands the filter the inline "before" script (window.swgAdmin) together with the `src` script,
	 * so only the opening tag that has a `src` attribute may become a module; the inline script stays classic.
	 *
	 * @param string $tag    Script tag(s).
	 * @param string $handle Script handle.
	 */
	public function mark_as_module( string $tag, string $handle ): string {
		if ( self::ASSET_HANDLE !== $handle ) {
			return $tag;
		}
		return (string) preg_replace_callback(
			'/<script\b[^>]*\ssrc=[^>]*>/',
			static function ( array $found ): string {
				$open = (string) preg_replace( '/\stype=([\'"])[^\'"]*\1/', '', $found[0], 1 );
				return (string) preg_replace( '/^<script\b/', '<script type="module"', $open, 1 );
			},
			$tag,
			1
		);
	}

	/**
	 * Data handed to the app. Contains no secret besides the per-user REST nonce, which the app keeps in memory.
	 *
	 * @return array<string, mixed>
	 */
	public function bootstrap(): array {
		return array(
			'restRoot'     => esc_url_raw( rest_url( 'swg/v1/' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'locale'       => get_user_locale(),
			'version'      => SWGALLERY_VERSION,
			'adminUrl'     => esc_url_raw( admin_url() ),
			'capabilities' => array_values(
				array_filter(
					self::HINT_CAPS,
					static fn ( string $capability ): bool => current_user_can( $capability )
				)
			),
			'isRtl'        => is_rtl(),
			'timezone'     => wp_timezone_string(),
		);
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'sw-gallery' ), '', array( 'response' => 403 ) );
		}

		echo '<div class="wrap swg-wrap">';
		echo '<h1 class="screen-reader-text">' . esc_html__( 'Gallery Management', 'sw-gallery' ) . '</h1>';
		if ( $this->build_missing ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The gallery admin app is not built. Run "npm run build" in apps/admin or install the release package.', 'sw-gallery' ) . '</p></div>';
		}
		echo '<div id="' . esc_attr( self::MOUNT_ID ) . '"></div>';
		echo '<noscript><p>' . esc_html__( 'The gallery administration requires JavaScript.', 'sw-gallery' ) . '</p></noscript>';
		echo '</div>';
	}
}
