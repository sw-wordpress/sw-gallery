<?php
/**
 * Item override and settings stores on the real item table: NULL = inherit vs '' = blank, JSON settings.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Domain\Gallery\ItemOverrides;
use SWGallery\WordPress\Repository\WpdbGalleryItemRepository;

class ItemStoresTest extends SwgTestCase {

	private WpdbGalleryItemRepository $repository;
	private int $item_id;

	public function set_up(): void {
		parent::set_up();
		$this->repository = new WpdbGalleryItemRepository();
		$this->item_id    = $this->repository->append( 10, 101 )->id;
	}

	private function raw_overrides(): array {
		global $wpdb;
		$table = SWGallery\WordPress\Database\Schema::items_table();
		return (array) $wpdb->get_row( $wpdb->prepare( "SELECT title_override, caption_override, alt_override FROM `{$table}` WHERE id = %d", $this->item_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public function test_null_and_empty_string_overrides_round_trip_exactly(): void {
		$this->assertTrue( $this->repository->saveOverrides( 10, $this->item_id, new ItemOverrides( '', null, 'Alt' ) ) );

		$raw = $this->raw_overrides();
		$this->assertSame( '', $raw['title_override'], "'' stays an empty string in SQL." );
		$this->assertNull( $raw['caption_override'], 'null stays SQL NULL.' );
		$this->assertSame( 'Alt', $raw['alt_override'] );

		$page = $this->repository->findPage( 10, 0, 10 );
		$this->assertSame( '', $page[0]->overrides->title );
		$this->assertNull( $page[0]->overrides->caption );
		$this->assertSame( 'Alt', $page[0]->overrides->alt );
	}

	public function test_overrides_are_replaced_as_a_whole_and_can_be_reset_to_inherit(): void {
		$this->repository->saveOverrides( 10, $this->item_id, new ItemOverrides( 'T', 'C', 'A' ) );
		$this->assertTrue( $this->repository->saveOverrides( 10, $this->item_id, new ItemOverrides( null, null, null ) ) );

		$this->assertSame(
			array(
				'title_override'   => null,
				'caption_override' => null,
				'alt_override'     => null,
			),
			$this->raw_overrides()
		);
	}

	public function test_saving_identical_values_still_reports_that_the_item_exists(): void {
		$overrides = new ItemOverrides( 'Same', null, null );
		$this->assertTrue( $this->repository->saveOverrides( 10, $this->item_id, $overrides ) );
		$this->assertTrue( $this->repository->saveOverrides( 10, $this->item_id, $overrides ) );
	}

	public function test_unknown_or_foreign_items_report_false_and_stay_untouched(): void {
		$this->assertFalse( $this->repository->saveOverrides( 10, 999999, new ItemOverrides( 'x' ) ) );
		$this->assertFalse( $this->repository->saveOverrides( 11, $this->item_id, new ItemOverrides( 'x' ) ) );
		$this->assertFalse( $this->repository->saveItemSettings( 11, $this->item_id, array( 'a' => 1 ) ) );
		$this->assertNull( $this->raw_overrides()['title_override'] );
		$this->assertSame( array(), $this->repository->itemSettings( 11, $this->item_id ) );
	}

	public function test_settings_round_trip_and_keep_unknown_keys(): void {
		$settings = array(
			'fit'      => 'cover',
			'x-future' => array(
				'a' => array( 1, 2 ),
				'b' => null,
			),
			'zoom'     => 1.5,
			'flag'     => false,
		);

		$this->assertSame( array(), $this->repository->itemSettings( 10, $this->item_id ) );
		$this->assertTrue( $this->repository->saveItemSettings( 10, $this->item_id, $settings ) );
		$this->assertSame( $settings, $this->repository->itemSettings( 10, $this->item_id ) );
	}

	public function test_empty_settings_are_stored_as_sql_null(): void {
		global $wpdb;
		$this->repository->saveItemSettings( 10, $this->item_id, array( 'a' => 1 ) );

		$this->assertTrue( $this->repository->saveItemSettings( 10, $this->item_id, array() ) );

		$table = SWGallery\WordPress\Database\Schema::items_table();
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT settings FROM `{$table}` WHERE id = %d", $this->item_id ) ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( array(), $this->repository->itemSettings( 10, $this->item_id ) );
	}

	public function test_oversized_or_unencodable_settings_are_rejected_without_writing(): void {
		$this->repository->saveItemSettings( 10, $this->item_id, array( 'keep' => 1 ) );

		try {
			$this->repository->saveItemSettings( 10, $this->item_id, array( 'big' => str_repeat( 'a', WpdbGalleryItemRepository::MAX_SETTINGS_BYTES ) ) );
			$this->fail( 'Oversized settings must be rejected.' );
		} catch ( \LengthException $e ) {
			$this->assertSame( 'The item settings are too large.', $e->getMessage() );
		}
		try {
			$this->repository->saveItemSettings( 10, $this->item_id, array( 'bad' => NAN ) );
			$this->fail( 'NAN cannot be encoded.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( 'The item settings are not JSON compatible.', $e->getMessage() );
		}

		$this->assertSame( array( 'keep' => 1 ), $this->repository->itemSettings( 10, $this->item_id ) );
	}

	public function test_corrupt_stored_json_reads_as_empty_settings(): void {
		global $wpdb;
		$table = SWGallery\WordPress\Database\Schema::items_table();
		foreach ( array( '{broken', '"string"', '' ) as $stored ) {
			$wpdb->update( $table, array( 'settings' => $stored ), array( 'id' => $this->item_id ) );
			$this->assertSame( array(), $this->repository->itemSettings( 10, $this->item_id ) );
		}
	}

	public function test_a_failing_write_raises(): void {
		global $wpdb;
		$fail = static fn( string $query ): string => str_starts_with( $query, 'UPDATE' ) ? 'SELECT * FROM swg_missing_table_for_update' : $query;
		add_filter( 'query', $fail );
		$wpdb->suppress_errors( true );
		try {
			$this->repository->saveOverrides( 10, $this->item_id, new ItemOverrides( 'x' ) );
			$this->fail( 'A failed update must raise.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'The gallery item could not be stored.', $e->getMessage() );
		} finally {
			$wpdb->suppress_errors( false );
			remove_filter( 'query', $fail );
		}
	}
}
