<?php
/**
 * MCP ability `sw-gallery/reorder-items`.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the framework independent core's value objects expose camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Mcp\Ability;

use SWGallery\WordPress\Mcp\AbilityDefinition;
use SWGallery\WordPress\Mcp\GallerySchema;
use SWGallery\WordPress\Mcp\ItemSchema;
use SWGallery\WordPress\Rest\ItemController;

final class ReorderItemsAbility implements AbilityDefinition {

	use ItemWriteAbilityTrait;

	public function name(): string {
		return 'reorder-items';
	}

	public function label(): string {
		return __( 'Reorder gallery items', 'sw-gallery' );
	}

	public function description(): string {
		return 'Sets the manual order of a gallery the user may edit. itemIds must be the complete list of the gallery item ids (see list-items) in the new order; anything else is rejected and nothing changes. The write is atomic. Returns the item count and the new revision.';
	}

	public function inputSchema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'id', 'itemIds', 'expectedRevision' ),
			'properties'           => array(
				'id'               => GallerySchema::id(),
				'itemIds'          => array(
					'description' => 'All item ids of the gallery in the new order.',
					'type'        => 'array',
					'maxItems'    => ItemController::MAX_ORDER,
					'items'       => ItemSchema::itemId(),
				),
				'expectedRevision' => GallerySchema::expectedRevision(),
			),
		);
	}

	public function outputSchema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'itemCount' => array( 'type' => 'integer' ),
				'revision'  => array( 'type' => 'integer' ),
			),
		);
	}

	public function execute( array $input ): array {
		$ids      = self::ids( $input, 'itemIds' );
		$revision = ( $this->items )()->reorder( $this->actor(), self::int( $input, 'id' ), $ids, self::int( $input, 'expectedRevision' ) );
		return array(
			'itemCount' => count( $ids ),
			'revision'  => $revision,
		);
	}
}
