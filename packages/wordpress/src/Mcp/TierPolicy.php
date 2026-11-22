<?php
/**
 * Maximum blast radius of the MCP abilities (docs/mcp-harness.md §4).
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Mcp;

/**
 * Effective tier = the lower of the site option and the optional `SW_GALLERY_MCP_MAX_TIER` ceiling. Default `read`.
 * Invalid stored values fail closed (`off`). Tiers are cumulative.
 */
final class TierPolicy {

	public const OFF         = 'off';
	public const READ        = 'read';
	public const WRITE       = 'write';
	public const DESTRUCTIVE = 'destructive';
	public const ADMIN       = 'admin';

	public const OPTION   = 'swg_mcp_max_tier';
	public const CONSTANT = 'SW_GALLERY_MCP_MAX_TIER';

	private const RANK = array(
		self::OFF         => 0,
		self::READ        => 1,
		self::WRITE       => 2,
		self::DESTRUCTIVE => 3,
		self::ADMIN       => 4,
	);

	/** @return list<string> Every tier, lowest first. */
	public static function all(): array {
		return array_keys( self::RANK );
	}

	/** The tier in force right now (never raises above the constant). */
	public static function effective(): string {
		$option  = self::normalize( get_option( self::OPTION, self::READ ) );
		$ceiling = defined( self::CONSTANT ) ? self::normalize( constant( self::CONSTANT ) ) : self::ADMIN;
		return self::lower( $option, $ceiling );
	}

	/** Whether an ability of `$tier` may be registered and executed under the effective tier. */
	public static function allows( string $tier ): bool {
		if ( ! isset( self::RANK[ $tier ] ) || self::OFF === $tier ) {
			return false;
		}
		return self::RANK[ $tier ] <= self::RANK[ self::effective() ];
	}

	/** Lower of two valid tiers. */
	public static function lower( string $a, string $b ): string {
		return self::RANK[ $a ] <= self::RANK[ $b ] ? $a : $b;
	}

	/** A known tier name, otherwise `off` (fail closed). */
	public static function normalize( mixed $value ): string {
		return is_string( $value ) && isset( self::RANK[ $value ] ) ? $value : self::OFF;
	}
}
