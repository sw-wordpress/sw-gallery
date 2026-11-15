<?php
/**
 * The `shattered` layout: pictures as glass shards (weighted Voronoi cells) that fill a rectangle without gaps, pure CSS.
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
use SWGallery\Core\Domain\Layout\Plan\PlanSeed;
use SWGallery\Core\Domain\Layout\Plan\Shard;
use SWGallery\Core\Domain\Layout\Plan\ShardPlan;
use SWGallery\Core\Domain\Layout\Plan\VoronoiPlanner;
use SWGallery\Core\Domain\Layout\RenderItem;
use SWGallery\WordPress\Rendering\View\ImageMarkup;
use SWGallery\WordPress\Rendering\View\LightboxLabels;
use SWGallery\WordPress\Rendering\View\PlanValues;
use SWGallery\WordPress\Rendering\View\ResponsiveSetting;

/**
 * Shattered mosaic (L-01). The core {@see VoronoiPlanner} turns the page's items (`emphasis`, seed = gallery id +
 * stored revision + the `variation` setting) into one convex Voronoi cell per picture, once per container variant
 * (`n` narrow 4:5, `m` medium 1:1, `w` wide, default 16:10), and moves every cell inwards by half the grout. Every
 * item carries, per variant, the bounding box of its shard (`--swg-{n|m|w}-x/y/w/h`, percent of the container) and
 * the outline as a complete `clip-path` value in percent of that box (`--swg-{n|m|w}-clip: polygon(...)`); the
 * container carries the three aspect ratios. The stylesheet (`assets/frontend/src/shattered.css`) picks the
 * variant with container queries, positions the tiles absolutely and clips the link, so hit-testing follows the
 * shard. Below a narrow container width (and in browsers without container queries) the list is a plain grid.
 * DOM order is the stored order, no JavaScript is involved. The grout is a plan input (converted to percent at a
 * reference container width per variant), so it is exact only near that width; see docs/rendering.md.
 */
final class ShatteredLayout implements GalleryLayoutInterface, ImageHintsProviderInterface {

	public const ID = 'shattered';

	public const DEFAULT_GROUT     = 3;
	public const MAX_GROUT         = 24;
	public const DEFAULT_VARIATION = 0;
	public const MAX_VARIATION     = 99;

	/** Container width/height of the wide variant per `ratio` setting; `auto` is 16:10 (narrow 4:5 and medium 1:1 stay). */
	private const RATIOS = array(
		'auto' => 1.6,
		'1:1'  => 1.0,
		'4:3'  => 4 / 3,
		'3:2'  => 1.5,
		'16:9' => 16 / 9,
	);

	private const NARROW_RATIO = 0.8;
	private const MEDIUM_RATIO = 1.0;

	/** Thumbnail width target in pixels: a large shard of a wide container, see imageHints(). */
	private const DISPLAY_WIDTH = 640;

	/** Pictures that are certainly in the first row of the wide variant. */
	private const FIRST_ROW = 3;

	public function getId(): string {
		return self::ID;
	}

	public function getLabel(): string {
		return 'Shattered';
	}

	public function getSettingsSchema(): array {
		return array(
			'type'            => 'object',
			'properties'      => array(
				'grout'     => array(
					'title'       => __( 'Grout width (px)', 'sw-gallery' ),
					'description' => __( 'Width of the gaps between the shards. 0 lets the pictures touch.', 'sw-gallery' ),
					'type'        => 'integer',
					'minimum'     => 0,
					'maximum'     => self::MAX_GROUT,
					'default'     => self::DEFAULT_GROUT,
				),
				'variation' => array(
					'title'       => __( 'Variation', 'sw-gallery' ),
					'description' => __( 'Changes the shape of the shards without touching the pictures. Try another number until it looks right.', 'sw-gallery' ),
					'type'        => 'integer',
					'minimum'     => 0,
					'maximum'     => self::MAX_VARIATION,
					'default'     => self::DEFAULT_VARIATION,
				),
				'ratio'     => array(
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
		$values    = $request->settings->values;
		$grout     = ResponsiveSetting::integer( $values['grout'] ?? null, self::DEFAULT_GROUT, 0, self::MAX_GROUT );
		$variation = ResponsiveSetting::integer( $values['variation'] ?? null, self::DEFAULT_VARIATION, 0, self::MAX_VARIATION );
		$plans     = ( new VoronoiPlanner() )->variants(
			array_map( array( PlanValues::class, 'plan_item' ), $request->items ),
			PlanSeed::from( $request->galleryId, $request->revision, $variation ),
			self::aspects( self::ratio_key( $values['ratio'] ?? null ) ),
			(float) $grout
		);
		$items     = '';
		foreach ( $request->items as $index => $item ) {
			$items .= ImageMarkup::lightbox_item( $item, self::item_style( $plans, $index, $item ) );
		}
		$css = '';
		foreach ( $plans as $key => $plan ) {
			$css .= sprintf( '--swg-%s-ar:%s;', $key, PlanValues::number( $plan->aspectRatio ) );
		}
		return sprintf(
			'<div class="swg-gallery swg-layout-shattered" data-swg-gallery data-swg-instance="%1$s" data-swg-labels="%2$s" style="%3$s"><ul class="swg-items" role="list">%4$s</ul></div>',
			esc_attr( $request->instanceId ),
			LightboxLabels::attribute(),
			esc_attr( rtrim( $css, ';' ) ),
			$items
		);
	}

	/**
	 * Shards differ in size, so the hints use a large realistic shard: an extra large picture takes about 60 % of the
	 * width of a medium container and rarely more than 640 px of a wide one. Galleries of one to three pictures have
	 * bigger shards and then look slightly soft; the common case keeps small shards from loading oversized files.
	 * Below 480 px the list is a two column grid (50vw).
	 */
	public function imageHints( GallerySettings $settings ): ImageHints {
		return new ImageHints(
			self::DISPLAY_WIDTH,
			'(max-width: 479px) 50vw, (max-width: 1066px) 60vw, 640px',
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

	/**
	 * Inline custom properties of one item: per variant the bounding box of the shard and its outline, and, off-centre
	 * only, the focal point. Every value is a bounded float formatted by {@see PlanValues::number()}; the polygon is
	 * built from those numbers only, nothing from the settings is echoed.
	 *
	 * @param array<string, ShardPlan> $plans Plan per variant key.
	 * @param int                      $index Item index.
	 * @param RenderItem               $item  The item.
	 */
	private static function item_style( array $plans, int $index, RenderItem $item ): string {
		$css = '';
		foreach ( $plans as $key => $plan ) {
			$shard = $plan->shards[ $index ];
			$box   = $shard->box();
			$css  .= sprintf(
				'--swg-%1$s-x:%2$s;--swg-%1$s-y:%3$s;--swg-%1$s-w:%4$s;--swg-%1$s-h:%5$s;--swg-%1$s-clip:%6$s;',
				$key,
				PlanValues::number( $box['x'] ),
				PlanValues::number( $box['y'] ),
				PlanValues::number( $box['w'] ),
				PlanValues::number( $box['h'] ),
				self::clip_path( $shard )
			);
		}
		$plan_item = PlanValues::plan_item( $item );
		if ( 50.0 !== $plan_item->focalX || 50.0 !== $plan_item->focalY ) {
			$css .= sprintf( '--swg-fx:%s%%;--swg-fy:%s%%;', PlanValues::number( $plan_item->focalX ), PlanValues::number( $plan_item->focalY ) );
		}
		return rtrim( $css, ';' );
	}

	/** The outline as `polygon(x% y%,...)` in percent of the tile: numbers and fixed punctuation only. */
	private static function clip_path( Shard $shard ): string {
		$points = array();
		foreach ( $shard->clip() as $point ) {
			$points[] = PlanValues::number( $point[0] ) . '% ' . PlanValues::number( $point[1] ) . '%';
		}
		return 'polygon(' . implode( ',', $points ) . ')';
	}
}
