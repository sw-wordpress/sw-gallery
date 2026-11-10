<?php
/**
 * Revision counter in post meta against a real database.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Domain\Gallery\Revision;
use SWGallery\Core\Exception\RevisionConflictException;
use SWGallery\WordPress\PostTypes;
use SWGallery\WordPress\Repository\PostMetaRevisionStore;

class PostMetaRevisionStoreTest extends SwgTestCase {

	private PostMetaRevisionStore $store;
	private int $gallery_id;

	public function set_up(): void {
		parent::set_up();
		$this->store      = new PostMetaRevisionStore();
		$this->gallery_id = self::factory()->post->create( array( 'post_type' => PostTypes::GALLERY ) );
	}

	public function test_a_new_gallery_starts_at_revision_zero(): void {
		$this->assertSame( 0, $this->store->current( $this->gallery_id )->value );
		$this->assertSame( 0, $this->store->current( 999999 )->value );
	}

	public function test_compare_and_increment_counts_up(): void {
		$one = $this->store->compareAndIncrement( $this->gallery_id, new Revision( 0 ) );
		$two = $this->store->compareAndIncrement( $this->gallery_id, $one );
		$tri = $this->store->compareAndIncrement( $this->gallery_id, $two );

		$this->assertSame( array( 1, 2, 3 ), array( $one->value, $two->value, $tri->value ) );
		$this->assertSame( 3, $this->store->current( $this->gallery_id )->value );
		$this->assertSame( '3', get_post_meta( $this->gallery_id, PostTypes::META_REV, true ) );
	}

	public function test_a_stale_expected_revision_is_a_conflict_and_changes_nothing(): void {
		$this->store->compareAndIncrement( $this->gallery_id, new Revision( 0 ) );
		$this->store->compareAndIncrement( $this->gallery_id, new Revision( 1 ) );

		try {
			$this->store->compareAndIncrement( $this->gallery_id, new Revision( 1 ) );
			$this->fail( 'Expected a conflict.' );
		} catch ( RevisionConflictException $e ) {
			$this->assertSame( 1, $e->expected );
			$this->assertSame( 2, $e->actual );
			$this->assertSame( 'swg_revision_conflict', $e->errorCode() );
		}
		$this->assertSame( 2, $this->store->current( $this->gallery_id )->value );
	}

	public function test_a_concurrent_writer_between_read_and_update_is_detected(): void {
		global $wpdb;
		$this->store->compareAndIncrement( $this->gallery_id, new Revision( 0 ) );
		// Prime the object cache, then change the row behind its back (another request winning the race).
		$this->assertSame( 1, $this->store->current( $this->gallery_id )->value );
		$wpdb->update(
			$wpdb->postmeta,
			array( 'meta_value' => '5' ),
			array(
				'post_id'  => $this->gallery_id,
				'meta_key' => PostTypes::META_REV,
			)
		);

		$this->expectException( RevisionConflictException::class );
		$this->store->compareAndIncrement( $this->gallery_id, new Revision( 1 ) );
	}

	public function test_a_concurrently_created_first_revision_is_a_conflict(): void {
		// Another request creates the row between the existence check and the insert: the unique add fails.
		add_filter( 'add_post_metadata', '__return_false' );

		$this->expectException( RevisionConflictException::class );
		try {
			$this->store->compareAndIncrement( $this->gallery_id, new Revision( 0 ) );
		} finally {
			remove_filter( 'add_post_metadata', '__return_false' );
		}
	}

	public function test_the_first_revision_works_with_the_registered_default_of_zero(): void {
		$this->assertFalse( metadata_exists( 'post', $this->gallery_id, PostTypes::META_REV ) );
		$this->assertSame( 0, (int) get_post_meta( $this->gallery_id, PostTypes::META_REV, true ), 'Registered default is returned for a missing row.' );

		$this->assertSame( 1, $this->store->compareAndIncrement( $this->gallery_id, new Revision( 0 ) )->value );
	}
}
