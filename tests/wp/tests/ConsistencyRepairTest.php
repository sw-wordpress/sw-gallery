<?php
/**
 * Consistency repair service, WP-CLI command handler and registrar against a real database.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Domain\Gallery\NewGallery;
use SWGallery\Tests\Support\WpFixtures;
use SWGallery\WordPress\Cli\CliOutputInterface;
use SWGallery\WordPress\Cli\CliRegistrar;
use SWGallery\WordPress\Cli\RepairCommand;
use SWGallery\WordPress\Cli\WpCliOutput;
use SWGallery\WordPress\Database\Schema;
use SWGallery\WordPress\Repair\ConsistencyRepair;
use SWGallery\WordPress\Repair\RepairReport;
use SWGallery\WordPress\Repository\PostMetaRevisionStore;
use SWGallery\WordPress\Repository\WpdbGalleryItemRepository;
use SWGallery\WordPress\Repository\WpdbGalleryRelations;
use SWGallery\WordPress\Repository\WpGalleryRepository;

// phpcs:disable Generic.Files.OneObjectStructurePerFile, Universal.Files.SeparateFunctionsFromOO, Squiz.Commenting

/** Records what a command prints. */
class SwgRecordingOutput implements CliOutputInterface {

	/** @var list<array{string, string}> */
	public array $messages = array();

	public function line( string $message ): void {
		$this->messages[] = array( 'line', $message );
	}

	public function success( string $message ): void {
		$this->messages[] = array( 'success', $message );
	}

	public function warning( string $message ): void {
		$this->messages[] = array( 'warning', $message );
	}

	public function error( string $message ): void {
		$this->messages[] = array( 'error', $message );
	}

	public function kinds(): array {
		return array_column( $this->messages, 0 );
	}

	public function text(): string {
		return implode( "\n", array_column( $this->messages, 1 ) );
	}
}

if ( ! class_exists( 'WP_CLI' ) ) {
	/** Minimal stand-in so the dynamic WP-CLI calls can be observed (the real class exists only inside WP-CLI). */
	class WP_CLI { // phpcs:ignore PEAR.NamingConventions.ValidClassName.Invalid, Generic.Classes.OpeningBraceSameLine

		/** @var list<array{string, string}> */
		public static array $calls = array();

		public static function __callStatic( string $name, array $arguments ): void {
			self::$calls[] = array( $name, (string) $arguments[0] );
		}
	}
}

class ConsistencyRepairTest extends SwgTestCase {

	use WpFixtures;

	private WpdbGalleryRelations $relations;
	private WpdbGalleryItemRepository $items;
	private WpGalleryRepository $repository;
	private ConsistencyRepair $repair;

	public function set_up(): void {
		parent::set_up();
		$this->purgeGalleries();
		$this->relations  = new WpdbGalleryRelations();
		$this->items      = new WpdbGalleryItemRepository();
		$this->repository = new WpGalleryRepository( $this->relations );
		$this->repair     = new ConsistencyRepair( $this->relations );
	}

	public function tear_down(): void {
		$this->purgeGalleries();
		parent::tear_down();
	}

	private function drop_unique_key(): void {
		global $wpdb;
		$this->use_real_ddl();
		$table = Schema::items_table();
		$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX gallery_attachment" ); // phpcs:ignore WordPress.DB
	}

	private function restore_unique_key(): void {
		global $wpdb;
		$table = Schema::items_table();
		$wpdb->query( "TRUNCATE TABLE `{$table}`" ); // phpcs:ignore WordPress.DB
		Schema::migrate();
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s AND non_unique = 0', $table, 'gallery_attachment' ) ), 'The unique key is back.' ); // phpcs:ignore WordPress.DB
	}

	private function insert_row( int $gallery_id, int $attachment_id ): int {
		global $wpdb;
		$wpdb->insert(
			Schema::items_table(),
			array(
				'gallery_id'    => $gallery_id,
				'attachment_id' => $attachment_id,
				'position'      => 1,
				'created_at'    => '2020-01-01 00:00:00',
				'updated_at'    => '2020-01-01 00:00:00',
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function test_a_consistent_site_reports_nothing_and_changes_nothing(): void {
		$gallery = $this->repository->create( new NewGallery( 'G', 1 ) );
		$image   = $this->makeAttachment();
		$this->items->append( $gallery->id, $image );
		$this->repository->update( $gallery->withCover( $image ) );

		$report = $this->repair->run( true );

		$this->assertSame( 0, $report->total_found() );
		$this->assertSame( 0, $report->total_fixed() );
		$this->assertTrue( $report->complete );
		$this->assertTrue( $report->applied );
		$this->assertSame( 1, $this->items->count( $gallery->id ) );
	}

	public function test_a_dry_run_is_the_default_and_only_reports(): void {
		$gallery = $this->repository->create( new NewGallery( 'G', 1 ) );
		$this->items->append( $gallery->id, 987201 );
		$this->items->append( 987202, 987203 );
		$this->repository->update( $gallery->withCover( 987204 ) );

		$report = $this->repair->run();

		$this->assertFalse( $report->applied );
		$this->assertSame( 1, $report->found[ RepairReport::ORPHAN_ATTACHMENT_ROWS ] );
		$this->assertSame( 1, $report->found[ RepairReport::ORPHAN_GALLERY_ROWS ] );
		$this->assertSame( 1, $report->found[ RepairReport::DANGLING_COVERS ] );
		$this->assertSame( 0, $report->found[ RepairReport::DUPLICATE_ROWS ] );
		$this->assertSame( 0, $report->total_fixed() );
		$this->assertSame( 2, $this->countItemRows(), 'Nothing was deleted.' );
		$this->assertSame( 987204, $this->repository->find( $gallery->id )?->coverAttachmentId );
		$this->assertSame( 'dry-run', $report->to_array()['mode'] );
	}

	public function test_apply_removes_orphans_and_dangling_covers_in_bounded_batches_but_never_attachments_or_galleries(): void {
		$revisions = new PostMetaRevisionStore();
		$gallery   = $this->repository->create( new NewGallery( 'G', 1 ) );
		$image     = $this->makeAttachment();
		$keep      = $this->items->append( $gallery->id, $image );
		for ( $i = 0; $i < 25; ++$i ) {
			$this->items->append( $gallery->id, 987300 + $i );
		}
		for ( $i = 0; $i < 7; ++$i ) {
			$this->items->append( 987400 + $i, $image );
		}
		$this->repository->update( $gallery->withCover( 987500 ) );
		$before = $revisions->current( $gallery->id )->value;

		$report = $this->repair->run( true, 10 );

		$this->assertSame( 25, $report->found[ RepairReport::ORPHAN_ATTACHMENT_ROWS ] );
		$this->assertSame( 25, $report->fixed[ RepairReport::ORPHAN_ATTACHMENT_ROWS ] );
		$this->assertSame( 7, $report->fixed[ RepairReport::ORPHAN_GALLERY_ROWS ] );
		$this->assertSame( 1, $report->fixed[ RepairReport::DANGLING_COVERS ] );
		$this->assertSame( 10, $report->batch_size );
		$this->assertCount( RepairReport::MAX_SAMPLES, $report->samples );
		$this->assertSame( array( $keep->id ), $this->items->orderedIds( $gallery->id ), 'The valid relation survives.' );
		$this->assertNull( $this->repository->find( $gallery->id )?->coverAttachmentId );
		$this->assertGreaterThan( $before, $revisions->current( $gallery->id )->value );
		$this->assertNotNull( get_post( $image ), 'Attachments are never deleted.' );
		$this->assertNotNull( $this->repository->find( $gallery->id ), 'Galleries are never deleted.' );
		$this->assertSame( 0, $this->repair->run( true )->total_found(), 'A second run finds nothing.' );
	}

	public function test_the_batch_size_is_clamped(): void {
		$this->assertSame( ConsistencyRepair::MIN_BATCH_SIZE, $this->repair->run( false, 1 )->batch_size );
		$this->assertSame( ConsistencyRepair::MAX_BATCH_SIZE, $this->repair->run( false, 10 ** 9 )->batch_size );
		$this->assertSame( ConsistencyRepair::DEFAULT_BATCH_SIZE, $this->repair->run()->batch_size );
	}

	public function test_duplicates_are_found_and_the_oldest_row_is_kept(): void {
		// Real galleries and attachments, so that only duplicates (no orphans) are in play.
		$g1 = $this->repository->create( new NewGallery( 'One', 1 ) )->id;
		$g2 = $this->repository->create( new NewGallery( 'Two', 1 ) )->id;
		$a1 = $this->makeAttachment();
		$a2 = $this->makeAttachment();
		$this->drop_unique_key();
		try {
			$first  = $this->insert_row( $g1, $a1 );
			$second = $this->insert_row( $g1, $a1 );
			$third  = $this->insert_row( $g1, $a1 );
			$other  = $this->insert_row( $g2, $a1 );
			$this->insert_row( $g1, $a2 );

			$groups = $this->relations->find_duplicates( 10 );
			$this->assertCount( 1, $groups );
			$this->assertSame( $first, $groups[0]['keep_id'] );
			$this->assertSame( array( $second, $third ), $groups[0]['extra_ids'] );

			$dry = $this->repair->run();
			$this->assertSame( 2, $dry->found[ RepairReport::DUPLICATE_ROWS ] );
			$this->assertSame( 5, $this->countItemRows() );

			$report = $this->repair->run( true );
			$this->assertSame( 2, $report->fixed[ RepairReport::DUPLICATE_ROWS ] );
			$this->assertSame( 3, $this->countItemRows() );
			$this->assertSame( array( $other ), $this->items->orderedIds( $g2 ) );
			$this->assertSame( array( $first ), array_values( array_intersect( $this->items->orderedIds( $g1 ), array( $first, $second, $third ) ) ) );
			$this->assertTrue( $report->complete );
		} finally {
			$this->restore_unique_key();
		}
	}

	public function test_a_full_dry_run_page_of_duplicates_is_reported_as_possibly_incomplete(): void {
		$this->drop_unique_key();
		try {
			for ( $i = 1; $i <= ConsistencyRepair::MIN_BATCH_SIZE; ++$i ) {
				$this->insert_row( 10, $i );
				$this->insert_row( 10, $i );
			}

			$this->assertFalse( $this->repair->run( false, ConsistencyRepair::MIN_BATCH_SIZE )->complete );
			$applied = $this->repair->run( true, ConsistencyRepair::MIN_BATCH_SIZE );
			$this->assertTrue( $applied->complete );
			$this->assertSame( ConsistencyRepair::MIN_BATCH_SIZE, $applied->fixed[ RepairReport::DUPLICATE_ROWS ] );
		} finally {
			$this->restore_unique_key();
		}
	}

	public function test_apply_reports_an_incomplete_scan_when_deleting_duplicates_makes_no_progress(): void {
		$this->drop_unique_key();
		try {
			$this->insert_row( 10, 101 );
			$this->insert_row( 10, 101 );
			$stuck = static fn( string $query ): string => str_starts_with( $query, 'DELETE FROM' ) && str_contains( $query, 'IN (' ) ? (string) preg_replace( '/IN \(.*\)/', 'IN (-1)', $query ) : $query;
			add_filter( 'query', $stuck );
			try {
				$report = $this->repair->run( true );
			} finally {
				remove_filter( 'query', $stuck );
			}

			$this->assertFalse( $report->complete );
			$this->assertSame( 0, $report->fixed[ RepairReport::DUPLICATE_ROWS ] );
		} finally {
			$this->restore_unique_key();
		}
	}

	public function test_the_command_defaults_to_a_dry_run_and_prints_the_report(): void {
		$this->items->append( 987600, 987601 );
		$out = new SwgRecordingOutput();

		( new RepairCommand( $this->repair, $out ) )( array(), array() );

		$this->assertSame( 1, $this->countItemRows(), 'Dry run: nothing deleted.' );
		$this->assertStringContainsString( 'dry run', $out->text() );
		$this->assertStringContainsString( 'Item relations of a missing gallery: 1 found, 0 fixed', $out->text() );
		$this->assertContains( 'warning', $out->kinds() );
		$this->assertStringContainsString( '--apply', $out->text() );
	}

	public function test_the_command_applies_with_the_flag_and_accepts_a_batch_size(): void {
		$this->items->append( 987600, 987601 );
		$out = new SwgRecordingOutput();

		( new RepairCommand( $this->repair, $out ) )( array(), array( 'apply' => true, 'batch-size' => '50' ) );

		$this->assertSame( 0, $this->countItemRows() );
		$this->assertStringContainsString( 'Mode: apply', $out->text() );
		$this->assertStringContainsString( '1 problem fixed.', $out->text() );
		$this->assertContains( 'success', $out->kinds() );
	}

	public function test_the_command_reports_a_clean_site(): void {
		$out = new SwgRecordingOutput();

		( new RepairCommand( $this->repair, $out ) )( array(), array( 'apply' => 'true' ) );

		$this->assertStringContainsString( 'No inconsistencies found.', $out->text() );
	}

	public function test_an_explicitly_disabled_flag_stays_a_dry_run(): void {
		$this->items->append( 987600, 987601 );

		foreach ( array( 'false', '0', false, 'no', array() ) as $value ) {
			( new RepairCommand( $this->repair, new SwgRecordingOutput() ) )( array(), array( 'apply' => $value ) );
		}

		$this->assertSame( 1, $this->countItemRows() );
	}

	public function test_the_command_rejects_bad_batch_sizes_and_formats_without_touching_data(): void {
		$this->items->append( 987600, 987601 );
		foreach ( array( '0', '9', '5001', '-5', 'abc', '1.5', array( 5 ), '' ) as $bad ) {
			$out = new SwgRecordingOutput();

			( new RepairCommand( $this->repair, $out ) )( array(), array( 'apply' => true, 'batch-size' => $bad ) );

			$this->assertSame( array( 'error' ), $out->kinds(), 'Batch size ' . wp_json_encode( $bad ) );
			$this->assertStringContainsString( 'from 10 to 5000', $out->text() );
		}
		$out = new SwgRecordingOutput();
		( new RepairCommand( $this->repair, $out ) )( array(), array( 'apply' => true, 'format' => 'xml' ) );

		$this->assertSame( array( 'error' ), $out->kinds() );
		$this->assertSame( 1, $this->countItemRows(), 'Invalid input never deletes.' );
	}

	public function test_the_json_format_prints_the_structured_report(): void {
		$this->items->append( 987600, 987601 );
		$out = new SwgRecordingOutput();

		( new RepairCommand( $this->repair, $out ) )( array(), array( 'format' => 'json' ) );

		$this->assertSame( array( 'line' ), $out->kinds() );
		$decoded = json_decode( $out->text(), true );
		$this->assertSame( 'dry-run', $decoded['mode'] );
		$this->assertSame( 1, $decoded['found'][ RepairReport::ORPHAN_GALLERY_ROWS ] );
		$this->assertTrue( $decoded['complete'] );
		$this->assertSame( array( 'type' => RepairReport::ORPHAN_GALLERY_ROWS, 'id' => $decoded['samples'][0]['id'] ), $decoded['samples'][0] );
	}

	public function test_the_command_warns_when_the_scan_was_cut_short(): void {
		$this->drop_unique_key();
		try {
			for ( $i = 1; $i <= ConsistencyRepair::MIN_BATCH_SIZE; ++$i ) {
				$this->insert_row( 10, $i );
				$this->insert_row( 10, $i );
			}
			$out = new SwgRecordingOutput();

			( new RepairCommand( $this->repair, $out ) )( array(), array( 'batch-size' => (string) ConsistencyRepair::MIN_BATCH_SIZE ) );

			$this->assertStringContainsString( 'safety limit', $out->text() );
		} finally {
			$this->restore_unique_key();
		}
	}

	public function test_the_command_builds_its_own_collaborators_by_default(): void {
		$command = new RepairCommand();

		$this->assertInstanceOf( RepairCommand::class, $command );
	}

	public function test_the_registrar_registers_the_command_only_inside_wp_cli(): void {
		$added   = array();
		$adder   = static function ( string $name, object $command ) use ( &$added ): void {
			$added[] = array( $name, $command::class );
		};
		$outside = new CliRegistrar( false, $adder );
		$outside->register();
		$this->assertFalse( has_action( 'cli_init', array( $outside, 'register_commands' ) ) );

		$inside = new CliRegistrar( true, $adder );
		$inside->register();
		$this->assertNotFalse( has_action( 'cli_init', array( $inside, 'register_commands' ) ) );
		$inside->register_commands();
		$this->assertSame( array( array( 'swg repair', RepairCommand::class ) ), $added );
	}

	public function test_the_registrar_reads_the_wp_cli_constant_by_default(): void {
		$registrar = new CliRegistrar();
		$registrar->register();

		$this->assertSame( defined( 'WP_CLI' ) && WP_CLI, false !== has_action( 'cli_init', array( $registrar, 'register_commands' ) ) );
	}

	public function test_the_default_command_adder_calls_wp_cli(): void {
		WP_CLI::$calls = array();

		( new CliRegistrar( true ) )->register_commands();

		$this->assertSame( 'add_command', WP_CLI::$calls[0][0] ?? null );
	}

	public function test_the_wp_cli_output_forwards_to_wp_cli(): void {
		WP_CLI::$calls = array();
		$output        = new WpCliOutput();

		$output->line( 'a' );
		$output->success( 'b' );
		$output->warning( 'c' );
		$output->error( 'd' );

		$this->assertSame(
			array(
				array( 'line', 'a' ),
				array( 'success', 'b' ),
				array( 'warning', 'c' ),
				array( 'error', 'd' ),
			),
			WP_CLI::$calls
		);
	}
}
