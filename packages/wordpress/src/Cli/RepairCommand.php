<?php
/**
 * `wp swg repair`: consistency repair of gallery data.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Cli;

use SWGallery\WordPress\Repair\ConsistencyRepair;
use SWGallery\WordPress\Repair\RepairReport;
use SWGallery\WordPress\Repository\WpdbGalleryRelations;

/**
 * Finds and fixes inconsistent gallery data: item relations whose gallery or attachment no longer exists, covers that
 * point to a deleted attachment and duplicate (gallery, attachment) rows. Attachments and galleries are never deleted.
 */
final class RepairCommand {

	private ConsistencyRepair $repair;
	private CliOutputInterface $out;

	public function __construct( ?ConsistencyRepair $repair = null, ?CliOutputInterface $out = null ) {
		$this->repair = $repair ?? new ConsistencyRepair( new WpdbGalleryRelations() );
		$this->out    = $out ?? new WpCliOutput();
	}

	/**
	 * Reports (dry run, default) or fixes inconsistent gallery data.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Remove the problems that were found. Without it nothing is changed.
	 *
	 * [--batch-size=<n>]
	 * : Rows handled per database batch (10 to 5000).
	 * ---
	 * default: 500
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp swg repair
	 *     wp swg repair --apply --batch-size=1000
	 *
	 * @param array<string>        $args       Positional arguments (none).
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );
		$batch = $assoc_args['batch-size'] ?? ConsistencyRepair::DEFAULT_BATCH_SIZE;
		if ( ! is_scalar( $batch ) || ! ctype_digit( (string) $batch ) || (int) $batch < ConsistencyRepair::MIN_BATCH_SIZE || (int) $batch > ConsistencyRepair::MAX_BATCH_SIZE ) {
			$this->out->error(
				sprintf(
					/* translators: 1: smallest batch size, 2: largest batch size */
					__( 'The batch size must be a whole number from %1$d to %2$d.', 'sw-gallery' ),
					ConsistencyRepair::MIN_BATCH_SIZE,
					ConsistencyRepair::MAX_BATCH_SIZE
				)
			);
			return;
		}
		$format = $assoc_args['format'] ?? 'text';
		if ( 'text' !== $format && 'json' !== $format ) {
			$this->out->error( __( 'The format must be "text" or "json".', 'sw-gallery' ) );
			return;
		}

		$report = $this->repair->run( $this->flag( $assoc_args['apply'] ?? false ), (int) $batch );

		if ( 'json' === $format ) {
			$this->out->line( (string) wp_json_encode( $report->to_array() ) );
			return;
		}
		$this->print_report( $report );
	}

	/**
	 * @param mixed $value Raw flag value (`--apply` is true, `--apply=false` is the string "false").
	 */
	private function flag( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		return is_scalar( $value ) && filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	private function print_report( RepairReport $report ): void {
		$this->out->line( $report->applied ? __( 'Mode: apply (problems are removed).', 'sw-gallery' ) : __( 'Mode: dry run (nothing is changed).', 'sw-gallery' ) );

		$labels = array(
			RepairReport::ORPHAN_GALLERY_ROWS    => __( 'Item relations of a missing gallery', 'sw-gallery' ),
			RepairReport::ORPHAN_ATTACHMENT_ROWS => __( 'Item relations of a missing attachment', 'sw-gallery' ),
			RepairReport::DANGLING_COVERS        => __( 'Covers of a missing attachment', 'sw-gallery' ),
			RepairReport::DUPLICATE_ROWS         => __( 'Duplicate item relations', 'sw-gallery' ),
		);
		foreach ( $labels as $category => $label ) {
			$this->out->line(
				sprintf(
					/* translators: 1: kind of problem, 2: number found, 3: number fixed */
					__( '%1$s: %2$d found, %3$d fixed', 'sw-gallery' ),
					$label,
					$report->found[ $category ],
					$report->fixed[ $category ]
				)
			);
		}
		if ( ! $report->complete ) {
			$this->out->warning( __( 'A safety limit stopped the scan early; run the command again to continue.', 'sw-gallery' ) );
		}
		if ( 0 === $report->total_found() ) {
			$this->out->success( __( 'No inconsistencies found.', 'sw-gallery' ) );
		} elseif ( ! $report->applied ) {
			$this->out->warning( __( 'Run again with --apply to remove these problems. Attachments and galleries are never deleted.', 'sw-gallery' ) );
		} else {
			$this->out->success(
				sprintf(
					/* translators: %d: number of fixed problems */
					_n( '%d problem fixed.', '%d problems fixed.', $report->total_fixed(), 'sw-gallery' ),
					$report->total_fixed()
				)
			);
		}
	}
}
