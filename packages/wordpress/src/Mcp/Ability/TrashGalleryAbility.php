<?php
/**
 * MCP ability `sw-gallery/trash-gallery`.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the framework independent core's value objects expose camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Mcp\Ability;

use SWGallery\Core\Exception\AccessDeniedException;
use SWGallery\WordPress\Gallery\GalleryEditor;
use SWGallery\WordPress\Mcp\AbilityDefinition;
use SWGallery\WordPress\Mcp\GallerySchema;
use SWGallery\WordPress\Mcp\TierPolicy;

final class TrashGalleryAbility implements AbilityDefinition {

	use WriteAbilityTrait;

	/**
	 * @param \Closure $editor Lazy provider of the {@see GalleryEditor}.
	 * @phpstan-param \Closure():GalleryEditor $editor
	 */
	public function __construct( private readonly \Closure $editor ) {
	}

	public function name(): string {
		return 'trash-gallery';
	}

	public function tier(): string {
		return TierPolicy::WRITE;
	}

	public function label(): string {
		return __( 'Trash gallery', 'sw-gallery' );
	}

	public function description(): string {
		return 'Moves a gallery the user may delete to the trash. Relations are kept, restore-gallery undoes it. Returns id, status and the new revision.';
	}

	public function inputSchema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'id', 'expectedRevision' ),
			'properties'           => array(
				'id'               => GallerySchema::id(),
				'expectedRevision' => GallerySchema::expectedRevision(),
			),
		);
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

	public function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	public function permission( array $input ): true|\WP_Error {
		if ( ! current_user_can( 'delete_posts' ) ) {
			throw new AccessDeniedException();
		}
		( $this->editor )()->deletable( $this->actor(), self::int( $input, 'id' ) );
		return true;
	}

	public function execute( array $input ): array {
		return ( $this->editor )()->trash( $this->actor(), self::int( $input, 'id' ), self::int( $input, 'expectedRevision' ) );
	}
}
