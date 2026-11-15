<?php
/**
 * Validated values of plan based layouts: item settings to planner input, numbers to CSS text.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- core value objects use camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Rendering\View;

use SWGallery\Core\Domain\Layout\Plan\PlanItem;
use SWGallery\Core\Domain\Layout\RenderItem;

/**
 * Only finite, bounded numbers leave this class as text, so nothing from the settings can reach a style attribute as
 * markup. Shared by the layouts that turn a core plan into custom properties.
 */
final class PlanValues {

	/** The planner input of a render item: aspect ratio of the thumbnail, `emphasis` and `focal` (all clamped). */
	public static function plan_item( RenderItem $item ): PlanItem {
		$emphasis = $item->settings['emphasis'] ?? 1;
		$focal    = $item->settings['focal'] ?? null;
		return PlanItem::fromSize(
			$item->thumbnail->width,
			$item->thumbnail->height,
			is_int( $emphasis ) ? $emphasis : 1,
			is_array( $focal ) ? self::percent( $focal['x'] ?? null ) : 50.0,
			is_array( $focal ) ? self::percent( $focal['y'] ?? null ) : 50.0
		);
	}

	/** A coordinate in percent: a finite number clamped to 0..100, else the centre. */
	public static function percent( mixed $value ): float {
		return ( is_int( $value ) || is_float( $value ) ) && is_finite( (float) $value ) ? max( 0.0, min( 100.0, (float) $value ) ) : 50.0;
	}

	/** Locale independent decimal with at most three decimals, no trailing zeros. */
	public static function number( float $value ): string {
		$text = rtrim( rtrim( sprintf( '%.3F', $value ), '0' ), '.' );
		return '' === $text || '-0' === $text ? '0' : $text;
	}
}
