<?php
/**
 * Trust boundary of registered layouts (M5-04 l): a layout registered through `swg_register_layouts` is TRUSTED PHP
 * code. The plugin contains what it can (exceptions never reach the page or the log with their message, a rejected
 * layout is not used, the access decision happens before any layout runs) but does not sanitise or sandbox the markup
 * a layout returns. This test pins that contract; docs/security.md states it for extension authors.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Application\Render\EntryPoint;
use SWGallery\Core\Contract\GalleryLayoutInterface;
use SWGallery\Core\Contract\LayoutRegistryInterface;
use SWGallery\Core\Domain\Layout\LayoutRenderRequest;
use SWGallery\Tests\Support\RenderFixtures;
use SWGallery\WordPress\Infrastructure\Services;

class RegisteredLayoutTrustTest extends SwgTestCase {

	use RenderFixtures;

	/** @var int Number of times the hostile layout was asked to render. */
	public static int $renders = 0;

	public function set_up(): void {
		parent::set_up();
		$this->purgeGalleries();
		self::$renders = 0;
		add_action(
			'swg_register_layouts',
			static function ( LayoutRegistryInterface $registry ): void {
				$registry->register( new class() implements GalleryLayoutInterface {
					public function getId(): string {
						return 'hostile';
					}
					public function getLabel(): string {
						return 'Hostile';
					}
					public function getSettingsSchema(): array {
						return array( 'type' => 'object', 'properties' => array() );
					}
					public function render( LayoutRenderRequest $request ): string {
						++RegisteredLayoutTrustTest::$renders;
						return '<script>window.pwned=1</script><div onclick="x()">layout markup</div>';
					}
				} );
			}
		);
		Services::reset();
	}

	public function tear_down(): void {
		remove_all_actions( 'swg_register_layouts' );
		wp_set_current_user( 0 );
		$this->purgeGalleries();
		Services::reset();
		parent::tear_down();
	}

	private function gallery( string $status ): int {
		$gallery = $this->makeGalleryPost( $this->makeUser( 'author' ), $status, '', 'hostile' );
		$this->addItems( $gallery, array( $this->makeImage( 'One' ) ) );
		return $gallery;
	}

	public function test_a_registered_layout_is_trusted_and_its_markup_is_emitted_as_returned(): void {
		$html = Services::gallery_renderer()->render( $this->gallery( 'publish' ), EntryPoint::Shortcode )->html;
		$this->assertStringContainsString( '<script>window.pwned=1</script>', $html, 'trusted code: the plugin does not sanitise layout markup' );
	}

	public function test_no_layout_code_runs_before_the_access_decision(): void {
		foreach ( array( 'draft', 'pending', 'private' ) as $status ) {
			$gallery = $this->gallery( $status );
			wp_set_current_user( 0 );
			Services::reset();
			$result = Services::gallery_renderer()->render( $gallery, EntryPoint::Shortcode );
			$this->assertSame( '', $result->html, $status );
		}
		$this->assertSame( 0, self::$renders, 'a layout never sees a gallery the visitor may not view' );
	}
}
