<?php
/**
 * FU-17: `swg_gallery_changed` also fires for revision bumps outside the core events (attachment deleted, cover
 * cleared, repair run) — once per affected gallery and operation, isolated, and never on a dry run.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Domain\Gallery\NewGallery;
use SWGallery\Tests\Support\WpFixtures;
use SWGallery\WordPress\Gallery\RevisionBump;
use SWGallery\WordPress\Repair\ConsistencyRepair;
use SWGallery\WordPress\Repository\WpdbGalleryItemRepository;
use SWGallery\WordPress\Repository\WpdbGalleryRelations;
use SWGallery\WordPress\Repository\WpGalleryRepository;

class GalleryChangedCompletenessTest extends SwgTestCase {

	use WpFixtures;

	private WpGalleryRepository $repository;
	private WpdbGalleryItemRepository $items;
	/** @var list<int> */
	private array $fired = array();

	public function set_up(): void {
		parent::set_up();
		$this->purgeGalleries();
		$this->repository = new WpGalleryRepository( new WpdbGalleryRelations() );
		$this->items      = new WpdbGalleryItemRepository();
		$this->fired      = array();
		add_action(
			'swg_gallery_changed',
			function ( int $gallery_id ): void {
				$this->fired[] = $gallery_id;
			}
		);
	}

	public function tear_down(): void {
		$this->purgeGalleries();
		parent::tear_down();
	}

	public function test_deleting_an_attachment_used_in_three_galleries_fires_once_per_gallery(): void {
		$doomed = $this->makeAttachment();
		$other  = $this->makeAttachment();
		$g1     = $this->repository->create( new NewGallery( 'One', 1 ) );
		$g2     = $this->repository->create( new NewGallery( 'Two', 1 ) );
		$g3     = $this->repository->create( new NewGallery( 'Three', 1 ) );
		$unused = $this->repository->create( new NewGallery( 'Unused', 1 ) );
		foreach ( array( $g1, $g2, $g3 ) as $gallery ) {
			$this->items->append( $gallery->id, $doomed );
		}
		$this->items->append( $unused->id, $other );
		$this->repository->update( $g1->withCover( $doomed ) ); // Cover AND relation in the same gallery: still once.

		wp_delete_attachment( $doomed, true );

		$this->assertEqualsCanonicalizing( array( $g1->id, $g2->id, $g3->id ), $this->fired );
		$this->assertCount( 3, $this->fired, 'Once per gallery.' );
	}

	public function test_a_cover_only_gallery_is_announced_too(): void {
		$doomed  = $this->makeAttachment();
		$gallery = $this->repository->create( new NewGallery( 'Cover only', 1 ) );
		$this->repository->update( $gallery->withCover( $doomed ) );

		wp_delete_attachment( $doomed, true );

		$this->assertSame( array( $gallery->id ), $this->fired );
	}

	public function test_deleting_an_unused_attachment_fires_nothing(): void {
		$image = $this->makeAttachment();
		wp_delete_attachment( $image, true );
		$this->assertSame( array(), $this->fired );
	}

	public function test_repair_fires_per_fixed_cover_and_never_on_a_dry_run(): void {
		$relations = new WpdbGalleryRelations();
		$repair    = new ConsistencyRepair( $relations );
		$g1        = $this->repository->create( new NewGallery( 'One', 1 ) );
		$g2        = $this->repository->create( new NewGallery( 'Two', 1 ) );
		$healthy   = $this->repository->create( new NewGallery( 'Healthy', 1 ) );
		$image     = $this->makeAttachment();
		$this->items->append( $healthy->id, $image );
		$this->repository->update( $g1->withCover( 987601 ) );
		$this->repository->update( $g2->withCover( 987602 ) );
		$this->repository->update( $healthy->withCover( $image ) );

		$repair->run();
		$this->assertSame( array(), $this->fired, 'A dry run announces nothing.' );

		$repair->run( true );
		$this->assertEqualsCanonicalizing( array( $g1->id, $g2->id ), $this->fired );

		$this->fired = array();
		$repair->run( true );
		$this->assertSame( array(), $this->fired, 'A second run finds nothing to fix.' );
	}

	public function test_a_throwing_listener_does_not_break_the_bump_and_is_logged(): void {
		$gallery = $this->repository->create( new NewGallery( 'G', 1 ) );
		remove_all_actions( 'swg_gallery_changed' );
		add_action(
			'swg_gallery_changed',
			static function (): void {
				throw new RuntimeException( 'secret' );
			}
		);
		\SWGallery\WordPress\Infrastructure\Services::logger()->clear();

		$this->assertTrue( ( new RevisionBump() )->bump( $gallery->id ) );

		$recent = \SWGallery\WordPress\Infrastructure\Services::logger()->recent();
		$this->assertSame( 'swg_listener_failed', $recent[0]['code'] );
		$this->assertSame( 'swg_gallery_changed', $recent[0]['context']['hook'] );
		$this->assertStringNotContainsString( 'secret', (string) wp_json_encode( $recent ) );
		\SWGallery\WordPress\Infrastructure\Services::logger()->clear();
	}

	public function test_a_bump_of_something_that_is_not_a_gallery_fires_nothing(): void {
		$this->assertFalse( ( new RevisionBump() )->bump( $this->makeAttachment() ) );
		$this->assertFalse( ( new RevisionBump() )->bump( 0 ) );
		$this->assertSame( array(), $this->fired );
	}
}
