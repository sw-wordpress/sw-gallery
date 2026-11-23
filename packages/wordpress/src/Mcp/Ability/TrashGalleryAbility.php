<?php
/**
 * MCP ability `sw-gallery/trash-gallery`.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the framework independent core's value objects expose camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Mcp\Ability;

use SWGallery\WordPress\Mcp\AbilityDefinition;
use SWGallery\WordPress\Mcp\GallerySchema;

final class TrashGalleryAbility implements AbilityDefinition {

	use GalleryDeletionPermissionTrait;

	public function name(): string {
		return 'trash-gallery';
	}

	public function label(): string {
		return __( 'Trash gallery', 'sw-gallery' );
	}

	public function description(): string {
		return 'Moves a gallery the user may delete to the trash. Relations are kept, restore-gallery undoes it. Returns id, status and the new revision.';
	}

	public function inputSchema(): array {
		return GallerySchema::revisionInput();
	}

	public function outputSchema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'id'       => array( 'type' => 'integer' ),
				'trashed'  => array( 'type' => 'boolean' ),
				'status'   => array( 'type' => 'string' ),
				'revision' => array( 'type' => 'integer' ),
			),
		);
	}

	public function execute( array $input ): array {
		return ( $this->editor )()->trash( $this->actor(), self::int( $input, 'id' ), self::int( $input, 'expectedRevision' ) );
	}
}
