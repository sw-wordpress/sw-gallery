<?php
/**
 * The public registration actions: `swg_register_layouts` and `swg_register_import_providers` (M4-07, M4-08), including
 * contract validation, listener isolation and the containment of a failing third-party layout during render.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Contract\GalleryLayoutInterface;
use SWGallery\Core\Contract\ImportProviderRegistryInterface;
use SWGallery\Core\Contract\LayoutRegistryInterface;
use SWGallery\Tests\Support\FailingLayout;
use SWGallery\Tests\Support\InMemoryImportProvider;
use SWGallery\Tests\Support\RenderFixtures;
use SWGallery\Tests\Support\StubLayout;
use SWGallery\WordPress\Import\MediaLibraryProvider;
use SWGallery\WordPress\Infrastructure\Services;
use SWGallery\WordPress\Rendering\Layout\GridLayout;

class ExtensionPointsTest extends SwgTestCase {

	use RenderFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->purgeGalleries();
		Services::reset();
		Services::logger()->clear();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->purgeGalleries();
		Services::logger()->clear();
		Services::reset();
		parent::tear_down();
	}

	/** @return list<string> */
	private function logged_codes(): array {
		return array_reverse( array_column( Services::logger()->recent(), 'code' ) ); // Chronological (recent() is newest first).
	}

	public function test_the_layout_action_fires_once_per_request_after_the_built_ins_with_the_api_version(): void {
		$seen = array();
		add_action(
			'swg_register_layouts',
			static function ( $registry, $version ) use ( &$seen ): void {
				$seen[] = array( $registry instanceof LayoutRegistryInterface, $version, $registry->has( 'grid' ) );
			},
			10,
			2
		);

		Services::layouts();
		Services::layouts();

		$this->assertSame( array( array( true, GalleryLayoutInterface::API_VERSION, true ) ), $seen );
		$this->assertSame( 1, did_action( 'swg_register_layouts' ) );
	}

	public function test_a_third_party_layout_is_registered_and_listed_by_the_rest_route(): void {
		$schema = array(
			'type'       => 'object',
			'properties' => array( 'rows' => array( 'type' => 'integer' ) ),
		);
		add_action(
			'swg_register_layouts',
			static function ( LayoutRegistryInterface $registry ) use ( $schema ): void {
				$registry->register( new StubLayout( 'partner-wall', $schema ) );
			}
		);
		global $wp_rest_server;
		$wp_rest_server = null;
		rest_get_server();

		wp_set_current_user( $this->makeUser( 'editor' ) );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/swg/v1/layouts' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'grid', 'partner-wall' ), array_column( $data['layouts'], 'id' ) );
		$this->assertSame( 'Partner-wall', $data['layouts'][1]['label'] );
		$this->assertSame( $schema, $data['layouts'][1]['settingsSchema'] );
		$wp_rest_server = null;
	}

	public function test_layouts_that_break_the_contract_are_rejected_logged_and_never_break_the_request(): void {
		add_action(
			'swg_register_layouts',
			static function ( LayoutRegistryInterface $registry ): void {
				$registry->register( new StubLayout( 'Bad Id' ) );
				$registry->register( new StubLayout( 'empty-schema', array() ) );
				$registry->register( new FailingLayout( 'id' ) );
				$registry->register( new FailingLayout( 'schema', 'bad-schema' ) );
				$registry->register( new StubLayout( 'grid' ) ); // Duplicate of a built-in.
				$registry->register( new StubLayout( 'good-one' ) );
			}
		);

		$layouts = Services::layouts();

		$this->assertSame( array( 'grid', 'good-one' ), array_map( static fn( $l ) => $l->getId(), $layouts->all() ) );
		$this->assertInstanceOf( GridLayout::class, $layouts->get( 'grid' ), 'A built-in is never replaced.' );
		$this->assertSame( array_fill( 0, 5, 'swg_layout_rejected' ), $this->logged_codes() );
		$entry = Services::logger()->recent()[0];
		$this->assertSame( array( 'layout', 'exception' ), array_keys( $entry['context'] ) );
		$this->assertStringNotContainsString( 'secret', (string) wp_json_encode( Services::logger()->recent() ) );
	}

	public function test_a_throwing_registration_listener_is_isolated(): void {
		add_action(
			'swg_register_layouts',
			static function (): void {
				throw new RuntimeException( 'secret-token' );
			}
		);

		$layouts = Services::layouts();

		$this->assertTrue( $layouts->has( 'grid' ) );
		$this->assertSame( array( 'swg_listener_failed' ), $this->logged_codes() );
		$context = Services::logger()->recent()[0]['context'];
		$this->assertSame( 'swg_register_layouts', $context['hook'] );
		$this->assertSame( RuntimeException::class, $context['exception'] );
		$this->assertStringNotContainsString( 'secret', (string) wp_json_encode( Services::logger()->recent() ) );
	}

	public function test_a_listener_that_reads_the_registry_does_not_recurse(): void {
		add_action(
			'swg_register_layouts',
			static function (): void {
				Services::layouts()->all();
			}
		);

		$this->assertTrue( Services::layouts()->has( 'grid' ) );
		$this->assertSame( 1, did_action( 'swg_register_layouts' ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function failure_points(): array {
		return array(
			'render'      => array( 'render' ),
			'image hints' => array( 'hints' ),
		);
	}

	/**
	 * @dataProvider failure_points
	 */
	public function test_a_layout_that_fails_while_rendering_falls_back_to_grid_with_an_editor_only_diagnostic( string $fails_in ): void {
		add_action(
			'swg_register_layouts',
			static function ( LayoutRegistryInterface $registry ) use ( $fails_in ): void {
				$registry->register( new FailingLayout( $fails_in ) );
			}
		);
		$editor  = $this->makeUser( 'editor' );
		$gallery = $this->makeGalleryPost( $editor, 'publish', '', 'broken' );
		$this->addItems( $gallery, array( $this->makeImage( 'One' ), $this->makeImage( 'Two' ) ) );

		wp_set_current_user( $editor );
		Services::reset();
		$html = swg_render_gallery( $gallery );
		$this->assertStringContainsString( 'swg-layout-grid', $html, 'The grid renders the images.' );
		$this->assertStringContainsString( 'swg-diagnostic', $html );
		$this->assertStringContainsString( 'failed, the grid layout is used instead', $html );
		$this->assertStringNotContainsString( 'secret', $html );
		$this->assertContains( 'swg_layout_failed', $this->logged_codes() );

		wp_set_current_user( 0 );
		Services::reset();
		$visitor = swg_render_gallery( $gallery );
		$this->assertStringContainsString( 'swg-layout-grid', $visitor );
		$this->assertStringNotContainsString( 'swg-diagnostic', $visitor );
	}

	public function test_the_import_provider_action_registers_the_media_library_through_the_same_extension_point(): void {
		$registry = Services::import_providers();

		$this->assertTrue( $registry->has( MediaLibraryProvider::PROVIDER_ID ) );
		$this->assertSame( array( 'media-library' ), array_map( static fn( $p ) => $p->getProviderId(), $registry->all() ) );
		$this->assertSame( 1, did_action( 'swg_register_import_providers' ) );
		Services::import_providers();
		$this->assertSame( 1, did_action( 'swg_register_import_providers' ), 'Once per request.' );
	}

	public function test_third_party_providers_are_validated_and_isolated(): void {
		add_action(
			'swg_register_import_providers',
			static function ( ImportProviderRegistryInterface $registry ): void {
				$registry->register( new InMemoryImportProvider( 'my-cloud' ) );
				$registry->register( new InMemoryImportProvider( 'Bad Id' ) );
				$registry->register( new InMemoryImportProvider( 'media-library' ) ); // Duplicate of the built-in.
			}
		);
		add_action(
			'swg_register_import_providers',
			static function (): void {
				throw new RuntimeException( 'secret-token' );
			},
			20
		);

		$registry = Services::import_providers();

		$this->assertSame( array( 'media-library', 'my-cloud' ), array_map( static fn( $p ) => $p->getProviderId(), $registry->all() ) );
		$this->assertInstanceOf( MediaLibraryProvider::class, $registry->get( 'media-library' ) );
		$this->assertSame( array( 'swg_import_provider_rejected', 'swg_import_provider_rejected', 'swg_listener_failed' ), $this->logged_codes() );
		$this->assertStringNotContainsString( 'secret', (string) wp_json_encode( Services::logger()->recent() ) );
	}
}
