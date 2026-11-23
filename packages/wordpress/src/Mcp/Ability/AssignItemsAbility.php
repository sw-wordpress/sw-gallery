<?php
/**
 * MCP ability `sw-gallery/assign-items`.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the framework independent core's value objects expose camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Mcp\Ability;

use SWGallery\WordPress\Mcp\AbilityDefinition;
use SWGallery\WordPress\Mcp\GallerySchema;
use SWGallery\Core\Application\Service\GalleryItemService;

final class AssignItemsAbility implements AbilityDefinition {

	use ItemWriteAbilityTrait;

	public function name(): string {
		return 'assign-items';
	}

	public function label(): string {
		return __( 'Add images to gallery', 'sw-gallery' );
	}

	public function description(): string {
		return 'Adds media library images (attachment ids from list-media) to the end of a gallery the user may edit. Attachments are never copied or modified. Ids that are already in the gallery or are not usable images are reported, not errors. Returns the new revision.';
	}

	public function inputSchema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'id', 'attachmentIds', 'expectedRevision' ),
			'properties'           => array(
				'id'               => GallerySchema::id(),
				'attachmentIds'    => array(
					'description' => 'Attachment ids to add.',
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => GalleryItemService::MAX_BATCH,
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => GallerySchema::ID_MAX,
					),
				),
				'expectedRevision' => GallerySchema::expectedRevision(),
			),
		);
	}

	public function outputSchema(): array {
		$ids = array(
			'type'  => 'array',
			'items' => array( 'type' => 'integer' ),
		);
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'added'           => $ids,
				'alreadyAssigned' => $ids,
				'missing'         => $ids,
				'revision'        => array( 'type' => 'integer' ),
			),
		);
	}

	public function execute( array $input ): array {
		$result = ( $this->items )()->assign( $this->actor(), self::int( $input, 'id' ), self::ids( $input, 'attachmentIds' ), self::int( $input, 'expectedRevision' ) );
		return array(
			'added'           => $result->added,
			'alreadyAssigned' => $result->alreadyAssigned,
			'missing'         => $result->missing,
			'revision'        => $result->revision->value,
		);
	}
}
