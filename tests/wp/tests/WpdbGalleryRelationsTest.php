<?php
/**
 * Bulk relation operations and integrity queries against a real database.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Domain\Gallery\NewGallery;
use SWGallery\Tests\Support\WpFixtures;
use SWGallery\WordPress\Database\Schema;
use SWGallery\WordPress\PostTypes;
use SWGallery\WordPress\Repository\WpdbGalleryItemRepository;
use SWGallery\WordPress\Repository\WpdbGalleryRelations;
use SWGallery\WordPress\Repository\WpGalleryRepository;

class WpdbGalleryRelationsTest extends SwgTestCase {

	use WpFixtures;

	private WpdbGalleryRelations $relations;
	private WpdbGalleryItemRepository $items;

	public function set_up(): void {
		parent::set_up();
		$this->purgeGalleries();
		$this->relations = new WpdbGalleryRelations();
		$this->items     = new WpdbGalleryItemRepository();
	}

	public function tear_down(): void {
		$this->purgeGalleries();
		parent::tear_down();
	}

	public function test_count_by_gallery_answers_with_one_grouped_query_and_reports_empty_galleries(): void {
		global $wpdb;
		$this->items->append( 10, 101 );
		$this->items->append( 10, 102 );
		$this->items->append( 11, 101 );

		$before = $wpdb->num_queries;
		$counts = $this->relations->count_by_gallery( array( 10, 11, 12, 10, 0, -3 ) );

		$this->assertSame( 1, $wpdb->num_queries - $before );
		$this->assertSame(
			array(
				10 => 2,
				11 => 1,
				12 => 0,
			),
			$counts
		);
		$this->assertSame( array(), $this->relations->count_by_gallery( array() ) );
		$this->assertSame( array(), $this->relations->count_by_gallery( array( 0, -1 ) ) );
	}

	public function test_delete_by_gallery_and_by_attachment_are_scoped_and_idempotent(): void {
		$this->items->append( 10, 101 );
		$this->items->append( 10, 102 );
		$this->items->append( 11, 101 );
		$this->items->append( 12, 103 );

		$this->assertSame( 2, $this->relations->delete_by_gallery( 10 ) );
		$this->assertSame( 0, $this->relations->delete_by_gallery( 10 ), 'Repeating is harmless.' );
		$this->assertSame( 1, $this->relations->delete_by_attachment( 101 ) );
		$this->assertSame( 0, $this->relations->delete_by_attachment( 101 ) );
		$this->assertSame( 1, $this->items->count( 12 ) );
		$this->assertSame( 0, $this->relations->delete_by_gallery( 0 ) );
		$this->assertSame( 0, $this->relations->delete_by_attachment( -1 ) );
		$this->assertSame( 1, $this->countItemRows() );
	}

	public function test_deletes_run_in_bounded_batches(): void {
		global $wpdb;
		$table = Schema::items_table();
		$now   = '2020-01-01 00:00:00';
		$total = WpdbGalleryRelations::DELETE_BATCH + 7;
		$rows  = array();
		for ( $i = 1; $i <= $total; ++$i ) {
			$rows[] = $wpdb->prepare( '(%d, %d, %d, %s, %s)', 77, $i, $i, $now, $now );
		}
		$wpdb->query( "INSERT INTO {$table} (gallery_id, attachment_id, position, created_at, updated_at) VALUES " . implode( ',', $rows ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertSame( $total, $this->relations->delete_by_gallery( 77 ) );
		$this->assertSame( 0, $this->items->count( 77 ) );
	}

	public function test_gallery_ids_with_attachment_lists_each_gallery_once(): void {
		$this->items->append( 10, 101 );
		$this->items->append( 12, 101 );
		$this->items->append( 11, 102 );

		$this->assertSame( array( 10, 12 ), $this->relations->gallery_ids_with_attachment( 101 ) );
		$this->assertSame( array( 10 ), $this->relations->gallery_ids_with_attachment( 101, 1 ) );
		$this->assertSame( array(), $this->relations->gallery_ids_with_attachment( 999 ) );
	}

	public function test_copy_membership_of_an_empty_gallery_copies_nothing(): void {
		$this->assertSame( 0, $this->relations->copy_membership( 10, 11 ) );
		$this->items->append( 10, 101 );
		$this->assertSame( 1, $this->relations->copy_membership( 10, 11 ) );
		$this->assertSame( array( 101 ), $this->items->assignedAttachmentIds( 11, array( 101 ) ) );
	}

	public function test_find_orphans_reports_missing_galleries_and_attachments_in_pages(): void {
		$gallery = ( new WpGalleryRepository( $this->relations ) )->create( new NewGallery( 'G', 1 ) );
		$image   = $this->makeAttachment();
		$ok      = $this->items->append( $gallery->id, $image );
		$no_att  = $this->items->append( $gallery->id, 987001 );
		$no_gal  = $this->items->append( 987002, $image );
		$neither = $this->items->append( 987003, 987004 );
		$post    = self::factory()->post->create();
		$not_att = $this->items->append( $gallery->id, $post );
		$not_gal = $this->items->append( $post, $image );

		$all = $this->relations->find_orphans( 0, 100 );

		$this->assertSame(
			array(
				$no_att->id  => 'attachment_missing',
				$no_gal->id  => 'gallery_missing',
				$neither->id => 'gallery_missing',
				$not_att->id => 'attachment_missing',
				$not_gal->id => 'gallery_missing',
			),
			array_column( $all, 'reason', 'id' )
		);
		$this->assertNotContains( $ok->id, array_column( $all, 'id' ) );
		$first = $this->relations->find_orphans( 0, 2 );
		$this->assertCount( 2, $first );
		$this->assertSame( array( $neither->id, $not_att->id, $not_gal->id ), array_column( $this->relations->find_orphans( $no_gal->id, 100 ), 'id' ) );
		$this->assertSame( $gallery->id, $all[0]['gallery_id'] );
		$this->assertSame( 987001, $all[0]['attachment_id'] );
	}

	public function test_find_orphans_keeps_relations_of_trashed_galleries_and_trashed_attachments(): void {
		$repository = new WpGalleryRepository( $this->relations );
		$gallery    = $repository->create( new NewGallery( 'G', 1 ) );
		$image      = $this->makeAttachment( 0, 'image/jpeg', array( 'post_status' => 'trash' ) );
		$this->items->append( $gallery->id, $image );
		$repository->trash( $gallery->id );

		$this->assertSame( array(), $this->relations->find_orphans( 0, 100 ) );
	}

	public function test_delete_by_ids_only_removes_the_given_rows(): void {
		$a = $this->items->append( 10, 101 );
		$b = $this->items->append( 10, 102 );
		$this->items->append( 10, 103 );

		$this->assertSame( 2, $this->relations->delete_by_ids( array( $a->id, $b->id, 0, -2, $a->id ) ) );
		$this->assertSame( 0, $this->relations->delete_by_ids( array() ) );
		$this->assertSame( 0, $this->relations->delete_by_ids( array( 0 ) ) );
		$this->assertSame( 1, $this->items->count( 10 ) );
	}

	public function test_find_duplicates_and_dangling_covers(): void {
		global $wpdb;
		$repository = new WpGalleryRepository( $this->relations );
		$gallery    = $repository->create( new NewGallery( 'G', 1 ) );
		$image      = $this->makeAttachment();
		$repository->update( $gallery->withCover( 987005 ) );
		$other = $repository->create( new NewGallery( 'Good cover', 1 ) );
		$repository->update( $other->withCover( $image ) );

		$covers = $this->relations->find_dangling_covers( 0, 100 );

		$this->assertSame( array( $gallery->id ), array_column( $covers, 'gallery_id' ) );
		$this->assertSame( 987005, $covers[0]['attachment_id'] );
		$this->assertSame( array(), $this->relations->find_dangling_covers( $covers[0]['meta_id'], 100 ) );
		$this->assertSame( array(), $this->relations->find_duplicates( 10 ), 'The unique key prevents duplicates.' );
		$this->assertTrue( (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s', Schema::items_table(), 'gallery_attachment' ) ) );
		$this->assertSame( PostTypes::GALLERY, get_post_type( $gallery->id ) );
	}
}
