<?php
/**
 * The paged render item query: sort modes (D3), skipping unusable attachments, bounded queries.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Application\Render\RenderItemPage;
use SWGallery\Core\Domain\Gallery\GalleryItem;
use SWGallery\Core\Domain\Gallery\SortMode;
use SWGallery\Tests\Support\RenderFixtures;
use SWGallery\WordPress\Infrastructure\Services;

class RenderItemSourceTest extends SwgTestCase {

	use RenderFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->purgeGalleries();
		Services::reset();
	}

	public function tear_down(): void {
		$this->purgeGalleries();
		Services::reset();
		parent::tear_down();
	}

	/**
	 * @return list<int> Attachment ids of the page.
	 */
	private function attachments( RenderItemPage $page ): array {
		return array_map( static fn( GalleryItem $item ): int => $item->attachmentId, $page->items );
	}

	/**
	 * Three images with distinct titles and upload dates, added in an order that matches neither.
	 *
	 * @return array{0: int, 1: array<string, int>} Gallery id and attachments by title.
	 */
	private function sortable_gallery(): array {
		$by   = array();
		$spec = array(
			'Banana' => '2026-03-01 10:00:00',
			'apple'  => '2026-01-01 10:00:00',
			'Cherry' => '2026-02-01 10:00:00',
		);
		foreach ( $spec as $title => $date ) {
			$by[ $title ] = $this->makeImage(
				$title,
				800,
				600,
				array(
					'post_date'     => $date,
					'post_date_gmt' => $date,
				)
			);
		}
		$gallery = $this->makeGalleryPost( 1 );
		$this->addItems( $gallery, array( $by['Banana'], $by['apple'], $by['Cherry'] ) );
		return array( $gallery, $by );
	}

	public function test_manual_mode_uses_the_stored_positions(): void {
		list( $gallery, $by ) = $this->sortable_gallery();

		$page = Services::items()->findRenderPage( $gallery, SortMode::Manual, 0, 10 );

		$this->assertSame( array( $by['Banana'], $by['apple'], $by['Cherry'] ), $this->attachments( $page ) );
		$this->assertSame( 3, $page->total );
	}

	public function test_date_modes_sort_by_the_attachment_upload_date(): void {
		list( $gallery, $by ) = $this->sortable_gallery();

		$asc  = Services::items()->findRenderPage( $gallery, SortMode::DateAsc, 0, 10 );
		$desc = Services::items()->findRenderPage( $gallery, SortMode::DateDesc, 0, 10 );

		$this->assertSame( array( $by['apple'], $by['Cherry'], $by['Banana'] ), $this->attachments( $asc ) );
		$this->assertSame( array( $by['Banana'], $by['Cherry'], $by['apple'] ), $this->attachments( $desc ) );
	}

	public function test_title_modes_sort_by_the_attachment_title_case_insensitively(): void {
		list( $gallery, $by ) = $this->sortable_gallery();

		$asc  = Services::items()->findRenderPage( $gallery, SortMode::TitleAsc, 0, 10 );
		$desc = Services::items()->findRenderPage( $gallery, SortMode::TitleDesc, 0, 10 );

		$this->assertSame( array( $by['apple'], $by['Banana'], $by['Cherry'] ), $this->attachments( $asc ) );
		$this->assertSame( array( $by['Cherry'], $by['Banana'], $by['apple'] ), $this->attachments( $desc ) );
	}

	public function test_ties_are_broken_by_ascending_item_id_in_both_directions(): void {
		$date = '2026-05-05 10:00:00';
		$ids  = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$ids[] = $this->makeImage(
				'Same',
				800,
				600,
				array(
					'post_date'     => $date,
					'post_date_gmt' => $date,
				)
			);
		}
		$gallery = $this->makeGalleryPost( 1 );
		$this->addItems( $gallery, array( $ids[2], $ids[0], $ids[3], $ids[1] ) );
		$expected = array( $ids[2], $ids[0], $ids[3], $ids[1] );

		foreach ( array( SortMode::DateAsc, SortMode::DateDesc, SortMode::TitleAsc, SortMode::TitleDesc ) as $mode ) {
			$this->assertSame( $expected, $this->attachments( Services::items()->findRenderPage( $gallery, $mode, 0, 10 ) ), $mode->value );
		}
	}

	public function test_sorting_never_touches_the_stored_positions(): void {
		list( $gallery, $by ) = $this->sortable_gallery();
		Services::items()->findRenderPage( $gallery, SortMode::TitleDesc, 0, 10 );

		$manual = Services::items()->findRenderPage( $gallery, SortMode::Manual, 0, 10 );

		$this->assertSame( array( 1, 2, 3 ), array_map( static fn( GalleryItem $i ): int => $i->position, $manual->items ) );
		$this->assertSame( $by['Banana'], $manual->items[0]->attachmentId );
	}

	public function test_paging_cuts_with_limit_and_offset_and_counts_the_whole_gallery(): void {
		$ids = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$ids[] = $this->makeImage( "Image $i" );
		}
		$gallery = $this->makeGalleryPost( 1 );
		$this->addItems( $gallery, $ids );

		$second = Services::items()->findRenderPage( $gallery, SortMode::Manual, 2, 2 );
		$past   = Services::items()->findRenderPage( $gallery, SortMode::Manual, 10, 2 );

		$this->assertSame( array( $ids[2], $ids[3] ), $this->attachments( $second ) );
		$this->assertSame( 5, $second->total );
		$this->assertSame( array(), $past->items );
		$this->assertSame( 5, $past->total );
	}

	public function test_missing_trashed_and_non_image_attachments_are_skipped_but_their_relations_stay(): void {
		$good    = $this->makeImage( 'Good' );
		$trashed = $this->makeImage( 'Trashed', 800, 600, array( 'post_status' => 'trash' ) );
		$pdf     = $this->makeImage( 'Pdf', 800, 600, array(), null, '', 'application/pdf' );
		$svg     = $this->makeImage( 'Svg', 800, 600, array(), null, '', 'image/svg+xml' );
		$missing = 987654;
		$gallery = $this->makeGalleryPost( 1 );
		$this->addItems( $gallery, array( $trashed, $good, $pdf, $missing, $svg ) );

		$page = Services::items()->findRenderPage( $gallery, SortMode::Manual, 0, 10 );

		$this->assertSame( array( $good ), $this->attachments( $page ) );
		$this->assertSame( 1, $page->total, 'Unusable items are not counted.' );
		$this->assertSame( 5, $this->itemRowCount( $gallery ), 'No relation was deleted.' );
	}

	public function test_overrides_travel_with_the_item_and_other_galleries_are_ignored(): void {
		$image   = $this->makeImage( 'One' );
		$gallery = $this->makeGalleryPost( 1 );
		$other   = $this->makeGalleryPost( 1 );
		$items   = $this->addItems( $gallery, array( $image ) );
		$this->addItems( $other, array( $image ) );
		Services::items()->saveOverrides( $gallery, $items[0], new \SWGallery\Core\Domain\Gallery\ItemOverrides( 'T', '', null ) );

		$page = Services::items()->findRenderPage( $gallery, SortMode::Manual, 0, 10 );

		$this->assertCount( 1, $page->items );
		$this->assertSame( 'T', $page->items[0]->overrides->title );
		$this->assertSame( '', $page->items[0]->overrides->caption );
		$this->assertNull( $page->items[0]->overrides->alt );
	}

	public function test_an_empty_gallery_answers_an_empty_page(): void {
		$page = Services::items()->findRenderPage( $this->makeGalleryPost( 1 ), SortMode::TitleAsc, 0, 10 );

		$this->assertSame( array(), $page->items );
		$this->assertSame( 0, $page->total );
	}

	public function test_the_limit_is_bounded(): void {
		$gallery = $this->makeGalleryPost( 1 );
		$this->addItems( $gallery, array( $this->makeImage() ) );

		$this->assertCount( 1, Services::items()->findRenderPage( $gallery, SortMode::Manual, -5, 100000 )->items );
		$this->assertCount( 1, Services::items()->findRenderPage( $gallery, SortMode::Manual, 0, 0 )->items );
	}

	public function test_the_query_count_does_not_grow_with_the_page_size(): void {
		global $wpdb;
		$counts = array();
		foreach ( array( 12, 100 ) as $size ) {
			$ids = array();
			for ( $i = 0; $i < $size; $i++ ) {
				$ids[] = $this->makeImage( "Bulk $size $i" );
			}
			$gallery = $this->makeGalleryPost( 1 );
			$this->addItems( $gallery, $ids );
			wp_cache_flush();
			$before = $wpdb->num_queries;
			$page   = Services::items()->findRenderPage( $gallery, SortMode::TitleAsc, 0, $size );
			Services::media()->findReferences( array_map( static fn( GalleryItem $i ): int => $i->attachmentId, $page->items ) );
			$counts[ $size ] = $wpdb->num_queries - $before;
			$this->assertCount( $size, $page->items );
		}

		$this->assertLessThanOrEqual( 1, abs( $counts[100] - $counts[12] ), 'Queries for 12 and 100 items: ' . wp_json_encode( $counts ) );
		$this->assertLessThanOrEqual( 6, $counts[100] );
	}
}
