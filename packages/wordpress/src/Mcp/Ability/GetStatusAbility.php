<?php
/**
 * MCP ability `sw-gallery/get-status`.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Mcp\Ability;

use SWGallery\WordPress\Mcp\AbilityDefinition;
use SWGallery\WordPress\Rest\StatusReport;

final class GetStatusAbility implements AbilityDefinition {

	use AdminPermissionTrait;
	use ReadAnnotationsTrait;

	/**
	 * @param \Closure $report Lazy status report provider.
	 * @phpstan-param \Closure():StatusReport $report
	 */
	public function __construct( private readonly \Closure $report ) {
	}

	public function name(): string {
		return 'get-status';
	}

	public function label(): string {
		return __( 'Get gallery system status', 'sw-gallery' );
	}

	public function description(): string {
		return 'Returns SW Gallery schema status, counts, software versions, recent error codes and diagnostic hint keys.';
	}

	public function inputSchema(): array {
		return self::object( array() );
	}

	public function outputSchema(): array {
		$int  = array( 'type' => 'integer' );
		$bool = array( 'type' => 'boolean' );
		$text = array( 'type' => 'string' );
		return self::object(
			array(
				'schema'       => self::object(
					array(
						'stored'           => $int,
						'current'          => $int,
						'migrationPending' => $bool,
						'newerThanCode'    => $bool,
						'itemTablePresent' => $bool,
					)
				),
				'counts'       => self::object(
					array(
						'galleries'       => self::object( array_fill_keys( StatusReport::GALLERY_STATUSES, $int ) ),
						'itemRows'        => self::object(
							array(
								'count'  => $int,
								'capped' => $bool,
							)
						),
						'orphanRelations' => self::object(
							array(
								'count'    => array( 'type' => array( 'integer', 'null' ) ),
								'complete' => $bool,
							)
						),
					)
				),
				'versions'     => self::object(
					array(
						'plugin'    => $text,
						'wordpress' => $text,
						'php'       => $text,
						'database'  => self::object(
							array(
								'type'    => $text,
								'version' => $text,
							)
						),
					)
				),
				'recentErrors' => array(
					'type'     => 'array',
					'maxItems' => StatusReport::MAX_ERRORS,
					'items'    => self::object(
						array(
							'time' => $text,
							'code' => $text,
						)
					),
				),
				'hints'        => array(
					'type'  => 'array',
					'items' => $text,
				),
			)
		);
	}

	public function execute( array $input ): array {
		$report = ( $this->report )()->build();
		return array(
			'schema'       => $report['schema'],
			'counts'       => $report['counts'],
			'versions'     => $report['versions'],
			'recentErrors' => array_map(
				static fn( array $error ): array => array(
					'time' => $error['time'],
					'code' => $error['code'],
				),
				$report['recentErrors']
			),
			'hints'        => array_column( $report['hints'], 'key' ),
		);
	}

	/**
	 * @param array<string, mixed> $properties Schema for each available field.
	 * @return array<string, mixed>
	 */
	private static function object( array $properties ): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $properties,
		);
	}
}
