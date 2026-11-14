<?php
/**
 * The `mosaic` layout: a gap-free treemap mosaic whose tile areas follow each picture's emphasis, pure CSS.
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
use SWGallery\Core\Domain\Layout\Plan\LayoutPlan;
use SWGallery\Core\Domain\Layout\Plan\PlanItem;
use SWGallery\Core\Domain\Layout\Plan\PlanSeed;
use SWGallery\Core\Domain\Layout\Plan\TreemapPlanner;
use SWGallery\Core\Domain\Layout\RenderItem;
use SWGallery\WordPress\Rendering\View\ImageMarkup;
use SWGallery\WordPress\Rendering\View\LightboxLabels;
use SWGallery\WordPress\Rendering\View\ResponsiveSetting;

/**
 * Treemap mosaic (L-02). The core {@see TreemapPlanner} turns the page's items (aspect ratio, `emphasis`, seed =
 * gallery id + stored revision) into rectangles in percent of the container, once per container variant (`n` narrow
 * 4:5, `m` medium 1:1, `w` wide, default 16:10). Every item carries all three rectangles as validated numbers in its
 * inline `style` (`--swg-n-x`, `--swg-n-y`, `--swg-n-w`, `--swg-n-h`, `--swg-m-*`, `--swg-w-*`) and the focal point as
 * `--swg-fx`/`--swg-fy`; the container carries the three aspect ratios (`--swg-n-ar`, `--swg-m-ar`, `--swg-w-ar`). The
 * stylesheet (`assets/frontend/src/mosaic.css`) picks the variant with container queries and positions the tiles
 * absolutely; below a narrow container width (and in browsers without container queries) the list is a plain grid.
 * DOM order is the stored order, no JavaScript is involved.
 */
final class MosaicLayout implements GalleryLayoutInterface, ImageHintsProviderInterface {

	public const ID = 'mosaic';

	public const DEFAULT_GAP    = 6;
	public const MAX_GAP        = 64;
	public const DEFAULT_RADIUS = 0;
	public const MAX_RADIUS     = 64;

	/** Container width/height of the wide variant per `ratio` setting; `auto` is 16:10 (narrow 4:5 and medium 1:1 stay). */
	private const RATIOS = array(
		'auto' => 1.6,
		'1:1'  => 1.0,
		'4:3'  => 4 / 3,
		'3:2'  => 1.5,
		'16:9' => 16 / 9,
	);

	/** Variant keys and their container aspect ratios when the wide ratio is `auto`. */
	private const NARROW_RATIO = 0.8;
	private const MEDIUM_RATIO = 1.0;

	/** Thumbnail width target in pixels: the typical large tile (emphasis 3) of a wide container, see imageHints(). */
	private const DISPLAY_WIDTH = 800;

	/** Pictures that are certainly in the first row of the wide variant. */
	private const FIRST_ROW = 3;

	public function getId(): string {
		return self::ID;
	}

	public function getLabel(): string {
		return 'Mosaic';
	}

	public function getSettingsSchema(): array {
		return array(
			'type'            => 'object',
			'properties'      => array(
				'gap'    => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => self::MAX_GAP,
					'default' => self::DEFAULT_GAP,
				),
				'radius' => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'maximum'     => self::MAX_RADIUS,
					'default'     => self::DEFAULT_RADIUS,
					'title'       => __( 'Corner radius (px)', 'sw-gallery' ),
					'description' => __( '0 keeps the tiles square so the mosaic reads as one surface.', 'sw-gallery' ),
				),
				'ratio'  => array(
					'title'         => __( 'Shape of the wide mosaic', 'sw-gallery' ),
					'description'   => __( 'Width to height ratio of the whole mosaic in a wide container. Narrower containers use 1:1 and 4:5; below 480 px wide a plain grid is shown.', 'sw-gallery' ),
					'type'          => 'string',
					'enum'          => array_keys( self::RATIOS ),
					'x-enum-titles' => array(
						'auto' => __( 'Automatic (16:10)', 'sw-gallery' ),
						'1:1'  => __( 'Square (1:1)', 'sw-gallery' ),
						'4:3'  => __( 'Classic (4:3)', 'sw-gallery' ),
						'3:2'  => __( 'Photo (3:2)', 'sw-gallery' ),
						'16:9' => __( 'Widescreen (16:9)', 'sw-gallery' ),
					),
					'default'       => 'auto',
				),
			),
			'x-item-settings' => array(
				'type'       => 'object',
				'properties' => array(
					'emphasis' => array(
						'title'         => __( 'Size in the mosaic', 'sw-gallery' ),
						'description'   => __( 'How much area this picture gets: large pictures take about twice, extra large about three times the area of a normal one.', 'sw-gallery' ),
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
		$planner = new TreemapPlanner();
		$plans   = $planner->variants(
			array_map( array( self::class, 'plan_item' ), $request->items ),
			PlanSeed::from( $request->galleryId, $request->revision ),
			self::aspects( $ratio )
		);
		$items   = '';
		foreach ( $request->items as $index => $item ) {
			$items .= ImageMarkup::lightbox_item( $item, self::item_style( $plans, $index, $item ) );
		}
		$css = sprintf( '--swg-gap:%dpx;', ResponsiveSetting::integer( $values['gap'] ?? null, self::DEFAULT_GAP, 0, self::MAX_GAP ) )
			. sprintf( '--swg-thumb-radius:%dpx;', ResponsiveSetting::integer( $values['radius'] ?? null, self::DEFAULT_RADIUS, 0, self::MAX_RADIUS ) );
		foreach ( $plans as $key => $plan ) {
			$css .= sprintf( '--swg-%s-ar:%s;', $key, self::number( $plan->aspectRatio ) );
		}
		return sprintf(
			'<div class="swg-gallery swg-layout-mosaic" data-swg-gallery data-swg-instance="%1$s" data-swg-labels="%2$s" style="%3$s"><ul class="swg-items" role="list">%4$s</ul></div>',
			esc_attr( $request->instanceId ),
			LightboxLabels::attribute(),
			esc_attr( rtrim( $css, ';' ) ),
			$items
		);
	}

	/**
	 * Tiles have different widths, so the hints use the largest realistic tile: an extra large picture takes about
	 * three quarters of a medium container and rarely more than 800 px of a wide one. Galleries of one to three
	 * pictures can have tiles that are bigger than that and then look slightly soft; the common case keeps
	 * small tiles from loading oversized files. Below 480 px the list is a two column grid (50vw).
	 */
	public function imageHints( GallerySettings $settings ): ImageHints {
		return new ImageHints(
			self::DISPLAY_WIDTH,
			'(max-width: 479px) 50vw, (max-width: 1066px) 75vw, 800px',
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
	 * Inline custom properties of one item: the rectangle in every variant and, off-centre only, the focal point.
	 * Every value is a bounded float formatted by {@see number()}; nothing from the settings is echoed.
	 *
	 * @param array<string, LayoutPlan> $plans Plan per variant key.
	 * @param int                       $index Item index.
	 * @param RenderItem                $item  The item.
	 */
	private static function item_style( array $plans, int $index, RenderItem $item ): string {
		$css = '';
		foreach ( $plans as $key => $plan ) {
			$shape = $plan->shapes[ $index ];
			$css  .= sprintf(
				'--swg-%1$s-x:%2$s;--swg-%1$s-y:%3$s;--swg-%1$s-w:%4$s;--swg-%1$s-h:%5$s;',
				$key,
				self::number( $shape->x ),
				self::number( $shape->y ),
				self::number( $shape->w ),
				self::number( $shape->h )
			);
		}
		$plan_item = self::plan_item( $item );
		if ( 50.0 !== $plan_item->focalX || 50.0 !== $plan_item->focalY ) {
			$css .= sprintf( '--swg-fx:%s%%;--swg-fy:%s%%;', self::number( $plan_item->focalX ), self::number( $plan_item->focalY ) );
		}
		return rtrim( $css, ';' );
	}

	/** Locale independent decimal with at most three decimals, no trailing zeros. */
	private static function number( float $value ): string {
		$text = rtrim( rtrim( sprintf( '%.3F', $value ), '0' ), '.' );
		return '' === $text || '-0' === $text ? '0' : $text;
	}
}
