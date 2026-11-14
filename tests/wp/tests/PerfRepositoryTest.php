<?php
/**
 * Statement counts of the repository operations that used to be O(n) (M2-07, M5-01): reorder, gallery list covers,
 * bulk assign. Always on (small fixtures); the large-gallery budgets are in PerfBudgetTest.
 *
 * @package sw-gallery
 */

require_once __DIR__ . '/../support/SwgSeeder.php';

use SWGallery\WordPress\Infrastructure\Services;
use SWGallery\WordPress\Repository\WpdbGalleryItemRepository;

class PerfRepositoryTest extends SwgTestCase {

	/** @var list<string> */
	private array $statements = array();

	public function set_up(): void {
		parent::set_up();
		Services::reset();
		add_filter( 'query', array( $this, 'record' ) );
	}

	public function tear_down(): void {
		remove_filter( 'query', array( $this, 'record' ) );
		global $wpdb;
		$wpdb->query( 'ROLLBACK' ); // SwgTestCase::tear_down() truncates (implicit commit): drop the fixtures first.
		parent::tear_down();
	}

	public function record( string $query ): string {
		$this->statements[] = $query;
		return $query;
	}

	/** @return list<string> UPDATE statements of the items table since the last reset. */
	private function updates(): array {
		return array_values( array_filter( $this->statements, static fn( string $q ): bool => str_starts_with( $q, 'UPDATE ' . \SWGallery\WordPress\Database\Schema::items_table() ) ) );
	}

	/** @return array<int, int> Item id => position. */
	private function positions( int $gallery ): array {
		global $wpdb;
		$table = \SWGallery\WordPress\Database\Schema::items_table();
		$out   = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, position FROM {$table} WHERE gallery_id = %d", $gallery ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB
			$out[ (int) $row['id'] ] = (int) $row['position'];
		}
		return $out;
	}

	public function test_a_single_swap_updates_only_the_two_moved_rows_in_one_statement(): void {
		$gallery = SwgSeeder::gallery( 'Swap', SwgSeeder::attachments( 50 ) );
		$ids     = Services::items()->orderedIds( $gallery );
		$order   = $ids;
		[ $order[10], $order[11] ] = array( $order[11], $order[10] );
		$this->statements          = array();

		Services::items()->applyOrder( $gallery, $order );

		$updates = $this->updates();
		$this->assertCount( 1, $updates, 'One CASE statement' );
		$this->assertStringContainsString( 'CASE id', $updates[0] );
		$this->assertSame( 2, substr_count( $updates[0], ' WHEN ' ), 'Only the two moved rows are written' );
		$this->assertSame( $order, Services::items()->orderedIds( $gallery ) );
	}

	public function test_a_complete_reversal_spans_chunks_and_makes_positions_contiguous(): void {
		$count   = WpdbGalleryItemRepository::ORDER_CHUNK * 2 + 37;
		$gallery = SwgSeeder::gallery( 'Reverse', SwgSeeder::attachments( $count ) );
		global $wpdb;
		$table = \SWGallery\WordPress\Database\Schema::items_table();
		// Stored positions with gaps (after removals): 10, 20, 30 ...
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET position = position * 10 WHERE gallery_id = %d", $gallery ) ); // phpcs:ignore WordPress.DB
		$order            = array_reverse( Services::items()->orderedIds( $gallery ) );
		$this->statements = array();

		Services::items()->applyOrder( $gallery, $order );

		$this->assertCount( 3, $this->updates(), 'ceil(2037 / 1000) statements, not one per item' );
		$this->assertSame( $order, Services::items()->orderedIds( $gallery ) );
		$this->assertSame( range( 1, $count ), $this->sorted_positions( $gallery ) );
	}

	/** @return list<int> */
	private function sorted_positions( int $gallery ): array {
		$positions = array_values( $this->positions( $gallery ) );
		sort( $positions );
		return $positions;
	}

	public function test_an_unchanged_order_writes_nothing(): void {
		$gallery          = SwgSeeder::gallery( 'Same', SwgSeeder::attachments( 20 ) );
		$order            = Services::items()->orderedIds( $gallery );
		$this->statements = array();

		Services::items()->applyOrder( $gallery, $order );

		$this->assertSame( array(), $this->updates() );
	}

	public function test_the_order_never_touches_other_galleries(): void {
		$pool  = SwgSeeder::attachments( 10 );
		$one   = SwgSeeder::gallery( 'One', $pool );
		$two   = SwgSeeder::gallery( 'Two', $pool );
		$other = $this->positions( $two );

		Services::items()->applyOrder( $one, array_reverse( Services::items()->orderedIds( $one ) ) );

		$this->assertSame( $other, $this->positions( $two ) );
	}

	public function test_the_gallery_list_resolves_covers_in_a_constant_number_of_queries(): void {
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$counts = array();
		foreach ( array( 5, 25 ) as $number ) {
			$pool = SwgSeeder::attachments( $number );
			foreach ( $pool as $index => $attachment ) {
				$id = SwgSeeder::gallery( "Cover $number-$index", array( $attachment ), $admin );
				update_post_meta( $id, '_thumbnail_id', $attachment );
			}
			wp_cache_flush();
			Services::reset();
			wp_set_current_user( $admin );
			global $wp_rest_server;
			$wp_rest_server = null;
			rest_get_server();
			$before = $GLOBALS['wpdb']->num_queries;
			$request = new WP_REST_Request( 'GET', '/swg/v1/galleries' );
			$request->set_query_params( array( 'per_page' => 100, 'search' => "Cover $number-" ) );
			$response = rest_do_request( $request );
			$this->assertSame( 200, $response->get_status() );
			$this->assertCount( $number, $response->get_data() );
			$this->assertNotNull( $response->get_data()[0]['coverUrl'] );
			$counts[ $number ] = $GLOBALS['wpdb']->num_queries - $before;
		}
		$this->assertLessThanOrEqual( 3, abs( $counts[25] - $counts[5] ), 'Queries for 5 and 25 covers: ' . wp_json_encode( $counts ) );
	}

	public function test_a_reorder_of_more_than_ten_thousand_ids_is_rejected_up_front(): void {
		$admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$gallery = SwgSeeder::gallery( 'Too big', array(), $admin );
		wp_set_current_user( $admin );
		global $wp_rest_server;
		$wp_rest_server = null;
		rest_get_server();
		$request = new WP_REST_Request( 'PUT', '/swg/v1/galleries/' . $gallery . '/order' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'itemIds' => range( 1, 10001 ), 'expectedRevision' => 0 ) ) );

		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
	}

	public function test_bulk_assign_does_not_query_every_attachment_for_the_access_check(): void {
		$admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$gallery = SwgSeeder::gallery( 'Assign', array(), $admin );
		wp_set_current_user( $admin );
		global $wp_rest_server;
		$wp_rest_server = null;
		rest_get_server();
		$counts = array();
		foreach ( array( 10, 40 ) as $number ) {
			$pool = SwgSeeder::attachments( $number );
			wp_cache_flush();
			Services::reset();
			$request = new WP_REST_Request( 'POST', '/swg/v1/galleries/' . $gallery . '/items' );
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( array( 'attachmentIds' => $pool, 'expectedRevision' => Services::revisions()->current( $gallery )->value ) ) );
			$before   = $GLOBALS['wpdb']->num_queries;
			$response = rest_do_request( $request );
			$this->assertSame( 200, $response->get_status() );
			$this->assertCount( $number, $response->get_data()['added'] );
			$counts[ $number ] = $GLOBALS['wpdb']->num_queries - $before;
		}
		// Two statements per appended item (position + insert), nothing per attachment on top.
		$this->assertLessThanOrEqual( 2 * 30 + 3, $counts[40] - $counts[10], 'Queries for 10 and 40 attachments: ' . wp_json_encode( $counts ) );
	}
}
