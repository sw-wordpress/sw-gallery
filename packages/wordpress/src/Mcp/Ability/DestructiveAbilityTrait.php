<?php
/**
 * Confirmation contract for destructive MCP abilities.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Mcp\Ability;

use SWGallery\WordPress\Rest\ErrorMapper;

trait DestructiveAbilityTrait {

	/** @return array<string, mixed> */
	private static function confirmation_schema(): array {
		return array(
			'type'        => 'boolean',
			'enum'        => array( true ),
			'description' => 'Must be the JSON boolean true to confirm permanent removal.',
		);
	}

	private static function confirmation_error(): \WP_Error {
		return ErrorMapper::unprocessable( 'swg_confirmation_required', __( 'Confirm permanent removal explicitly.', 'sw-gallery' ) );
	}
}
