<?php
/**
 * MCP ability `sw-gallery/restore-gallery`.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the framework independent core's value objects expose camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Mcp\Ability;

use SWGallery\WordPress\Mcp\AbilityDefinition;
use SWGallery\WordPress\Mcp\GallerySchema;

final class RestoreGalleryAbility implements AbilityDefinition {

	use GalleryDeletionPermissionTrait;

	public function name(): string {
		return 'restore-gallery';
	}

	public function label(): string {
		return __( 'Restore gallery', 'sw-gallery' );
	}

	public function description(): string {
		return 'Restores a trashed gallery as a draft. Returns the gallery with its new revision. A gallery that is not in the trash fails with a state error.';
	}

	public function inputSchema(): array {
		return GallerySchema::revisionInput();
	}

	public function outputSchema(): array {
		return GallerySchema::output();
	}

	public function execute( array $input ): array {
		return GallerySchema::project( ( $this->editor )()->restore( $this->actor(), self::int( $input, 'id' ), self::int( $input, 'expectedRevision' ) ) );
	}
}
