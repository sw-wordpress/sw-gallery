<?php
/**
 * The `scattered` layout: photo prints lying on a table, slightly rotated and overlapping, pure CSS.
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
use SWGallery\Core\Domain\Layout\Scatter\ScatterPlan;
use SWGallery\Core\Domain\Layout\Scatter\ScatterPlanner;
use SWGallery\WordPress\Rendering\View\CssNumber;
use SWGallery\WordPress\Rendering\View\ImageMarkup;
use SWGallery\WordPress\Rendering\View\LightboxLabels;
use SWGallery\WordPress\Rendering\View\ResponsiveSetting;

/**
 * Scattered prints (L-10). A calm grid (2..8 columns, uniform cell ratio) whose prints are the cells scaled up by
 * `overlap`, rotated by up to `tilt` degrees and nudged a little. The core {@see ScatterPlanner} draws rotation, offsets
 * and the stacking order from the seed (gallery id + revision + `variation`) and guarantees that every print stays at
 * least 60 % visible in every grid the stylesheet can show. Per item the markup carries `--swg-rot`, `--swg-dx`,
 * `--swg-dy` (validated decimals with unit) and `--swg-z`; the container carries the columns, the effective overlap,
 * the border, the cell ratio and the bleed (how far prints leave their cells, so the stylesheet can pad the list and no
 * print creates horizontal scroll). DOM order is the stored order; the stylesheet is
 * `assets/frontend/src/scattered.css`, no JavaScript.
 */
final class ScatteredLayout implements GalleryLayoutInterface, ImageHintsProviderInterface {

	public const ID = 'scattered';

	public const DEFAULT_COLUMNS   = 3;
	public const MIN_COLUMNS       = 2;
	public const MAX_COLUMNS       = 8;
	public const DEFAULT_OVERLAP   = 12;
	public const MAX_OVERLAP       = 40;
	public const DEFAULT_TILT      = 5;
	public const MAX_TILT          = 12;
	public const DEFAULT_BORDER    = 10;
	public const MAX_BORDER        = 24;
	public const MAX_VARIATION     = 99;
	public const DEFAULT_RATIO_KEY = '3:2';

	/** Cell width divided by height per `ratio` setting. */
	private const RATIOS = array(
		'3:2' => 1.5,
		'4:3' => 4 / 3,
		'1:1' => 1.0,
		'2:3' => 2 / 3,
	);

	/** Container width the thumbnail target is derived from, in pixels. */
	private const REFERENCE_WIDTH = 1200;

	public function getId(): string {
		return self::ID;
	}

	public function getLabel(): string {
		return 'Scattered';
	}

	public function getSettingsSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'columns'   => array(
					'title'        => __( 'Columns', 'sw-gallery' ),
					'description'  => __( 'Prints per row. Containers narrower than 480 px always show two columns with half the tilt.', 'sw-gallery' ),
					'type'         => 'integer',
					'minimum'      => self::MIN_COLUMNS,
					'maximum'      => self::MAX_COLUMNS,
					'default'      => self::DEFAULT_COLUMNS,
					'x-responsive' => true,
				),
				'ratio'     => array(
					'title'         => __( 'Print shape', 'sw-gallery' ),
					'description'   => __( 'Width to height ratio of every print; pictures are cropped to fit.', 'sw-gallery' ),
					'type'          => 'string',
					'enum'          => array_keys( self::RATIOS ),
					'x-enum-titles' => array(
						'3:2' => __( 'Photo (3:2)', 'sw-gallery' ),
						'4:3' => __( 'Classic (4:3)', 'sw-gallery' ),
						'1:1' => __( 'Square (1:1)', 'sw-gallery' ),
						'2:3' => __( 'Portrait (2:3)', 'sw-gallery' ),
					),
					'default'       => self::DEFAULT_RATIO_KEY,
				),
				'overlap'   => array(
					'title'       => __( 'Overlap (%)', 'sw-gallery' ),
					'description' => __( 'How far neighbouring prints reach over each other. Larger values are reduced automatically so every print stays at least 60 % visible; touch screens use half.', 'sw-gallery' ),
					'type'        => 'integer',
					'minimum'     => 0,
					'maximum'     => self::MAX_OVERLAP,
					'default'     => self::DEFAULT_OVERLAP,
				),
				'tilt'      => array(
					'title'       => __( 'Tilt (degrees)', 'sw-gallery' ),
					'description' => __( 'Largest rotation of a print in either direction. 0 keeps the prints straight.', 'sw-gallery' ),
					'type'        => 'integer',
					'minimum'     => 0,
					'maximum'     => self::MAX_TILT,
					'default'     => self::DEFAULT_TILT,
				),
				'border'    => array(
					'title'       => __( 'Print border (px)', 'sw-gallery' ),
					'description' => __( 'Width of the white border around every picture.', 'sw-gallery' ),
					'type'        => 'integer',
					'minimum'     => 0,
					'maximum'     => self::MAX_BORDER,
					'default'     => self::DEFAULT_BORDER,
				),
				'variation' => array(
					'title'       => __( 'Variation', 'sw-gallery' ),
					'description' => __( 'Another number gives another arrangement of the same pictures.', 'sw-gallery' ),
					'type'        => 'integer',
					'minimum'     => 0,
					'maximum'     => self::MAX_VARIATION,
					'default'     => 0,
				),
			),
		);
	}

	public function render( LayoutRenderRequest $request ): string {
		if ( array() === $request->items ) {
			return '';
		}
		$values  = $request->settings->values;
		$ratio   = self::ratio( $values['ratio'] ?? null );
		$columns = ResponsiveSetting::integers( $values['columns'] ?? null, self::DEFAULT_COLUMNS, self::MIN_COLUMNS, self::MAX_COLUMNS );
		$plan    = ( new ScatterPlanner() )->plan(
			count( $request->items ),
			PlanSeed::from( $request->galleryId, $request->revision, ResponsiveSetting::integer( $values['variation'] ?? null, 0, 0, self::MAX_VARIATION ) ),
			(float) ResponsiveSetting::integer( $values['tilt'] ?? null, self::DEFAULT_TILT, 0, self::MAX_TILT ),
			(float) ResponsiveSetting::integer( $values['overlap'] ?? null, self::DEFAULT_OVERLAP, 0, self::MAX_OVERLAP ),
			$ratio,
			array_values( $columns )
		);

		$items = '';
		foreach ( $request->items as $index => $item ) {
			$items .= ImageMarkup::lightbox_item( $item, self::item_style( $plan, $index ) );
		}
		$css = ResponsiveSetting::declarations( $values['columns'] ?? null, '--swg-columns', '', self::DEFAULT_COLUMNS, self::MIN_COLUMNS, self::MAX_COLUMNS )
			. sprintf( '--swg-overlap:%s;', CssNumber::format( $plan->overlap, 2 ) )
			. sprintf( '--swg-border:%dpx;', ResponsiveSetting::integer( $values['border'] ?? null, self::DEFAULT_BORDER, 0, self::MAX_BORDER ) )
			. sprintf( '--swg-cell-ar:%s;', CssNumber::format( $ratio, 4 ) )
			. sprintf( '--swg-bleed-x:%s;', CssNumber::format( $plan->bleedX ) )
			. sprintf( '--swg-bleed-y:%s', CssNumber::format( $plan->bleedY ) );
		return sprintf(
			'<div class="swg-gallery swg-layout-scattered" data-swg-gallery data-swg-instance="%1$s" data-swg-labels="%2$s" style="%3$s"><ul class="swg-items" role="list">%4$s</ul></div>',
			esc_attr( $request->instanceId ),
			LightboxLabels::attribute(),
			esc_attr( $css ),
			$items
		);
	}

	/**
	 * A print is the cell scaled by the overlap (at most 1.4 x), a cell is the container divided by the column count.
	 * The narrow-container rule (two columns below 480 px) is covered by the first entry, which only matches there.
	 */
	public function imageHints( GallerySettings $settings ): ImageHints {
		$columns = ResponsiveSetting::integers( $settings->values['columns'] ?? null, self::DEFAULT_COLUMNS, self::MIN_COLUMNS, self::MAX_COLUMNS );
		$overlap = ResponsiveSetting::integer( $settings->values['overlap'] ?? null, self::DEFAULT_OVERLAP, 0, self::MAX_OVERLAP );
		$scale   = 1 + $overlap / 100;
		$sizes   = array();
		foreach ( $columns as $breakpoint => $count ) {
			$sizes[ $breakpoint ] = sprintf( 'calc(100vw * %s / %d)', CssNumber::format( $scale, 2 ), $count );
		}
		$base          = $sizes['base'];
		$sizes['base'] = sprintf( '(max-width: 479px) calc(100vw * %s / 2), %s', CssNumber::format( $scale, 2 ), $base );
		return new ImageHints(
			intdiv( self::REFERENCE_WIDTH * ( 100 + $overlap ) + 100 * min( $columns ) - 1, 100 * min( $columns ) ),
			ResponsiveSetting::sizes( $sizes ),
			max( 1, min( ImageHints::MAX_FIRST_ROW, max( $columns ) ) )
		);
	}

	/** Cell ratio for a validated `ratio` key; anything else is the default. */
	private static function ratio( mixed $value ): float {
		return self::RATIOS[ is_string( $value ) && isset( self::RATIOS[ $value ] ) ? $value : self::DEFAULT_RATIO_KEY ];
	}

	/**
	 * Inline custom properties of one print: rotation (deg), offsets (percent of the cell) and the stacking position.
	 *
	 * @param ScatterPlan $plan  The plan.
	 * @param int         $index Item index.
	 */
	private static function item_style( ScatterPlan $plan, int $index ): string {
		$print = $plan->prints[ $index ];
		return sprintf(
			'--swg-rot:%sdeg;--swg-dx:%s%%;--swg-dy:%s%%;--swg-z:%d',
			CssNumber::format( $print->rotation, 2 ),
			CssNumber::format( $print->dx, 2 ),
			CssNumber::format( $print->dy, 2 ),
			$print->z
		);
	}
}
