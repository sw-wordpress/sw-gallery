<?php
/**
 * MCP ability `sw-gallery/update-settings`.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Mcp\Ability;

use SWGallery\WordPress\Mcp\AbilityDefinition;
use SWGallery\WordPress\Mcp\GallerySchema;
use SWGallery\WordPress\Rest\ErrorMapper;
use SWGallery\WordPress\Rest\SettingsController;
use SWGallery\WordPress\Settings\PluginSettings;

final class UpdateSettingsAbility implements AbilityDefinition {

	use SettingsAbilityTrait;

	public function name(): string {
		return 'update-settings';
	}

	public function label(): string {
		return __( 'Update gallery settings', 'sw-gallery' );
	}

	public function description(): string {
		return 'Changes the supplied site-wide display settings for SW Gallery. Use list-layouts to find the registered layout ids.';
	}

	public function inputSchema(): array {
		$properties                  = PluginSettings::display_properties();
		$properties['defaultLayout'] = GallerySchema::writableFields()['layout'];
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'minProperties'        => 1,
			'properties'           => $properties,
		);
	}

	public function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	public function execute( array $input ): array|\WP_Error {
		$changes = self::display_values( $input );
		if ( array() === $changes ) {
			return ErrorMapper::bad_request( SettingsController::CODE_NO_CHANGES, __( 'The request does not change anything.', 'sw-gallery' ) );
		}
		return self::display_values( ( $this->settings )()->update( $changes ) );
	}
}
