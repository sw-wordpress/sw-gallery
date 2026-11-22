<?php
/**
 * MCP ability `sw-gallery/duplicate-gallery`.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the framework independent core's value objects expose camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Mcp\Ability;

use SWGallery\WordPress\Gallery\GalleryEditor;
use SWGallery\WordPress\Mcp\AbilityDefinition;
use SWGallery\WordPress\Mcp\GallerySchema;
use SWGallery\WordPress\Mcp\TierPolicy;

final class DuplicateGalleryAbility implements AbilityDefinition {

	use WriteAbilityTrait;

	/**
	 * @param \Closure $editor Lazy provider of the {@see GalleryEditor}.
	 * @phpstan-param \Closure():GalleryEditor $editor
	 */
	public function __construct( private readonly \Closure $editor ) {
	}

	public function name(): string {
		return 'duplicate-gallery';
	}

	public function tier(): string {
		return TierPolicy::WRITE;
	}

	public function label(): string {
		return __( 'Duplicate gallery', 'sw-gallery' );
	}

	public function description(): string {
		return 'Copies a gallery the user may view (membership, order and text overrides) into a new draft owned by the user, with revision 1. A trashed gallery can not be duplicated. Do not repeat this call after a timeout: check list-galleries first.';
	}

	public function inputSchema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'id' ),
			'properties'           => array(
				'id'    => GallerySchema::id(),
				'title' => array(
					'description' => 'Title of the copy (default: "<title> (copy)").',
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 200,
				),
			),
		);
	}

	public function outputSchema(): array {
		return GallerySchema::output();
	}

	public function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		);
	}

	public function permission( array $input ): true|\WP_Error {
		$editor = ( $this->editor )();
		$editor->creatable( $this->actor() );
		$editor->viewable( $this->actor(), self::int( $input, 'id' ) );
		return true;
	}

	public function execute( array $input ): array {
		$title = is_string( $input['title'] ?? null ) ? $input['title'] : null;
		return GallerySchema::project( ( $this->editor )()->duplicate( $this->actor(), self::int( $input, 'id' ), $title ) );
	}
}
