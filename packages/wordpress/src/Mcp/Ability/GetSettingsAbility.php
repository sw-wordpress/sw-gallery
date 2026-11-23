<?php
/**
 * MCP ability `sw-gallery/get-settings`.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Mcp\Ability;

use SWGallery\WordPress\Mcp\AbilityDefinition;

final class GetSettingsAbility implements AbilityDefinition {

	use ReadAnnotationsTrait;
	use SettingsAbilityTrait;

	public function name(): string {
		return 'get-settings';
	}

	public function label(): string {
		return __( 'Get gallery settings', 'sw-gallery' );
	}

	public function description(): string {
		return 'Returns the site-wide display settings for SW Gallery.';
	}

	public function inputSchema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(),
		);
	}

	public function execute( array $input ): array {
		return self::display_values( ( $this->settings )()->get() );
	}
}
