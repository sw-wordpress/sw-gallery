<?php
/**
 * Shared helpers of the read abilities.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the framework independent core's value objects expose camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Mcp\Ability;

use SWGallery\Core\Domain\Access\Actor;
use SWGallery\WordPress\Mcp\AbilityFactory;
use SWGallery\WordPress\Mcp\Paging;
use SWGallery\WordPress\Mcp\TierPolicy;

trait ReadAbilityTrait {

	use ReadAnnotationsTrait;

	public function tier(): string {
		return TierPolicy::READ;
	}

	private function actor(): Actor {
		return new Actor( max( 0, get_current_user_id() ) );
	}

	/** @param array<string, mixed> $input Validated input. */
	private static function int( array $input, string $key, int $fallback = 0 ): int {
		return is_numeric( $input[ $key ] ?? null ) ? (int) $input[ $key ] : $fallback;
	}

	/** @param array<string, mixed> $input Validated input. */
	private static function text( array $input, string $key ): string {
		return is_string( $input[ $key ] ?? null ) ? $input[ $key ] : '';
	}

	/**
	 * @param array<string, mixed>       $input    Validated input.
	 * @param string                     $key      Reply list name.
	 * @param list<array<string, mixed>> $records  Records of the requested page.
	 * @param int                        $total    Total records.
	 * @param int                        $fallback Default page size.
	 * @param array<string, mixed>       $extra    Fixed reply fields.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function paged( array $input, string $key, array $records, int $total, int $fallback, array $extra = array() ): array|\WP_Error {
		return Paging::build(
			$key,
			$records,
			$total,
			self::int( $input, 'page', 1 ),
			self::int( $input, 'per_page', $fallback ),
			self::int( $input, 'offset' ),
			AbilityFactory::max_output(),
			$extra
		);
	}
}
