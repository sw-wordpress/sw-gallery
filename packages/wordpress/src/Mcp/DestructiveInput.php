<?php
/**
 * Confirmation contract for destructive MCP abilities.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Mcp;

use SWGallery\WordPress\Rest\ErrorMapper;

final class DestructiveInput {

	/** @return array<string, mixed> */
	public static function schema(): array {
		return array(
			'type'        => 'boolean',
			'enum'        => array( true ),
			'description' => 'Must be the JSON boolean true to confirm permanent removal.',
		);
	}

	public static function error(): \WP_Error {
		return ErrorMapper::unprocessable( 'swg_confirmation_required', __( 'Confirm permanent removal explicitly.', 'sw-gallery' ) );
	}

	/**
	 * Preserves the raw boolean requirement before newer WordPress versions sanitize schema types.
	 *
	 * @param mixed  $validity     Core validation result.
	 * @param mixed  $input        Input before schema sanitization.
	 * @param string $ability_name Registered ability name.
	 */
	public static function validate( mixed $validity, mixed $input, string $ability_name ): mixed {
		if ( true !== $validity || ! str_starts_with( $ability_name, AbilityFactory::NAMESPACE_SLUG . '/' ) ) {
			return $validity;
		}
		$annotations = wp_get_ability( $ability_name )?->get_meta_item( 'annotations' );
		if ( ! is_array( $annotations ) || true !== ( $annotations['destructive'] ?? null ) ) {
			return $validity;
		}
		return is_array( $input ) && true === ( $input['confirm'] ?? null ) ? true : self::error();
	}
}
