<?php
/**
 * The only switch for the MCP integration (docs/mcp-harness.md §2).
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Mcp;

/**
 * True only when the Abilities API (WordPress 6.9+) and the mcp-adapter plugin are present, the kill switch
 * `SW_GALLERY_MCP_DISABLED` is not set and the tier is not `off`. Adapter classes are never referenced, only probed.
 */
final class McpAvailability {

	public const DISABLED_CONSTANT = 'SW_GALLERY_MCP_DISABLED';
	public const ADAPTER_CLASS     = '\\WP\\MCP\\Plugin';

	public static function isAvailable(): bool {
		if ( ! self::adapterPresent() ) {
			return false;
		}
		if ( defined( self::DISABLED_CONSTANT ) && constant( self::DISABLED_CONSTANT ) ) {
			return false;
		}
		return TierPolicy::OFF !== TierPolicy::effective();
	}

	/** Whether the adapter itself is present (the tier control is shown then, even at tier `off`). */
	public static function adapterPresent(): bool {
		return function_exists( 'wp_register_ability' ) && class_exists( self::ADAPTER_CLASS, false );
	}
}
