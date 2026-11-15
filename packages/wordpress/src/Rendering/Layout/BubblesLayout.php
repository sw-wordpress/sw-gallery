<?php
/**
 * The `bubbles` layout: circles of different sizes packed tightly into a fixed-ratio container, pure CSS.
 *
 * @package sw-gallery
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- core value objects use camelCase properties (CODE-STYLE 3.1).

declare(strict_types=1);

namespace SWGallery\WordPress\Rendering\Layout;

use SWGallery\Core\Contract\GalleryLayoutInterface;
use SWGallery\Core\Contract\ImageHintsProviderInterface;
use SWGallery\Core\Domain\Gallery\GallerySettings;
use SWGallery\Core\Domain\Layout\ImageHints;
use SWGallery\Core\Domain\Layout\LayoutRenderRequest;
use SWGallery\Core\Domain\Layout\Plan\CirclePacker;
use SWGallery\Core\Domain\Layout\Plan\LayoutPlan;
use SWGallery\Core\Domain\Layout\Plan\PlanItem;
use SWGallery\Core\Domain\Layout\Plan\PlanSeed;
use SWGallery\Core\Domain\Layout\RenderItem;
use SWGallery\WordPress\Rendering\View\CssNumber;
use SWGallery\WordPress\Rendering\View\ImageMarkup;
use SWGallery\WordPress\Rendering\View\LightboxLabels;
use SWGallery\WordPress\Rendering\View\ResponsiveSetting;

/**
 * Bubbles (L-08). The core {@see CirclePacker} packs one circle per item (area = emphasis weight 1 : 1.8 : 3, placement
 * in stored order, topmost free position first) into the container of each variant (`n` narrow 4:5, `m` medium 1:1, `w`
 * wide, default 16:10), once per page. The markup is the mosaic's: every item carries the bounding box of its circle for
 * the three variants as validated numbers (`--swg-{n|m|w}-{x|y|w|h}`), the focal point as `--swg-fx`/`--swg-fy`, the
 * container the ratios (`--swg-{n|m|w}-ar`) and the gap. With `float` the items additionally carry a seeded float
 * delay, amplitude and period (`--swg-fd`, `--swg-fa`, `--swg-fp`) and the container the class `swg-float`; the
 * animation itself only runs for visitors without a reduced-motion preference. Below a container width of 480 px (and
 * without container queries) the list is a two column grid of circles. The stylesheet is
 * `assets/frontend/src/bubbles.css`, DOM order is the stored order.
 */
final class BubblesLayout implements GalleryLayoutInterface, ImageHintsProviderInterface {

	public const ID = 'bubbles';

	public const DEFAULT_GAP = 4;
	public const MAX_GAP     = 24;

	/** Container width/height of the wide variant per `ratio` setting; `auto` is 16:10 (narrow 4:5 and medium 1:1 stay). */
	private const RATIOS = array(
		'auto' => 1.6,
		'1:1'  => 1.0,
		'4:3'  => 4 / 3,
		'16:9' => 16 / 9,
	);

	private const NARROW_RATIO = 0.8;
	private const MEDIUM_RATIO = 1.0;

	/** Largest plausible circle diameter in pixels (an extra large circle of a medium or wide container). */
	private const DISPLAY_WIDTH = 480;

	private const FIRST_ROW = 3;

	public function getId(): string {
		return self::ID;
	}

	public function getLabel(): string {
		return 'Bubbles';
	}

	public function getSettingsSchema(): array {
		return array(
			'type'            => 'object',
			'properties'      => array(
				'gap'   => array(
					'title'       => __( 'Gap (px)', 'sw-gallery' ),
					'description' => __( 'Space between the bubbles; the circles shrink by it.', 'sw-gallery' ),
					'type'        => 'integer',
					'minimum'     => 0,
					'maximum'     => self::MAX_GAP,
					'default'     => self::DEFAULT_GAP,
				),
				'ratio' => array(
					'title'         => __( 'Shape of the wide bubble field', 'sw-gallery' ),
					'description'   => __( 'Width to height ratio of the whole field in a wide container. Narrower containers use 1:1 and 4:5; below 480 px wide two columns of circles are shown.', 'sw-gallery' ),
					'type'          => 'string',
					'enum'          => array_keys( self::RATIOS ),
					'x-enum-titles' => array(
						'auto' => __( 'Automatic (16:10)', 'sw-gallery' ),
						'1:1'  => __( 'Square (1:1)', 'sw-gallery' ),
						'4:3'  => __( 'Classic (4:3)', 'sw-gallery' ),
						'16:9' => __( 'Widescreen (16:9)', 'sw-gallery' ),
					),
					'default'       => 'auto',
				),
				'float' => array(
					'title'       => __( 'Gentle floating', 'sw-gallery' ),
					'description' => __( 'The bubbles drift slowly up and down. Never animated for visitors who prefer reduced motion.', 'sw-gallery' ),
					'type'        => 'boolean',
					'default'     => false,
				),
			),
			'x-item-settings' => array(
				'type'       => 'object',
				'properties' => array(
					'emphasis' => array(
						'title'         => __( 'Size of the bubble', 'sw-gallery' ),
						'description'   => __( 'How much area this bubble gets: large bubbles take about twice, extra large about three times the area of a normal one.', 'sw-gallery' ),
						'type'          => 'integer',
						'enum'          => array( 1, 2, 3 ),
						'x-enum-titles' => array(
							'1' => __( 'Normal', 'sw-gallery' ),
							'2' => __( 'Large', 'sw-gallery' ),
							'3' => __( 'Extra large', 'sw-gallery' ),
						),
						'default'       => 1,
					),
					'focal'    => array(
						'title'       => __( 'Focal point', 'sw-gallery' ),
						'description' => __( 'Which part of the picture stays visible when the bubble crops it, in percent from the left and the top (50 / 50 is the centre).', 'sw-gallery' ),
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
					),
				),
			),
		);
	}

	public function render( LayoutRenderRequest $request ): string {
		if ( array() === $request->items ) {
			return '';
		}
		$values  = $request->settings->values;
		$ratio   = self::ratio_key( $values['ratio'] ?? null );
		$float   = true === ( $values['float'] ?? false );
		$plans   = ( new CirclePacker() )->variants(
			array_map( array( self::class, 'plan_item' ), $request->items ),
			self::aspects( $ratio )
		);
		$items   = '';
		foreach ( $request->items as $index => $item ) {
			$items .= ImageMarkup::lightbox_item( $item, self::item_style( $plans, $index, $item, $float ? self::float_style( $request, $index ) : '' ) );
		}
		$css = sprintf( '--swg-gap:%dpx;', ResponsiveSetting::integer( $values['gap'] ?? null, self::DEFAULT_GAP, 0, self::MAX_GAP ) );
		foreach ( $plans as $key => $plan ) {
			$css .= sprintf( '--swg-%s-ar:%s;', $key, CssNumber::format( $plan->aspectRatio ) );
		}
		return sprintf(
			'<div class="swg-gallery swg-layout-bubbles%1$s" data-swg-gallery data-swg-instance="%2$s" data-swg-labels="%3$s" style="%4$s"><ul class="swg-items" role="list">%5$s</ul></div>',
			$float ? ' swg-float' : '',
			esc_attr( $request->instanceId ),
			LightboxLabels::attribute(),
			esc_attr( rtrim( $css, ';' ) ),
			$items
		);
	}

	/**
	 * The largest circle is an extra large one: about 45 % of a medium container and rarely more than 480 px of a wide
	 * one. Galleries of one to three pictures can have bigger circles and then look slightly soft; the common case keeps
	 * small circles from loading oversized files. Below 480 px the list is a two column grid (50vw).
	 */
	public function imageHints( GallerySettings $settings ): ImageHints {
		return new ImageHints(
			self::DISPLAY_WIDTH,
			'(max-width: 479px) 50vw, (max-width: 1066px) 45vw, 480px',
			self::FIRST_ROW
		);
	}

	/**
	 * Wide container aspect ratio per variant key for a validated `ratio` key.
	 *
	 * @param string $ratio Key of {@see RATIOS}.
	 * @return array<string, float>
	 */
	private static function aspects( string $ratio ): array {
		$wide = self::RATIOS[ $ratio ];
		return array(
			'n' => min( self::NARROW_RATIO, $wide ),
			'm' => min( self::MEDIUM_RATIO, $wide ),
			'w' => $wide,
		);
	}

	/** The setting is validated upstream; anything unexpected falls back to `auto`. */
	private static function ratio_key( mixed $value ): string {
		return is_string( $value ) && isset( self::RATIOS[ $value ] ) ? $value : 'auto';
	}

	private static function plan_item( RenderItem $item ): PlanItem {
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

	/** A focal coordinate: finite number clamped to 0..100, else the centre. */
	private static function percent( mixed $value ): float {
		return ( is_int( $value ) || is_float( $value ) ) && is_finite( (float) $value ) ? max( 0.0, min( 100.0, (float) $value ) ) : 50.0;
	}

	/**
	 * Seeded float parameters of one bubble: a negative delay (so the bubbles do not start in step), amplitude in
	 * pixels and period in seconds, all derived from the gallery id, the revision and the item index.
	 */
	private static function float_style( LayoutRenderRequest $request, int $index ): string {
		$seed = PlanSeed::from( $request->galleryId, $request->revision, $index + 1 );
		return sprintf(
			'--swg-fd:-%ss;--swg-fa:%dpx;--swg-fp:%ss',
			CssNumber::format( ( $seed % 800 ) / 100, 2 ),
			3 + intdiv( $seed, 800 ) % 6,
			CssNumber::format( 5 + ( intdiv( $seed, 4800 ) % 400 ) / 100, 2 )
		);
	}

	/**
	 * Inline custom properties of one item: the circle's bounding box in every variant, off-centre only the focal
	 * point, with `float` the float parameters. Every value is a bounded float formatted by {@see CssNumber}.
	 *
	 * @param array<string, LayoutPlan> $plans Plan per variant key.
	 * @param int                       $index Item index.
	 * @param RenderItem                $item  The item.
	 * @param string                    $extra Validated float declarations or an empty string.
	 */
	private static function item_style( array $plans, int $index, RenderItem $item, string $extra ): string {
		$css = '';
		foreach ( $plans as $key => $plan ) {
			$shape = $plan->shapes[ $index ];
			$css  .= sprintf(
				'--swg-%1$s-x:%2$s;--swg-%1$s-y:%3$s;--swg-%1$s-w:%4$s;--swg-%1$s-h:%5$s;',
				$key,
				CssNumber::format( $shape->x ),
				CssNumber::format( $shape->y ),
				CssNumber::format( $shape->w ),
				CssNumber::format( $shape->h )
			);
		}
		$plan_item = self::plan_item( $item );
		if ( 50.0 !== $plan_item->focalX || 50.0 !== $plan_item->focalY ) {
			$css .= sprintf( '--swg-fx:%s%%;--swg-fy:%s%%;', CssNumber::format( $plan_item->focalX ), CssNumber::format( $plan_item->focalY ) );
		}
		return rtrim( $css . $extra, ';' );
	}
}
