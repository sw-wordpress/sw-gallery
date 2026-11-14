<?php
/**
 * Performance budgets with real (seeded) data, M5-01. Assertions are on COUNTS (SQL queries, bytes, memory with generous
 * headroom); wall-clock time is only logged. `composer test:perf` runs the 5k and 10k variants (group `performance`),
 * the 1k smoke variant runs with the normal suite. Set SWG_PERF_LOG=/path to append one JSON line per measurement
 * (used for docs/performance.md).
 *
 * @package sw-gallery
 */

require_once __DIR__ . '/../support/SwgSeeder.php';

use SWGallery\Core\Application\Render\EntryPoint;
use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Gallery\Revision;
use SWGallery\Tests\Support\RestFixtures;
use SWGallery\WordPress\Infrastructure\Services;
use SWGallery\WordPress\PostTypes;

class PerfBudgetTest extends SwgTestCase {

	use RestFixtures;

	private int $admin;

	/** @var array<string, array<string, int|float>> */
	private array $report = array();

	public function set_up(): void {
		parent::set_up();
		$this->bootRest();
		$this->admin = $this->makeUser( 'administrator' );
	}

	public function tear_down(): void {
		global $wpdb;
		// SwgTestCase::tear_down() truncates the items table (DDL commits implicitly, which would keep ten thousand
		// seeded rows): roll the test transaction back first.
		$wpdb->query( 'ROLLBACK' );
		$this->shutdownRest();
		parent::tear_down();
	}

	public function test_budgets_for_1k_items(): void {
		$this->run_size( 1000, false );
	}

	/**
	 * @group performance
	 */
	public function test_budgets_for_5k_items(): void {
		$this->run_size( 5000, false );
	}

	/**
	 * @group performance
	 */
	public function test_budgets_for_10k_items(): void {
		$this->run_size( 10000, true );
	}

	/**
	 * @param int  $size         Items in the measured gallery.
	 * @param bool $gallery_list Also measure the list of 200 galleries with covers.
	 */
	private function run_size( int $size, bool $gallery_list ): void {
		$started     = microtime( true );
		$attachments = SwgSeeder::attachments( $size );
		$gallery     = SwgSeeder::gallery( 'Perf ' . $size, $attachments, $this->admin );
		fwrite( STDERR, sprintf( "\n[perf] seeded %d items in %.2fs\n", $size, microtime( true ) - $started ) );
		try {
			$this->assert_budgets( $size, $gallery, $gallery_list );
		} finally {
			$this->log_report( $size );
		}
	}

	private function assert_budgets( int $size, int $gallery, bool $gallery_list ): void {
		$per_page = 60;
		$pages    = (int) ceil( $size / $per_page );

		// Budgets are independent of the gallery size (they depend on the page size only); see docs/performance.md.
		$rest = $this->measure_read(
			$size,
			'rest_list_page1',
			function () use ( $gallery ): int {
				$response = $this->call( 'GET', '/galleries/' . $gallery . '/items', array( 'per_page' => 100 ), $this->admin );
				$this->assertSame( 200, $response->get_status() );
				$this->assertCount( 100, $response->get_data() );
				return strlen( (string) wp_json_encode( $response->get_data() ) );
			}
		);
		$this->assertLessThanOrEqual( 25, $rest['queries'], 'REST list page 1: ' . $this->describe( $rest ) );
		$this->assertLessThanOrEqual( 150000, $rest['bytes'], 'REST page bytes are bounded by per_page, not by gallery size' );
		$this->assertLessThanOrEqual( 24 * MB_IN_BYTES, $rest['memory'] );

		$rest_last = $this->measure_read(
			$size,
			'rest_list_last_page',
			function () use ( $gallery, $size ): int {
				$response = $this->call( 'GET', '/galleries/' . $gallery . '/items', array( 'per_page' => 100, 'page' => (int) ceil( $size / 100 ) ), $this->admin );
				$this->assertSame( 200, $response->get_status() );
				return strlen( (string) wp_json_encode( $response->get_data() ) );
			}
		);
		$this->assertLessThanOrEqual( 25, $rest_last['queries'], $this->describe( $rest_last ) );

		foreach ( array(
			'render_page1'        => 1,
			'render_page_later'   => max( 1, (int) floor( $pages / 2 ) ),
			'render_page_last'    => $pages,
		) as $name => $page ) {
			$render = $this->measure_read(
				$size,
				$name,
				function () use ( $gallery, $page, $per_page ): int {
					wp_set_current_user( 0 );
					Services::reset();
					wp_cache_flush();
					$result = Services::gallery_renderer()->render( $gallery, EntryPoint::Shortcode, array( 'page' => $page, 'per_page' => $per_page ) );
					$this->assertLessThanOrEqual( $per_page, $result->renderedItems );
					$this->assertGreaterThan( 0, $result->renderedItems );
					return strlen( $result->html );
				}
			);
			$this->assertLessThanOrEqual( 30, $render['queries'], $name . ': ' . $this->describe( $render ) );
			$this->assertLessThanOrEqual( 150000, $render['bytes'], 'Rendered HTML is bounded by per_page, not by gallery size' );
			$this->assertLessThanOrEqual( 24 * MB_IN_BYTES, $render['memory'] );
		}

		// Warm fragment cache: a hit needs no item query at all.
		wp_set_current_user( 0 );
		Services::reset();
		Services::gallery_renderer()->render( $gallery, EntryPoint::Shortcode, array( 'per_page' => $per_page ) );
		$hit = $this->measure_once(
			$size,
			'render_cache_hit',
			function () use ( $gallery, $per_page ): int {
				Services::reset();
				return strlen( Services::gallery_renderer()->render( $gallery, EntryPoint::Shortcode, array( 'per_page' => $per_page ) )->html );
			},
			false
		);
		$this->assertLessThanOrEqual( 6, $hit['queries'], 'A cache hit: ' . $this->describe( $hit ) );

		$this->measure_mutations( $size, $gallery );

		if ( $gallery_list ) {
			$this->measure_gallery_list( $size );
		}
	}

	private function measure_mutations( int $size, int $gallery ): void {
		// Work on a copy, so the shared gallery keeps its revision for the following measurements.
		$copy = $this->measure_once(
			$size,
			'duplicate_gallery',
			function () use ( $gallery ): int {
				$response = $this->call( 'POST', '/galleries/' . $gallery . '/duplicate', array(), $this->admin );
				$this->assertSame( 201, $response->get_status() );
				$this->copy_id = (int) $response->get_data()['id'];
				return strlen( (string) wp_json_encode( $response->get_data() ) );
			}
		);
		$this->assertLessThanOrEqual( 40, $copy['queries'], 'Duplicate is one INSERT..SELECT: ' . $this->describe( $copy ) );
		$this->assertSame( $size, Services::items()->count( $this->copy_id ) );

		$ids      = Services::items()->orderedIds( $this->copy_id );
		$revision = Services::revisions()->current( $this->copy_id )->value;
		// A single move: the last item to the front. The client sends the complete list (max 10000, S§F-13).
		$moved   = array_merge( array( $ids[ count( $ids ) - 1 ] ), array_slice( $ids, 0, -1 ) );
		$reorder = $this->measure_once(
			$size,
			'reorder_one_move',
			function () use ( $moved, $revision ): int {
				$response = $this->call( 'PUT', '/galleries/' . $this->copy_id . '/order', array( 'itemIds' => $moved, 'expectedRevision' => $revision ), $this->admin );
				$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
				return strlen( (string) wp_json_encode( $moved ) );
			}
		);
		$this->assertSame( $moved, Services::items()->orderedIds( $this->copy_id ), 'The new order is stored' );
		$this->assertLessThanOrEqual( 60, $reorder['queries'], 'Reorder is a bounded number of statements (CASE batches), not one per item: ' . $this->describe( $reorder ) );

		$fresh    = SwgSeeder::attachments( 100 );
		$revision = Services::revisions()->current( $this->copy_id )->value;
		$assign   = $this->measure_once(
			$size,
			'bulk_assign_100',
			function () use ( $fresh, $revision ): int {
				$response = $this->call( 'POST', '/galleries/' . $this->copy_id . '/items', array( 'attachmentIds' => $fresh, 'expectedRevision' => $revision ), $this->admin );
				$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
				return strlen( (string) wp_json_encode( $response->get_data() ) );
			}
		);
		$this->assertLessThanOrEqual( 450, $assign['queries'], 'Bulk assign of 100: ' . $this->describe( $assign ) );
		$this->assertSame( $size + 100, Services::items()->count( $this->copy_id ) );

		$revision = Services::revisions()->current( $this->copy_id )->value;
		$victim   = Services::items()->orderedIds( $this->copy_id )[ (int) ( $size / 2 ) ];
		$remove   = $this->measure_once(
			$size,
			'remove_one',
			function () use ( $victim, $revision ): int {
				$response = $this->call( 'DELETE', '/galleries/' . $this->copy_id . '/items/' . $victim, array(), $this->admin, array( 'expectedRevision' => $revision ) );
				$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
				return strlen( (string) wp_json_encode( $response->get_data() ) );
			}
		);
		$this->assertLessThanOrEqual( 40, $remove['queries'], 'Remove one: ' . $this->describe( $remove ) );
	}

	/** @var int */
	private int $copy_id = 0;

	/** 200 galleries with a cover each: the list resolves 200 cover URLs and 200 item counts. */
	private function measure_gallery_list( int $size ): void {
		$pool = SwgSeeder::attachments( 200 );
		foreach ( $pool as $index => $attachment ) {
			$id = SwgSeeder::gallery( 'List ' . $index, array( $attachment ), $this->admin );
			update_post_meta( $id, '_thumbnail_id', $attachment );
		}
		$list = $this->measure_read(
			$size,
			'gallery_list_200_with_covers',
			function (): int {
				$response = $this->call( 'GET', '/galleries', array( 'per_page' => 100 ), $this->admin );
				$this->assertSame( 200, $response->get_status() );
				$this->assertGreaterThanOrEqual( 100, count( $response->get_data() ) );
				return strlen( (string) wp_json_encode( $response->get_data() ) );
			}
		);
		$this->assertLessThanOrEqual( 20, $list['queries'], 'Gallery list (100 per page, 100 covers): ' . $this->describe( $list ) );
	}

	/**
	 * Runs a read operation 3 times from a cold object cache and keeps the median time; counts come from the first run.
	 *
	 * @param callable():int $operation Returns the response size in bytes.
	 * @return array<string, int|float>
	 */
	private function measure_read( int $size, string $name, callable $operation ): array {
		$times = array();
		$first = array();
		for ( $run = 0; $run < 3; $run++ ) {
			$result = $this->measure_once( $size, $name, $operation );
			$times[] = $result['ms'];
			$first   = array() === $first ? $result : $first;
		}
		sort( $times );
		$first['ms']            = $times[1];
		$this->report[ $name ] = $first;
		return $first;
	}

	/**
	 * @param callable():int $operation Returns the response size in bytes.
	 * @return array<string, int|float>
	 */
	private function measure_once( int $size, string $name, callable $operation, bool $cold = true ): array {
		global $wpdb;
		if ( $cold ) {
			wp_cache_flush();
		}
		if ( function_exists( 'memory_reset_peak_usage' ) ) {
			memory_reset_peak_usage();
		}
		$memory_before = memory_get_usage();
		$queries       = $wpdb->num_queries;
		$time          = microtime( true );
		$bytes         = $operation();
		$ms            = (int) round( ( microtime( true ) - $time ) * 1000 );
		$result        = array(
			'size'    => $size,
			'queries' => $wpdb->num_queries - $queries,
			'memory'  => max( memory_get_peak_usage(), memory_get_usage() ) - $memory_before,
			'bytes'   => $bytes,
			'ms'      => $ms,
		);
		$this->report[ $name ] = $result;
		return $result;
	}

	/**
	 * @param array<string, int|float> $result Measurement.
	 */
	private function describe( array $result ): string {
		return (string) wp_json_encode( $result );
	}

	private function log_report( int $size ): void {
		$lines = '';
		foreach ( $this->report as $name => $row ) {
			$lines .= wp_json_encode( array( 'op' => $name ) + $row ) . "\n";
			fwrite( STDERR, sprintf( "[perf] %5d %-30s queries=%-4d memory=%-9d bytes=%-8d ms=%d\n", $size, $name, $row['queries'], $row['memory'], $row['bytes'], $row['ms'] ) );
		}
		$path = getenv( 'SWG_PERF_LOG' );
		if ( is_string( $path ) && '' !== $path ) {
			file_put_contents( $path, $lines, FILE_APPEND );
		}
	}
}
