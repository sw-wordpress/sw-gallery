<?php
/**
 * The fixture seeder behind bin/seed.php: environment guard, bulk creation, usable output and purge.
 *
 * @package sw-gallery
 */

require_once __DIR__ . '/../support/SwgSeeder.php';

use SWGallery\Core\Application\Render\EntryPoint;
use SWGallery\WordPress\Infrastructure\Services;

class PerfSeederTest extends SwgTestCase {

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' ); // SwgTestCase::tear_down() truncates (implicit commit): drop the fixtures first.
		parent::tear_down();
	}

	public function test_the_guard_refuses_production_unless_forced(): void {
		$this->assertSame( 'production', wp_get_environment_type(), 'The test environment is not local.' );
		try {
			SwgSeeder::assert_allowed( false );
			$this->fail( 'Expected a refusal.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Refusing to seed', $e->getMessage() );
			$this->assertStringContainsString( '--force', $e->getMessage() );
		}
		SwgSeeder::assert_allowed( true );
		$this->assertTrue( true );
	}

	public function test_run_seeds_attachments_galleries_and_items_that_really_render(): void {
		$summary = SwgSeeder::run(
			array(
				'items'      => 25,
				'galleries'  => 2,
				'force'      => true,
				'with-files' => false,
			)
		);

		$this->assertSame( 25, $summary['attachments'] );
		$this->assertSame( 2, $summary['galleries'] );
		$this->assertSame( 50, $summary['items'] );
		$this->assertSame( 25, Services::items()->count( $summary['first_gallery'] ) );
		Services::reset();
		$result = Services::gallery_renderer()->render( $summary['first_gallery'], EntryPoint::Shortcode, array( 'per_page' => 10 ) );
		$this->assertSame( 10, $result->renderedItems );
		$this->assertSame( 3, $result->totalPages );
		$this->assertMatchesRegularExpression( '/<img [^>]*width="\d+" height="\d+"/', $result->html );
	}

	public function test_files_are_written_once_and_purge_removes_only_seeded_content(): void {
		$uploads = wp_upload_dir();
		$dir     = $uploads['basedir'] . '/' . SwgSeeder::UPLOAD_DIR;
		$keep    = self::factory()->post->create( array( 'post_title' => 'Seed gallery lookalike page' ) );
		$other   = self::factory()->attachment->create_object( 'own.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );

		SwgSeeder::run(
			array(
				'items'      => 7,
				'galleries'  => 1,
				'force'      => true,
				'with-files' => true,
			)
		);

		$this->assertFileExists( $dir . '/swg-seed-0.jpg' );
		$this->assertLessThan( 60000, (int) filesize( $dir . '/swg-seed-0.jpg' ), 'Tiny files.' );
		$removed = SwgSeeder::purge();
		$this->assertSame( 1, $removed['galleries'] );
		$this->assertSame( 7, $removed['attachments'] );
		$this->assertInstanceOf( WP_Post::class, get_post( $keep ), 'Content that is not seeded stays.' );
		$this->assertInstanceOf( WP_Post::class, get_post( $other ) );
		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $dir );
	}
}
