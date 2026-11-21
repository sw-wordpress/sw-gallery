<?php
/**
 * The public PHP API swg_render_gallery(): returns (never echoes), same access, allowlisted arguments.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Application\Render\EntryPoint;
use SWGallery\Tests\Support\RenderFixtures;
use SWGallery\WordPress\Infrastructure\Services;

class ApiFunctionTest extends SwgTestCase {

	use RenderFixtures;

	private int $author;

	public function set_up(): void {
		parent::set_up();
		$this->purgeGalleries();
		Services::reset();
		$this->author = $this->makeUser( 'author' );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->purgeGalleries();
		Services::reset();
		parent::tear_down();
	}

	private function gallery( string $status = 'publish', int $count = 3 ): int {
		$gallery = $this->makeGalleryPost( $this->author, $status );
		$images  = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$images[] = $this->makeImage( "Image $i" );
		}
		$this->addItems( $gallery, $images );
		return $gallery;
	}

	private function call( int $id, array $args = array(), int $user = 0 ): string {
		wp_set_current_user( $user );
		Services::reset();
		return swg_render_gallery( $id, $args );
	}

	public function test_the_function_is_declared_once_and_returns_without_echoing(): void {
		$id = $this->gallery();
		$this->assertTrue( function_exists( 'swg_render_gallery' ) );
		$this->assertSame( 'string', ( new ReflectionFunction( 'swg_render_gallery' ) )->getReturnType()?->getName() );

		ob_start();
		$html   = $this->call( $id );
		$echoed = ob_get_clean();

		$this->assertSame( '', $echoed );
		$this->assertStringContainsString( 'data-swg-gallery', $html );
		$this->assertSame( array( $id ), Services::asset_collector()->gallery_ids() );
	}

	public function test_an_invalid_id_returns_an_empty_string(): void {
		$this->assertSame( '', $this->call( 0 ) );
		$this->assertSame( '', $this->call( -3 ) );
		$this->assertSame( '', $this->call( 9999999 ) );
	}

	public function test_the_access_policy_is_shared_with_the_other_surfaces(): void {
		$draft = $this->gallery( 'draft' );

		$this->assertSame( '', $this->call( $draft ) );
		$this->assertStringContainsString( 'swg-diagnostic', $this->call( $draft, array(), $this->author ) );
		$this->assertSame( array(), Services::asset_collector()->gallery_ids() );
	}

	public function test_eager_false_lazy_loads_every_image_and_a_hostile_value_is_ignored(): void {
		$id = $this->gallery();

		$lazy = $this->call( $id, array( 'eager' => false ) );
		$this->assertStringNotContainsString( 'loading="eager"', $lazy );
		$this->assertStringNotContainsString( 'fetchpriority', $lazy );

		$this->assertSame( $this->call( $id ), $this->call( $id, array( 'eager' => 'maybe' ) ) );
		$this->assertStringNotContainsString( 'loading="eager"', $this->call( $id, array( 'eager' => '0' ) ) );
	}

	public function test_per_page_limits_the_page_size(): void {
		$id = $this->gallery();

		$this->assertStringContainsString( 'swg-pagination', $this->call( $id, array( 'per_page' => 1 ) ) );
		$this->assertStringNotContainsString( 'swg-pagination', $this->call( $id, array( 'per_page' => 200 ) ) );
	}

	public function test_hostile_and_unknown_arguments_are_ignored(): void {
		$id       = $this->gallery();
		$baseline = $this->call( $id );

		$hostile = array(
			'layout'   => '"><script>alert(1)</script>',
			'columns'  => array( 4 ),
			'gap'      => '9px',
			'page'     => -2,
			'per_page' => '1; DROP',
			'settings' => array( 'columns' => 99 ),
			'onclick'  => 'alert(1)',
		);
		$this->assertSame( $baseline, $this->call( $id, $hostile ) );
	}

	public function test_valid_arguments_match_the_renderer(): void {
		$id = $this->gallery();
		wp_set_current_user( 0 );
		Services::reset();
		$expected = Services::gallery_renderer()->html(
			$id,
			EntryPoint::PhpApi,
			array(
				'settings' => array(
					'columns' => 2,
					'gap'     => 0,
				),
				'eager'    => false,
			)
		);

		$this->assertSame( $expected, $this->call( $id, array( 'columns' => '2', 'gap' => 0, 'eager' => false ) ) );
		$this->assertStringContainsString( '--swg-columns:2;--swg-gap:0px', $expected );
	}
}
