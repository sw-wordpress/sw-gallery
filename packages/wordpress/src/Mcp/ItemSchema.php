<?php
/**
 * Schema and projection of the item resource shared by the item abilities.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Mcp;

use SWGallery\WordPress\Rest\ItemController;

final class ItemSchema {

	/** Fields of an item that are returned (no URLs). */
	public const FIELDS = array( 'id', 'attachmentId', 'position', 'overrides', 'effective', 'global', 'settings', 'missing' );

	/** @return array<string, mixed> */
	public static function output(): array {
		$texts = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'title'   => array( 'type' => array( 'string', 'null' ) ),
				'caption' => array( 'type' => array( 'string', 'null' ) ),
				'alt'     => array( 'type' => array( 'string', 'null' ) ),
			),
		);
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'id'           => array( 'type' => 'integer' ),
				'attachmentId' => array( 'type' => 'integer' ),
				'position'     => array( 'type' => 'integer' ),
				'overrides'    => $texts,
				'effective'    => $texts,
				'global'       => array( 'type' => array( 'object', 'null' ) ),
				'settings'     => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'missing'      => array( 'type' => 'boolean' ),
			),
		);
	}

	/**
	 * @param array<string, mixed> $presented Presenter output.
	 * @return array<string, mixed>
	 */
	public static function project( array $presented ): array {
		return array_intersect_key( $presented, array_flip( self::FIELDS ) );
	}

	/** @return array<string, mixed> */
	public static function itemId(): array {
		return array(
			'description' => 'Item id (not the attachment id), see list-items.',
			'type'        => 'integer',
			'minimum'     => 1,
			'maximum'     => GallerySchema::ID_MAX,
		);
	}

	/** @return array<string, mixed> Input schema of the partial text overrides. */
	public static function overridesInput(): array {
		return array(
			'description'          => 'Partial text overrides: absent fields keep their value, null inherits the attachment text, an empty string is an intentional blank.',
			'type'                 => 'object',
			'additionalProperties' => false,
			'minProperties'        => 1,
			'properties'           => array(
				'title'   => array(
					'type'      => array( 'string', 'null' ),
					'maxLength' => ItemController::TITLE_MAX,
				),
				'caption' => array(
					'type'      => array( 'string', 'null' ),
					'maxLength' => ItemController::CAPTION_MAX,
				),
				'alt'     => array(
					'type'      => array( 'string', 'null' ),
					'maxLength' => ItemController::ALT_MAX,
				),
			),
		);
	}
}
