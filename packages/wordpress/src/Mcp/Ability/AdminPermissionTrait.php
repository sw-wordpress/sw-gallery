<?php
/**
 * Permission check for MCP administration abilities.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Mcp\Ability;

use SWGallery\Core\Exception\AccessDeniedException;
use SWGallery\WordPress\Mcp\TierPolicy;

trait AdminPermissionTrait {

	public function tier(): string {
		return TierPolicy::ADMIN;
	}

	/**
	 * @param array<string, mixed> $input Validated input.
	 * @throws AccessDeniedException When the user cannot manage site options.
	 */
	public function permission( array $input ): true {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new AccessDeniedException();
		}
		return true;
	}
}
