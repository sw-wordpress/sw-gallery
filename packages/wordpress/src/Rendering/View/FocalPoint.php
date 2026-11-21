<?php
/**
 * Focal point of an image: the part that stays visible when a layout crops the thumbnail.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- core value objects use camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Rendering\View;

use SWGallery\Core\Domain\Layout\RenderItem;

/**
 * The per-item setting `focal` (`x`, `y` in percent from the left and the top) is layout independent: it is stored once
 * per image, every cropping layout reads it (plan layouts emit it from their plan, the other layouts through {@see style()}), and the stylesheet turns the custom properties `--swg-fx` / `--swg-fy`
 * into `object-position`. Only finite numbers clamped to 0..100 ever reach the style attribute.
 */
final class FocalPoint {

	/**
	 * The schema property `focal` of a layout's `x-item-settings` (the admin app shows a click-to-set picker for it).
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		return array(
			'title'       => __( 'Focal point', 'sw-gallery' ),
			'description' => __( 'Which part of the picture stays visible when the tile crops it, in percent from the left and the top (50 / 50 is the centre).', 'sw-gallery' ),
			'type'        => 'object',
			'properties'  => array(
				'x' => array(
					'title'   => __( 'Focal point, horizontal (%)', 'sw-gallery' ),
					'type'    => 'number',
					'minimum' => 0,
					'maximum' => 100,
					'default' => 50,
				),
				'y' => array(
					'title'   => __( 'Focal point, vertical (%)', 'sw-gallery' ),
					'type'    => 'number',
					'minimum' => 0,
					'maximum' => 100,
					'default' => 50,
				),
			),
			'default'     => array(
				'x' => 50,
				'y' => 50,
			),
		);
	}

	/**
	 * The `x-item-settings` schema of layouts whose only per-image setting is the focal point.
	 *
	 * @return array<string, mixed>
	 */
	public static function item_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array( 'focal' => self::schema() ),
		);
	}

	/**
	 * Custom properties of the item's focal point; empty for the centre (the stylesheet default) and for no setting.
	 */
	public static function style( RenderItem $item ): string {
		$focal = $item->settings['focal'] ?? null;
		if ( ! is_array( $focal ) ) {
			return '';
		}
		$x = PlanValues::percent( $focal['x'] ?? null );
		$y = PlanValues::percent( $focal['y'] ?? null );
		if ( 50.0 === $x && 50.0 === $y ) {
			return '';
		}
		return sprintf( '--swg-fx:%s%%;--swg-fy:%s%%;', PlanValues::number( $x ), PlanValues::number( $y ) );
	}
}
