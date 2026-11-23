<?php
/**
 * Settings projection shared by the two MCP settings abilities.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Mcp\Ability;

use SWGallery\WordPress\Settings\PluginSettings;

trait SettingsAbilityTrait {

	use AdminPermissionTrait;

	/**
	 * @param \Closure $settings Lazy settings provider.
	 * @phpstan-param \Closure():PluginSettings $settings
	 */
	public function __construct( private readonly \Closure $settings ) {
	}

	/** @return array<string, mixed> */
	public function outputSchema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => PluginSettings::display_properties(),
		);
	}

	/**
	 * @param array<string, mixed> $values Settings returned by the repository.
	 * @return array<string, mixed>
	 */
	private static function display_values( array $values ): array {
		return array_intersect_key( $values, PluginSettings::display_properties() );
	}
}
