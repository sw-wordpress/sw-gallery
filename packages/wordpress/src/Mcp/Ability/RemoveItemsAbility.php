<?php
/**
 * MCP ability `sw-gallery/remove-items`.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the framework independent core's value objects expose camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Mcp\Ability;

use SWGallery\WordPress\Mcp\AbilityDefinition;
use SWGallery\WordPress\Mcp\DestructiveInput;
use SWGallery\WordPress\Mcp\GallerySchema;
use SWGallery\WordPress\Mcp\ItemSchema;
use SWGallery\WordPress\Mcp\TierPolicy;
use SWGallery\Core\Application\Service\GalleryItemService;

final class RemoveItemsAbility implements AbilityDefinition {

	use ItemWriteAbilityTrait;

	public function name(): string {
		return 'remove-items';
	}

	public function tier(): string {
		return TierPolicy::DESTRUCTIVE;
	}

	public function label(): string {
		return __( 'Remove gallery items', 'sw-gallery' );
	}

	public function description(): string {
		return 'Removes the specified gallery items and their overrides. Use item ids from list-items. Media Library attachments are kept.';
	}

	public function inputSchema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'id', 'itemIds', 'expectedRevision', 'confirm' ),
			'properties'           => array(
				'id'               => GallerySchema::id(),
				'itemIds'          => array(
					'description' => 'Item ids to remove from the gallery.',
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => GalleryItemService::MAX_BATCH,
					'items'       => ItemSchema::itemId(),
				),
				'expectedRevision' => GallerySchema::expectedRevision(),
				'confirm'          => DestructiveInput::schema(),
			),
		);
	}

	public function outputSchema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'removed'  => self::item_id_list(),
				'notFound' => self::item_id_list(),
				'revision' => array( 'type' => 'integer' ),
			),
		);
	}

	public function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => true,
		);
	}

	public function execute( array $input ): array|\WP_Error {
		if ( true !== ( $input['confirm'] ?? null ) ) {
			return DestructiveInput::error();
		}
		$result = ( $this->items )()->remove( $this->actor(), self::int( $input, 'id' ), self::ids( $input, 'itemIds' ), self::int( $input, 'expectedRevision' ) );
		return array(
			'removed'  => $result->removed,
			'notFound' => $result->notFound,
			'revision' => $result->revision->value,
		);
	}

	/** @return array<string, mixed> */
	private static function item_id_list(): array {
		return array(
			'type'  => 'array',
			'items' => ItemSchema::itemId(),
		);
	}
}
