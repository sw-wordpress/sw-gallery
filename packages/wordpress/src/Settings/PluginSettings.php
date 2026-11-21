<?php
/**
 * Repository and validation of the plugin settings (`swg_settings`).
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Settings;

use SWGallery\Core\Contract\LayoutRegistryInterface;
use SWGallery\Core\Domain\Gallery\HoverAnimation;
use SWGallery\Core\Domain\Settings\SettingsSchemaValidator;
use SWGallery\Core\Exception\UnknownLayoutException;
use SWGallery\Core\Exception\ValidationFailedException;
use SWGallery\WordPress\Lifecycle;

/**
 * One versioned JSON object in one non-autoloaded option. Keys this version does not know (written by a newer
 * plugin version) are preserved on every save. `deleteDataOnUninstall` is not part of the object: it maps to the
 * per-site option the uninstall routine already reads ({@see Lifecycle::OPTION_DELETE_ON_UNINSTALL}), and turning it
 * on needs an explicit confirmation. Writes are last-write-wins (settings change rarely, no revision).
 */
final class PluginSettings {

	public const OPTION         = 'swg_settings';
	public const SCHEMA_VERSION = 1;

	public const MIN_ITEMS_PER_PAGE = 1;
	public const MAX_ITEMS_PER_PAGE = 200;

	public const DEFAULTS = array(
		'defaultLayout'         => 'grid',
		'itemsPerPage'          => 24,
		'lightbox'              => true,
		'hoverTitle'            => false,
		'hoverCaption'          => false,
		'hoverAnimation'        => 'fade',
		'deleteDataOnUninstall' => false,
	);

	/** Keys stored inside the JSON object. */
	private const STORED_KEYS = array( 'defaultLayout', 'itemsPerPage', 'lightbox', 'hoverTitle', 'hoverCaption', 'hoverAnimation' );

	private SettingsSchemaValidator $validator;

	public function __construct( private readonly LayoutRegistryInterface $layouts, ?SettingsSchemaValidator $validator = null ) {
		$this->validator = $validator ?? new SettingsSchemaValidator();
	}

	/**
	 * Effective settings: stored values, defaults for everything missing or invalid, and a registered layout.
	 *
	 * @return array{defaultLayout: string, itemsPerPage: int, lightbox: bool, hoverTitle: bool, hoverCaption: bool, hoverAnimation: string, deleteDataOnUninstall: bool}
	 */
	public function get(): array {
		$stored = $this->stored();
		$layout = $stored['defaultLayout'] ?? null;
		$items  = $stored['itemsPerPage'] ?? null;
		$box    = $stored['lightbox'] ?? null;
		$title  = $stored['hoverTitle'] ?? null;
		$text   = $stored['hoverCaption'] ?? null;
		$motion = $stored['hoverAnimation'] ?? null;

		return array(
			'defaultLayout'         => is_string( $layout ) && $this->layouts->has( $layout ) ? $layout : self::DEFAULTS['defaultLayout'],
			'itemsPerPage'          => is_int( $items ) && $items >= self::MIN_ITEMS_PER_PAGE && $items <= self::MAX_ITEMS_PER_PAGE ? $items : self::DEFAULTS['itemsPerPage'],
			'lightbox'              => is_bool( $box ) ? $box : self::DEFAULTS['lightbox'],
			'hoverTitle'            => is_bool( $title ) ? $title : self::DEFAULTS['hoverTitle'],
			'hoverCaption'          => is_bool( $text ) ? $text : self::DEFAULTS['hoverCaption'],
			'hoverAnimation'        => is_string( $motion ) && null !== HoverAnimation::tryFrom( $motion ) ? $motion : self::DEFAULTS['hoverAnimation'],
			'deleteDataOnUninstall' => '1' === (string) get_option( Lifecycle::OPTION_DELETE_ON_UNINSTALL, '0' ),
		);
	}

	/**
	 * Partial update: only the known keys present in `$changes` are applied, everything else is kept.
	 *
	 * @param array<string, mixed> $changes        Requested values.
	 * @param bool                 $confirm_delete Explicit confirmation to turn `deleteDataOnUninstall` on.
	 * @return array{defaultLayout: string, itemsPerPage: int, lightbox: bool, hoverTitle: bool, hoverCaption: bool, hoverAnimation: string, deleteDataOnUninstall: bool}
	 * @throws ValidationFailedException     When a value has the wrong type or is out of bounds.
	 * @throws UnknownLayoutException        When `defaultLayout` is not a registered layout.
	 * @throws ConfirmationRequiredException When the uninstall deletion is switched on without confirmation.
	 */
	public function update( array $changes, bool $confirm_delete = false ): array {
		$changes = array_intersect_key( $changes, self::DEFAULTS );
		if ( array() === $changes ) {
			return $this->get();
		}

		$result = $this->validator->validate( $this->schema(), $changes );
		if ( ! $result->isValid() ) {
			throw new ValidationFailedException( $result->errors );
		}
		$values = $result->values;

		if ( isset( $values['defaultLayout'] ) && ( ! is_string( $values['defaultLayout'] ) || ! $this->layouts->has( $values['defaultLayout'] ) ) ) {
			throw new UnknownLayoutException();
		}

		$current = $this->get();
		if ( true === ( $values['deleteDataOnUninstall'] ?? false ) && ! $current['deleteDataOnUninstall'] && ! $confirm_delete ) {
			throw new ConfirmationRequiredException( 'Deleting data on uninstall needs an explicit confirmation.' );
		}

		$blob = $this->stored();
		foreach ( self::STORED_KEYS as $key ) {
			if ( array_key_exists( $key, $values ) ) {
				$blob[ $key ] = $values[ $key ];
			}
		}
		$blob['schemaVersion'] = self::SCHEMA_VERSION;
		update_option( self::OPTION, (string) wp_json_encode( $blob ), false );

		if ( array_key_exists( 'deleteDataOnUninstall', $values ) ) {
			update_option( Lifecycle::OPTION_DELETE_ON_UNINSTALL, true === $values['deleteDataOnUninstall'] ? '1' : '0', false );
		}

		return $this->get();
	}

	/**
	 * The stored object including unknown keys; an unreadable value counts as empty.
	 *
	 * @return array<string, mixed>
	 */
	private function stored(): array {
		$raw = get_option( self::OPTION, '' );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Schema of the writable keys (no defaults: a partial update must not reset the other keys).
	 *
	 * @return array<string, mixed>
	 */
	private function schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'defaultLayout'         => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 64,
				),
				'itemsPerPage'          => array(
					'type'    => 'integer',
					'minimum' => self::MIN_ITEMS_PER_PAGE,
					'maximum' => self::MAX_ITEMS_PER_PAGE,
				),
				'lightbox'              => array( 'type' => 'boolean' ),
				'hoverTitle'            => array( 'type' => 'boolean' ),
				'hoverCaption'          => array( 'type' => 'boolean' ),
				'hoverAnimation'        => array(
					'type' => 'string',
					'enum' => array_map( static fn( HoverAnimation $animation ): string => $animation->value, HoverAnimation::cases() ),
				),
				'deleteDataOnUninstall' => array( 'type' => 'boolean' ),
			),
		);
	}
}
