<?php
/**
 * Schemas and projection of the gallery resource shared by the gallery abilities.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Mcp;

use SWGallery\Core\Domain\Gallery\GallerySettings;
use SWGallery\Core\Domain\Gallery\HoverAnimation;
use SWGallery\Core\Domain\Gallery\SortMode;
use SWGallery\WordPress\Gallery\GalleryEditor;

/**
 * Field definitions come from the domain enums and bounds, so the REST and MCP contracts cannot drift.
 */
final class GallerySchema {

	public const ID_MAX = 2147483647;

	/** The statuses a gallery can be written with. */
	public const WRITABLE_STATUSES = array( 'draft', 'pending', 'publish', 'private' );

	/** @return array<string, mixed> */
	public static function idInput(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'id' ),
			'properties'           => array( 'id' => self::id() ),
		);
	}

	/**
	 * @param array<string, array<string, mixed>> $fields Additional input properties.
	 * @param string[]                            $required Required keys among those properties.
	 * @return array<string, mixed>
	 */
	public static function revisionInput( array $fields = array(), array $required = array() ): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array_merge( array( 'id', 'expectedRevision' ), $required ),
			'properties'           => array(
				'id'               => self::id(),
				'expectedRevision' => self::expectedRevision(),
			) + $fields,
		);
	}

	/** @return array<string, mixed> */
	public static function id(): array {
		return array(
			'description' => 'Gallery id.',
			'type'        => 'integer',
			'minimum'     => 1,
			'maximum'     => self::ID_MAX,
		);
	}

	/** @return array<string, mixed> */
	public static function expectedRevision(): array {
		return array(
			'description' => 'Revision from the last read (get-gallery); a different stored revision fails with swg_revision_conflict.',
			'type'        => 'integer',
			'minimum'     => 0,
			'maximum'     => self::ID_MAX,
		);
	}

	/**
	 * Writable gallery fields (without `ownerId`, which is not exposed through MCP).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function writableFields(): array {
		return array(
			'title'             => array(
				'description' => 'Single-line title.',
				'type'        => 'string',
				'minLength'   => 1,
				'maxLength'   => 200,
			),
			'description'       => array(
				'description' => 'Plain text with line breaks.',
				'type'        => 'string',
				'maxLength'   => 10000,
			),
			'status'            => array(
				'description' => 'publish and private need the publish right.',
				'type'        => 'string',
				'enum'        => self::WRITABLE_STATUSES,
			),
			'coverAttachmentId' => array(
				'description' => 'Cover attachment id the user may use (see list-media), or null for none.',
				'type'        => array( 'integer', 'null' ),
				'minimum'     => 1,
				'maximum'     => self::ID_MAX,
			),
			'layout'            => array(
				'description' => 'Registered layout id (see list-layouts).',
				'type'        => 'string',
				'maxLength'   => 64,
				'pattern'     => '^[a-z][a-z0-9_-]{0,63}$',
			),
			'sortMode'          => array(
				'description' => 'Item order mode.',
				'type'        => 'string',
				'enum'        => self::sortModes(),
			),
			'perPage'           => array(
				'description' => 'Items per page of this gallery; null uses the site-wide setting.',
				'type'        => array( 'integer', 'null' ),
				'minimum'     => GallerySettings::MIN_PER_PAGE,
				'maximum'     => GallerySettings::MAX_PER_PAGE,
			),
			'hover'             => array(
				'description'          => 'Hover texts of the images; null uses the site-wide setting.',
				'type'                 => array( 'object', 'null' ),
				'additionalProperties' => false,
				'properties'           => array(
					'title'     => array( 'type' => 'boolean' ),
					'caption'   => array( 'type' => 'boolean' ),
					'animation' => array(
						'type' => 'string',
						'enum' => self::hoverAnimations(),
					),
				),
			),
			'settings'          => array(
				'description'          => 'Layout settings validated against the layout schema; replaces the stored map. At most 200 keys, 6 levels, 32 KB.',
				'type'                 => 'object',
				'additionalProperties' => true,
				'maxProperties'        => 200,
			),
		);
	}

	/** @return array<string, mixed> */
	public static function output(): array {
		$flag = array( 'type' => 'boolean' );
		$int  = array( 'type' => 'integer' );
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'id'                => $int,
				'title'             => array( 'type' => 'string' ),
				'description'       => array( 'type' => 'string' ),
				'status'            => array(
					'type' => 'string',
					'enum' => array( 'draft', 'pending', 'publish', 'private', 'future', 'trash' ),
				),
				'ownerId'           => $int,
				'coverAttachmentId' => array( 'type' => array( 'integer', 'null' ) ),
				'layout'            => array( 'type' => 'string' ),
				'sortMode'          => array(
					'type' => 'string',
					'enum' => self::sortModes(),
				),
				'perPage'           => array( 'type' => array( 'integer', 'null' ) ),
				'hover'             => array(
					'type'                 => array( 'object', 'null' ),
					'additionalProperties' => false,
					'properties'           => array(
						'title'     => $flag,
						'caption'   => $flag,
						'animation' => array(
							'type' => 'string',
							'enum' => self::hoverAnimations(),
						),
					),
				),
				'settings'          => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'passwordProtected' => $flag,
				'modified'          => array( 'type' => 'string' ),
				'revision'          => $int,
				'itemCount'         => $int,
				'canEdit'           => $flag,
				'canDelete'         => $flag,
				'canPublish'        => $flag,
			),
		);
	}

	/**
	 * Keeps only the fields of {@see self::output()} (drops URLs such as `coverUrl`).
	 *
	 * @param array<string, mixed> $presented Presenter output.
	 * @return array<string, mixed>
	 */
	public static function project( array $presented ): array {
		$properties = self::output()['properties'];
		return array_intersect_key( $presented, is_array( $properties ) ? $properties : array() );
	}

	/**
	 * Fields of a partial update taken from validated input.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function updateFields( array $input ): array {
		return array_intersect_key( $input, array_flip( array_intersect( GalleryEditor::UPDATE_FIELDS, array_keys( self::writableFields() ) ) ) );
	}

	/** @return list<string> */
	private static function sortModes(): array {
		return array_map( static fn( SortMode $mode ): string => $mode->value, SortMode::cases() );
	}

	/** @return list<string> */
	private static function hoverAnimations(): array {
		return array_map( static fn( HoverAnimation $animation ): string => $animation->value, HoverAnimation::cases() );
	}
}
