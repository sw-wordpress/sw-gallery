<?php
/**
 * MCP ability `sw-gallery/delete-gallery`.
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

final class DeleteGalleryAbility implements AbilityDefinition {

	use WriteAbilityTrait;
	use DestructiveAbilityTrait;

	/**
	 * @param \Closure $editor Lazy provider of the {@see GalleryEditor}.
	 * @phpstan-param \Closure():GalleryEditor $editor
	 */
	public function __construct( private readonly \Closure $editor ) {
	}

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
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'id', 'expectedRevision', 'confirm' ),
			'properties'           => array(
				'id'               => GallerySchema::id(),
				'expectedRevision' => GallerySchema::expectedRevision(),
				'confirm'          => self::confirmation_schema(),
			),
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

	public function permission( array $input ): true|\WP_Error {
		if ( ! current_user_can( 'delete_posts' ) ) {
			throw new AccessDeniedException();
		}
		( $this->editor )()->deletable( $this->actor(), self::int( $input, 'id' ) );
		return true;
	}

	public function execute( array $input ): array|\WP_Error {
		if ( true !== ( $input['confirm'] ?? null ) ) {
			return self::confirmation_error();
		}
		$id = self::int( $input, 'id' );
		( $this->editor )()->delete( $this->actor(), $id, self::int( $input, 'expectedRevision' ) );
		return array(
			'id'      => $id,
			'deleted' => true,
		);
	}
}
