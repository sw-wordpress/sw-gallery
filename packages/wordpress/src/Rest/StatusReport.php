<?php
/**
 * Builds the System Status payload: versions, schema, bounded counts and recent errors, never secrets.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Rest;

use SWGallery\WordPress\Admin\AssetManifest;
use SWGallery\WordPress\Database\Schema;
use SWGallery\WordPress\Infrastructure\WpLogger;
use SWGallery\WordPress\PostTypes;
use SWGallery\WordPress\Repository\WpdbGalleryRelations;

/**
 * Every figure is bounded: the relation rows are counted up to ROW_CAP, the orphan scan runs only on tables that were
 * not capped and stops after SCAN_SECONDS, and the error list is capped. Whatever could not be determined completely
 * is reported with `complete: false` (or `capped: true`) instead of guessing. The report contains no file system
 * path, host name, credential or SQL.
 */
final class StatusReport {

	/** Relation rows are counted exactly up to this number. */
	public const ROW_CAP = 50000;

	/** Orphan scan: rows per query and time budget in seconds. */
	public const SCAN_BATCH   = 500;
	public const SCAN_SECONDS = 1.0;

	/** Number of recent errors returned. */
	public const MAX_ERRORS = 20;

	private const GALLERY_STATUSES = array( 'draft', 'pending', 'publish', 'private', 'future', 'trash' );

	public function __construct(
		private readonly WpLogger $logger,
		private readonly WpdbGalleryRelations $relations,
		private readonly string $assets_dir,
		private readonly int $row_cap = self::ROW_CAP,
		private readonly int $scan_batch = self::SCAN_BATCH,
		private readonly float $scan_seconds = self::SCAN_SECONDS
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function build(): array {
		global $wpdb;
		$stored  = (int) get_option( Schema::OPTION, 0 );
		$present = $this->items_table_present();
		$rows    = $present ? $this->relations->count_rows_bounded( $this->row_cap ) : array(
			'count'  => 0,
			'capped' => false,
		);

		return array(
			'schema'       => array(
				'stored'           => $stored,
				'current'          => Schema::CURRENT,
				'migrationPending' => $stored < Schema::CURRENT,
				'newerThanCode'    => $stored > Schema::CURRENT,
				'itemTablePresent' => $present,
			),
			'counts'       => array(
				'galleries'       => $this->gallery_counts(),
				'itemRows'        => $rows,
				'orphanRelations' => $this->orphans( $present, $rows['capped'] ),
			),
			'recentErrors' => $this->recent_errors(),
			'versions'     => array(
				'plugin'    => SWGALLERY_VERSION,
				'wordpress' => get_bloginfo( 'version' ),
				'php'       => PHP_VERSION,
				'database'  => array(
					'type'    => false !== stripos( (string) $wpdb->db_server_info(), 'mariadb' ) ? 'MariaDB' : 'MySQL',
					'version' => (string) $wpdb->db_version(),
				),
			),
			'multisite'    => is_multisite(),
			'cache'        => array( 'state' => 'none' ),
			'adminBuild'   => null !== ( new AssetManifest( $this->assets_dir ) )->resolve(),
			'hints'        => array(
				array(
					'key'     => 'export_limitation',
					'message' => __( 'Galleries are not exported in 1.0. WordPress export and migration tools move galleries without their images, and attachment IDs change on import. A gallery export with ID remapping is planned.', 'sw-gallery' ),
				),
			),
		);
	}

	private function items_table_present(): bool {
		global $wpdb;
		$table = Schema::items_table();
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- diagnostics need the live state, not a cache.
	}

	/**
	 * @return array<string, int>
	 */
	private function gallery_counts(): array {
		$counts = wp_count_posts( PostTypes::GALLERY );
		$result = array();
		foreach ( self::GALLERY_STATUSES as $status ) {
			$result[ $status ] = isset( $counts->$status ) ? (int) $counts->$status : 0;
		}
		return $result;
	}

	/**
	 * Read-only scan for relation rows whose gallery or attachment is gone (the same query the repair command uses).
	 *
	 * @return array{count: int|null, complete: bool}
	 */
	private function orphans( bool $table_present, bool $capped ): array {
		if ( ! $table_present || $capped ) {
			return array(
				'count'    => null,
				'complete' => false,
			);
		}
		$deadline = microtime( true ) + $this->scan_seconds;
		$after    = 0;
		$count    = 0;
		while ( true ) {
			$batch = $this->relations->find_orphans( $after, $this->scan_batch );
			if ( array() === $batch ) {
				return array(
					'count'    => $count,
					'complete' => true,
				);
			}
			foreach ( $batch as $row ) {
				$after = max( $after, $row['id'] );
				++$count;
			}
			if ( microtime( true ) >= $deadline ) {
				return array(
					'count'    => $count,
					'complete' => false,
				);
			}
		}
	}

	/**
	 * @return list<array{time: string, code: string, context: array<string, scalar|null>}>
	 */
	private function recent_errors(): array {
		$errors = array();
		foreach ( array_slice( $this->logger->recent(), 0, self::MAX_ERRORS ) as $entry ) {
			$time     = strtotime( $entry['time'] . ' UTC' );
			$errors[] = array(
				'time'    => false === $time ? $entry['time'] : gmdate( 'Y-m-d\TH:i:s\Z', $time ),
				'code'    => $entry['code'],
				'context' => $entry['context'],
			);
		}
		return $errors;
	}
}
