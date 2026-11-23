<?php
/**
 * MCP ability `sw-gallery/create-gallery`.
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
use SWGallery\WordPress\Rest\AbstractController;
use SWGallery\WordPress\Rest\ErrorMapper;

final class CreateGalleryAbility implements AbilityDefinition {

	use WriteAbilityTrait;

	/**
	 * @param \Closure $editor Lazy provider of the {@see GalleryEditor}.
	 * @phpstan-param \Closure():GalleryEditor $editor
	 */
	public function __construct( private readonly \Closure $editor ) {
	}

	public function name(): string {
		return 'create-gallery';
	}

	public function label(): string {
		return __( 'Create gallery', 'sw-gallery' );
	}

	public function description(): string {
		return 'Creates a gallery owned by the user (a draft unless status says otherwise; publish and private need the publish right). Omitting layout uses the site default layout. Returns the new gallery with revision 1. Do not repeat this call after a timeout: check list-galleries first, the gallery may already exist.';
	}

	public function inputSchema(): array {
		$fields = GallerySchema::writableFields();
		unset( $fields['coverAttachmentId'] );
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'title' ),
			'properties'           => $fields,
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
		( $this->editor )()->creatable( $this->actor() );
		return true;
	}

	public function execute( array $input ): array|\WP_Error {
		if ( isset( $input['settings'] ) && is_array( $input['settings'] ) && ! AbstractController::settings_within_limits( $input['settings'] ) ) {
			return ErrorMapper::bad_request(
				'rest_invalid_param',
				/* translators: %s: argument name. */
				sprintf( __( '%s is too large or nested too deeply.', 'sw-gallery' ), 'settings' )
			);
		}
		$result = ( $this->editor )()->create( $this->actor(), GallerySchema::updateFields( $input ) );
		return $result instanceof \WP_Error ? $result : GallerySchema::project( $result );
	}
}
