<?php
/**
 * MCP ability `sw-gallery/delete-gallery`.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the framework independent core's value objects expose camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Mcp\Ability;

use SWGallery\WordPress\Mcp\AbilityDefinition;
use SWGallery\WordPress\Mcp\DestructiveInput;
use SWGallery\WordPress\Mcp\GallerySchema;
use SWGallery\WordPress\Mcp\TierPolicy;

final class DeleteGalleryAbility implements AbilityDefinition {

	use GalleryDeletionPermissionTrait;

	public function name(): string {
		return 'delete-gallery';
	}

	public function tier(): string {
		return TierPolicy::DESTRUCTIVE;
	}

	public function label(): string {
		return __( 'Permanently delete gallery', 'sw-gallery' );
	}

	public function description(): string {
		return 'Permanently deletes a gallery and its image relations. Media Library attachments are kept. Prefer trash-gallery for reversible removal.';
	}

	public function inputSchema(): array {
		return GallerySchema::revisionInput(
			array( 'confirm' => DestructiveInput::schema() ),
			array( 'confirm' )
		);
	}

	public function outputSchema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'id'      => array( 'type' => 'integer' ),
				'deleted' => array( 'type' => 'boolean' ),
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
		$id = self::int( $input, 'id' );
		( $this->editor )()->delete( $this->actor(), $id, self::int( $input, 'expectedRevision' ) );
		return array(
			'id'      => $id,
			'deleted' => true,
		);
	}
}
