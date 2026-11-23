<?php
/**
 * Shared helpers of the write abilities.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Mcp\Ability;

use SWGallery\Core\Domain\Access\Actor;
use SWGallery\WordPress\Mcp\TierPolicy;

trait WriteAbilityTrait {

	public function tier(): string {
		return TierPolicy::WRITE;
	}

	/** @return array{readonly: bool, destructive: bool, idempotent: bool} */
	public function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	private function actor(): Actor {
		return new Actor( max( 0, get_current_user_id() ) );
	}

	/** @param array<string, mixed> $input Validated input. */
	private static function int( array $input, string $key ): int {
		return is_numeric( $input[ $key ] ?? null ) ? (int) $input[ $key ] : 0;
	}

	/**
	 * @param array<string, mixed> $input Validated input.
	 * @return list<int>
	 */
	private static function ids( array $input, string $key ): array {
		$raw = $input[ $key ] ?? array();
		return is_array( $raw ) ? array_values( array_map( 'intval', $raw ) ) : array();
	}
}
