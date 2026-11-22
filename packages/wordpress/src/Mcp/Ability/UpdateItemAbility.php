<?php
/**
 * MCP ability `sw-gallery/update-item`.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the framework independent core's value objects expose camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Mcp\Ability;

use SWGallery\Core\Exception\AccessDeniedException;
use SWGallery\WordPress\Gallery\GalleryEditor;
use SWGallery\WordPress\Gallery\ItemEditor;
use SWGallery\WordPress\Mcp\AbilityDefinition;
use SWGallery\WordPress\Mcp\GallerySchema;
use SWGallery\WordPress\Mcp\ItemSchema;
use SWGallery\WordPress\Mcp\TierPolicy;
use SWGallery\WordPress\Rest\AbstractController;
use SWGallery\WordPress\Rest\ErrorMapper;

final class UpdateItemAbility implements AbilityDefinition {

	use WriteAbilityTrait;

	/**
	 * @param \Closure $items  Lazy provider of the {@see ItemEditor}.
	 * @param \Closure $editor Lazy provider of the {@see GalleryEditor}.
	 * @phpstan-param \Closure():ItemEditor $items
	 * @phpstan-param \Closure():GalleryEditor $editor
	 */
	public function __construct( private readonly \Closure $items, private readonly \Closure $editor ) {
	}

	public function name(): string {
		return 'update-item';
	}

	public function tier(): string {
		return TierPolicy::WRITE;
	}

	public function label(): string {
		return __( 'Update gallery item', 'sw-gallery' );
	}

	public function description(): string {
		return 'Changes one image of a gallery the user may edit: text overrides (title, caption, alt) and/or the per-image settings map (replaces the stored map, validated against the layout). Send at least one of both. If both are sent they are saved in two steps; after an error re-read the item (list-items) before retrying. Returns the item and the new revision.';
	}

	public function inputSchema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'id', 'itemId', 'expectedRevision' ),
			'properties'           => array(
				'id'               => GallerySchema::id(),
				'itemId'           => ItemSchema::itemId(),
				'expectedRevision' => GallerySchema::expectedRevision(),
				'overrides'        => ItemSchema::overridesInput(),
				'settings'         => array(
					'description'          => 'Per-image settings, replaces the stored map. At most 200 keys, 6 levels, 32 KB.',
					'type'                 => 'object',
					'additionalProperties' => true,
					'maxProperties'        => 200,
				),
			),
		);
	}

	public function outputSchema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'item'     => ItemSchema::output(),
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
		if ( ! current_user_can( 'edit_posts' ) ) {
			throw new AccessDeniedException();
		}
		( $this->editor )()->editable( $this->actor(), self::int( $input, 'id' ) );
		return true;
	}

	public function execute( array $input ): array|\WP_Error {
		$settings  = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : null;
		$overrides = isset( $input['overrides'] ) && is_array( $input['overrides'] ) ? $input['overrides'] : array();
		if ( null !== $settings && ! AbstractController::settings_within_limits( $settings ) ) {
			return ErrorMapper::bad_request(
				'rest_invalid_param',
				/* translators: %s: argument name. */
				sprintf( __( '%s is too large or nested too deeply.', 'sw-gallery' ), 'settings' )
			);
		}
		$result = ( $this->items )()->update( $this->actor(), self::int( $input, 'id' ), self::int( $input, 'itemId' ), $overrides, $settings, self::int( $input, 'expectedRevision' ) );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		return array(
			'item'     => ItemSchema::project( $result['item'] ),
			'revision' => $result['revision'],
		);
	}
}
