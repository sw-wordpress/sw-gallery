<?php
/**
 * MCP ability `sw-gallery/list-layouts`.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Mcp\Ability;

use SWGallery\Core\Contract\LayoutRegistryInterface;
use SWGallery\Core\Domain\Settings\SettingsSchemaValidator;
use SWGallery\Core\Exception\AccessDeniedException;
use SWGallery\WordPress\Mcp\AbilityDefinition;
use SWGallery\WordPress\Mcp\Paging;
use SWGallery\WordPress\Mcp\TierPolicy;
use SWGallery\WordPress\Rest\LayoutPresenter;

final class ListLayoutsAbility implements AbilityDefinition {

	use ReadAbilityTrait;

	private const PER_PAGE = 20;

	/**
	 * @param \Closure $layouts Lazy provider of the live layout registry (third-party layouts included).
	 * @phpstan-param \Closure():LayoutRegistryInterface $layouts
	 */
	public function __construct( private readonly \Closure $layouts ) {
	}

	public function name(): string {
		return 'list-layouts';
	}

	public function tier(): string {
		return TierPolicy::READ;
	}

	public function label(): string {
		return __( 'List layouts', 'sw-gallery' );
	}

	public function description(): string {
		return 'Lists the registered gallery layouts with their complete settings schemas (types, enums with labels, defaults, ranges, per-image settings). Use it to build valid layout and settings values. Settings marked x-responsive accept a scalar or a map of breakpoint values (breakpoints lists the keys). Follow the next field to continue.';
	}

	public function inputSchema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => Paging::inputProperties( self::PER_PAGE ),
		);
	}

	public function outputSchema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'fallbackId'  => array( 'type' => 'string' ),
				'breakpoints' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'layouts'     => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => array(
							'id'             => array( 'type' => 'string' ),
							'label'          => array( 'type' => 'string' ),
							'settingsSchema' => array(
								'type'                 => 'object',
								'additionalProperties' => true,
							),
						),
					),
				),
			) + Paging::outputProperties(),
		);
	}

	public function permission( array $input ): true|\WP_Error {
		if ( ! current_user_can( 'edit_posts' ) ) {
			throw new AccessDeniedException();
		}
		return true;
	}

	public function execute( array $input ): array|\WP_Error {
		$presenter = new LayoutPresenter();
		$records   = array();
		foreach ( ( $this->layouts )()->all() as $layout ) {
			$records[] = $presenter->layout( $layout );
		}
		$per_page = self::int( $input, 'per_page', self::PER_PAGE );
		$page     = max( 1, self::int( $input, 'page', 1 ) );
		$slice    = array_slice( $records, ( $page - 1 ) * $per_page, $per_page );
		return self::paged(
			$input,
			'layouts',
			$slice,
			count( $records ),
			self::PER_PAGE,
			array(
				'fallbackId'  => LayoutRegistryInterface::FALLBACK_ID,
				'breakpoints' => SettingsSchemaValidator::BREAKPOINTS,
			)
		);
	}
}
