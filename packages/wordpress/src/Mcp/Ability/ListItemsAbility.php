<?php
/**
 * MCP ability `sw-gallery/list-items`.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Mcp\Ability;

use SWGallery\WordPress\Gallery\ItemReader;
use SWGallery\WordPress\Mcp\AbilityDefinition;
use SWGallery\WordPress\Mcp\GallerySchema;
use SWGallery\WordPress\Mcp\ItemSchema;
use SWGallery\WordPress\Mcp\Paging;
use SWGallery\WordPress\Mcp\TierPolicy;
use SWGallery\Core\Exception\AccessDeniedException;

final class ListItemsAbility implements AbilityDefinition {

	use ReadAbilityTrait;

	private const PER_PAGE = 50;

	/**
	 * @param \Closure $reader Lazy provider of the {@see ItemReader}.
	 * @phpstan-param \Closure():ItemReader $reader
	 */
	public function __construct( private readonly \Closure $reader ) {
	}

	public function name(): string {
		return 'list-items';
	}

	public function tier(): string {
		return TierPolicy::READ;
	}

	public function label(): string {
		return __( 'List gallery items', 'sw-gallery' );
	}

	public function description(): string {
		return 'Lists the images of a gallery in their stored manual order with text overrides, effective texts and per-image settings, plus the gallery revision. Items marked missing have no usable attachment. Follow the next field to continue.';
	}

	public function inputSchema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'id' ),
			'properties'           => array( 'id' => GallerySchema::id() ) + Paging::inputProperties( self::PER_PAGE ),
		);
	}

	public function outputSchema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'items'    => array(
					'type'  => 'array',
					'items' => ItemSchema::output(),
				),
				'revision' => array( 'type' => 'integer' ),
			) + Paging::outputProperties(),
		);
	}

	public function permission( array $input ): true|\WP_Error {
		if ( ! current_user_can( 'edit_posts' ) ) {
			throw new AccessDeniedException();
		}
		// A missing or hidden gallery is the same not-found answer as on every other route.
		( $this->reader )()->page( $this->actor(), self::int( $input, 'id' ), 1, 1 );
		return true;
	}

	public function execute( array $input ): array|\WP_Error {
		$found = ( $this->reader )()->page( $this->actor(), self::int( $input, 'id' ), self::int( $input, 'page', 1 ), self::int( $input, 'per_page', self::PER_PAGE ) );
		$items = array_map( array( ItemSchema::class, 'project' ), $found['items'] );
		return self::paged( $input, 'items', $items, $found['total'], self::PER_PAGE, array( 'revision' => $found['revision'] ) );
	}
}
