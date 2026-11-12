<?php
/**
 * The dynamic block `sw-gallery/gallery`.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Block;

use SWGallery\Core\Application\Render\EntryPoint;
use SWGallery\WordPress\Admin\AdminRegistrar;
use SWGallery\WordPress\Bootstrap\RegistrarInterface;
use SWGallery\WordPress\Infrastructure\Services;

/**
 * Registers the block from `assets/blocks/block.json` with a server `render_callback` that calls the one renderer
 * (`EntryPoint::Block`); the renderer alone decides access (D4), nothing is reimplemented here. The block stores only
 * the gallery id and validated presentation overrides ({@see BlockAttributes}), never media ids (CODE-STYLE 8).
 *
 * The editor script and style are registered under one handle with explicit dependencies and the plugin version;
 * WordPress loads them only in the block editor because the block type references the handle.
 */
final class BlockRegistrar implements RegistrarInterface {

	public const BLOCK_NAME    = 'sw-gallery/gallery';
	public const EDITOR_HANDLE = 'swg-block-editor';
	public const TEXT_DOMAIN   = 'sw-gallery';

	private const EDITOR_DEPENDENCIES = array(
		'wp-api-fetch',
		'wp-block-editor',
		'wp-blocks',
		'wp-components',
		'wp-element',
		'wp-i18n',
		'wp-server-side-render',
	);

	private string $block_dir;
	private string $block_url;
	private string $languages_dir;

	/**
	 * @param string|null $block_dir     Directory with block.json and build/; defaults to the plugin's assets/blocks.
	 * @param string|null $block_url     Public URL of that directory.
	 * @param string|null $languages_dir Directory with the script translation catalogs.
	 */
	public function __construct( ?string $block_dir = null, ?string $block_url = null, ?string $languages_dir = null ) {
		$this->block_dir     = rtrim( $block_dir ?? SWGALLERY_DIR . 'assets/blocks', '/\\' );
		$this->block_url     = rtrim( $block_url ?? SWGALLERY_URL . 'assets/blocks', '/' );
		$this->languages_dir = rtrim( $languages_dir ?? SWGALLERY_DIR . 'languages', '/\\' );
	}

	public function register(): void {
		// Priority 20: after CoreRegistrar loaded the text domain, which the block.json metadata is translated with.
		add_action( 'init', array( $this, 'register_block' ), 20 );
	}

	public function register_block(): void {
		$this->register_editor_assets();
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK_NAME ) ) {
			return;
		}
		register_block_type(
			$this->block_dir . '/block.json',
			array( 'render_callback' => array( $this, 'render' ) )
		);
	}

	/**
	 * Registers script and style handles when the build exists; without a build the block still renders on the
	 * frontend, only the editor UI is unavailable.
	 */
	private function register_editor_assets(): void {
		$files = ( new EditorAssets( $this->block_dir . '/build' ) )->resolve();
		if ( null === $files ) {
			return;
		}
		$build_url = $this->block_url . '/build/';
		wp_register_script( self::EDITOR_HANDLE, $build_url . $files['script'], self::EDITOR_DEPENDENCIES, SWGALLERY_VERSION, true );
		if ( null !== $files['style'] ) {
			wp_register_style( self::EDITOR_HANDLE, $build_url . $files['style'], array( 'wp-components' ), SWGALLERY_VERSION );
		}
		wp_add_inline_script(
			self::EDITOR_HANDLE,
			'window.swgBlock = ' . wp_json_encode( $this->editor_data() ) . ';',
			'before'
		);
		wp_set_script_translations( self::EDITOR_HANDLE, self::TEXT_DOMAIN, $this->languages_dir );
	}

	/**
	 * Data for the editor script: only the URL of the plugin's gallery screen (deep links), no secrets. The REST
	 * root and nonce come from `@wordpress/api-fetch`.
	 *
	 * @return array<string, string>
	 */
	public function editor_data(): array {
		return array(
			'galleriesUrl' => esc_url_raw( admin_url( 'admin.php?page=' . AdminRegistrar::PAGE_SLUG ) ),
		);
	}

	/**
	 * Server render of the block. Invalid attributes are dropped; no gallery selected renders nothing for visitors and
	 * a short hint for editors in the editor preview (REST request).
	 *
	 * @param array<mixed> $attributes Block attributes (untrusted).
	 */
	public function render( array $attributes ): string {
		$gallery_id = BlockAttributes::gallery_id( $attributes );
		if ( 0 === $gallery_id ) {
			return $this->editor_hint();
		}
		$html = Services::gallery_renderer()->html( $gallery_id, EntryPoint::Block, BlockAttributes::options( $attributes ) );
		if ( '' === $html ) {
			return '';
		}
		return sprintf( '<div %s>%s</div>', get_block_wrapper_attributes(), $html );
	}

	private function editor_hint(): string {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST || ! current_user_can( 'edit_posts' ) ) {
			return '';
		}
		return '<p class="swg-block-hint">' . esc_html__( 'No gallery selected. Choose one in the block settings.', 'sw-gallery' ) . '</p>';
	}
}
