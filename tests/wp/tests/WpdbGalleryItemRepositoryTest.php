<?php
/**
 * `$wpdb` gallery membership repository against a real database.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Domain\Gallery\GalleryItem;
use SWGallery\WordPress\Database\Schema;
use SWGallery\WordPress\Repository\WpdbGalleryItemRepository;

class WpdbGalleryItemRepositoryTest extends SwgTestCase {

	private WpdbGalleryItemRepository $repository;

	public function set_up(): void {
		parent::set_up();
		$this->repository = new WpdbGalleryItemRepository();
	}

	public function test_append_assigns_increasing_positions_per_gallery(): void {
		$first  = $this->repository->append( 10, 101 );
		$second = $this->repository->append( 10, 102 );
		$other  = $this->repository->append( 11, 101 );

		$this->assertInstanceOf( GalleryItem::class, $first );
		$this->assertSame( array( 1, 2, 1 ), array( $first->position, $second->position, $other->position ) );
		$this->assertGreaterThan( 0, $first->id );
		$this->assertSame( 10, $second->galleryId );
		$this->assertSame( 102, $second->attachmentId );
		$this->assertSame( 2, $this->repository->count( 10 ) );
		$this->assertSame( 1, $this->repository->count( 11 ) );
		$this->assertSame( 0, $this->repository->count( 12 ) );
	}

	public function test_the_same_attachment_cannot_be_added_twice_to_one_gallery(): void {
		global $wpdb;
		$this->repository->append( 10, 101 );

		$wpdb->suppress_errors( true );
		try {
			$this->repository->append( 10, 101 );
			$this->fail( 'A duplicate relation must be rejected.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 1, $this->repository->count( 10 ) );
		} finally {
			$wpdb->suppress_errors( false );
		}
	}

	public function test_find_page_orders_by_position_and_paginates(): void {
		$ids = array();
		foreach ( array( 101, 102, 103, 104, 105 ) as $attachment ) {
			$ids[] = $this->repository->append( 10, $attachment )->id;
		}

		$page = $this->repository->findPage( 10, 1, 2 );

		$this->assertSame( array( $ids[1], $ids[2] ), array_map( static fn( GalleryItem $item ): int => $item->id, $page ) );
		$this->assertSame( array( 2, 3 ), array_map( static fn( GalleryItem $item ): int => $item->position, $page ) );
		$this->assertCount( 5, $this->repository->findPage( 10, 0, 1000 ), 'The limit is clamped, not rejected.' );
		$this->assertCount( 1, $this->repository->findPage( 10, 0, 0 ), 'A limit below one is raised to one.' );
		$this->assertSame( array(), $this->repository->findPage( 10, 50, 10 ) );
		$this->assertSame( array(), $this->repository->findPage( 99, 0, 10 ) );
	}

	public function test_find_page_maps_overrides_and_keeps_null_for_missing_ones(): void {
		global $wpdb;
		$plain = $this->repository->append( 10, 101 );
		$named = $this->repository->append( 10, 102 );
		$wpdb->update(
			Schema::items_table(),
			array(
				'title_override' => 'Title',
				'alt_override'   => 'Alt',
			),
			array( 'id' => $named->id )
		);

		$items = $this->repository->findPage( 10, 0, 10 );

		$this->assertSame( $plain->id, $items[0]->id );
		$this->assertNull( $items[0]->overrides->title );
		$this->assertNull( $items[0]->overrides->caption );
		$this->assertNull( $items[0]->overrides->alt );
		$this->assertSame( 'Title', $items[1]->overrides->title );
		$this->assertNull( $items[1]->overrides->caption );
		$this->assertSame( 'Alt', $items[1]->overrides->alt );
	}

	public function test_ordered_ids_and_assigned_attachment_ids(): void {
		$a = $this->repository->append( 10, 101 )->id;
		$b = $this->repository->append( 10, 102 )->id;
		$this->repository->append( 11, 103 );

		$this->assertSame( array( $a, $b ), $this->repository->orderedIds( 10 ) );
		$this->assertSame( array(), $this->repository->orderedIds( 99 ) );
		$this->assertSame( array(), $this->repository->assignedAttachmentIds( 10, array() ) );
		$assigned = $this->repository->assignedAttachmentIds( 10, array( 101, 103, 999 ) );
		$this->assertSame( array( 101 ), $assigned, 'Only attachments of this gallery are reported.' );
	}

	public function test_remove_deletes_only_the_relation_of_the_given_gallery(): void {
		$item  = $this->repository->append( 10, 101 );
		$other = $this->repository->append( 11, 101 );

		$this->assertFalse( $this->repository->remove( 11, $item->id ), 'Wrong gallery: nothing removed.' );
		$this->assertTrue( $this->repository->remove( 10, $item->id ) );
		$this->assertFalse( $this->repository->remove( 10, $item->id ), 'Already removed.' );
		$this->assertSame( 0, $this->repository->count( 10 ) );
		$this->assertSame( array( $other->id ), $this->repository->orderedIds( 11 ) );
	}

	public function test_apply_order_rewrites_positions_from_one(): void {
		$a = $this->repository->append( 10, 101 )->id;
		$b = $this->repository->append( 10, 102 )->id;
		$c = $this->repository->append( 10, 103 )->id;

		$this->repository->applyOrder( 10, array( $c, $a, $b ) );

		$this->assertSame( array( $c, $a, $b ), $this->repository->orderedIds( 10 ) );
		$this->assertSame( array( 1, 2, 3 ), array_map( static fn( GalleryItem $item ): int => $item->position, $this->repository->findPage( 10, 0, 10 ) ) );
	}

	public function test_apply_order_ignores_items_of_other_galleries(): void {
		$a     = $this->repository->append( 10, 101 )->id;
		$other = $this->repository->append( 11, 101 )->id;

		$this->repository->applyOrder( 10, array( $other, $a ) );

		$this->assertSame( array( $other ), $this->repository->orderedIds( 11 ) );
		$this->assertSame( 1, $this->repository->findPage( 11, 0, 10 )[0]->position, 'Foreign item keeps its position.' );
	}

	public function test_apply_order_rolls_back_when_a_statement_fails(): void {
		global $wpdb;
		$a = $this->repository->append( 10, 101 )->id;
		$b = $this->repository->append( 10, 102 )->id;

		$fail = static function ( string $query ): string {
			return str_contains( $query, 'SET `position` = 2' ) ? 'SELECT * FROM swg_missing_table_for_rollback' : $query;
		};
		add_filter( 'query', $fail );
		$wpdb->suppress_errors( true );
		try {
			$this->repository->applyOrder( 10, array( $b, $a ) );
			$this->fail( 'A failed update must raise an exception.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'The order could not be stored.', $e->getMessage() );
		} finally {
			$wpdb->suppress_errors( false );
			remove_filter( 'query', $fail );
		}

		$this->assertSame( array( $a, $b ), $this->repository->orderedIds( 10 ), 'The first update was rolled back.' );
	}
}
