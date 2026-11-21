<?php
/**
 * The `jigsaw` layout: a uniform grid whose tiles interlock like puzzle pieces, pure CSS and one inline SVG.
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
use SWGallery\Core\Domain\Layout\Jigsaw\JigsawEdges;
use SWGallery\Core\Domain\Layout\Jigsaw\JigsawOutline;
use SWGallery\Core\Domain\Layout\LayoutRenderRequest;
use SWGallery\Core\Domain\Layout\Plan\PlanSeed;
use SWGallery\WordPress\Rendering\View\FocalPoint;
use SWGallery\WordPress\Rendering\View\ImageMarkup;
use SWGallery\WordPress\Rendering\View\LightboxLabels;
use SWGallery\WordPress\Rendering\View\ResponsiveSetting;

/**
 * Jigsaw (L-04). The core {@see JigsawEdges} decides tabs and blanks per piece for the page's items (seed = gallery id
 * + stored revision + `variation`), once per distinct column count, because the rows depend on it. Every edge
 * combination used becomes one `<clipPath clipPathUnits="objectBoundingBox">` (built by {@see JigsawOutline}) in one
 * inline SVG per gallery; its ids are `<instance>-jc<code>` (the render cache rewrites the instance id, also inside
 * `url(#...)`). An item carries the clip reference as `--swg-cpN:url(#<instance>-jc<code>)` for every breakpoint N
 * (0 base, 1 sm, 2 md, 3 lg, 4 xl: the media queries of layouts.css; the column count of the `columns` setting at that
 * breakpoint) where it differs from the previous breakpoint. Everything else is a validated integer or enum value.
 * The stylesheet is `assets/frontend/src/jigsaw.css`; no JavaScript. DOM order is the stored order.
 */
final class JigsawLayout implements GalleryLayoutInterface, ImageHintsProviderInterface {

	public const ID = 'jigsaw';

	public const DEFAULT_COLUMNS = 4;
	public const MIN_COLUMNS     = 2;
	public const MAX_COLUMNS     = 8;
	public const DEFAULT_GAP     = 0;
	public const MAX_GAP         = 16;
	public const DEFAULT_TAB     = 18;
	public const MIN_TAB         = 8;
	public const MAX_TAB         = 30;
	public const MAX_VARIATION   = 99;

	/** Slot width / height per `ratio` setting and the CSS `aspect-ratio` value. */
	private const RATIOS = array(
		'1:1' => array( 1.0, '1/1' ),
		'4:3' => array( 4 / 3, '4/3' ),
		'3:2' => array( 1.5, '3/2' ),
	);

	/** Container width the thumbnail target is derived from, in pixels. */
	private const REFERENCE_WIDTH = 1200;

	public function getId(): string {
		return self::ID;
	}

	public function getLabel(): string {
		return 'Jigsaw';
	}

	public function getSettingsSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'columns'   => array(
					'title'        => __( 'Pieces per row', 'sw-gallery' ),
					'description'  => __( 'Below 480 px wide a plain two column grid is shown.', 'sw-gallery' ),
					'type'         => 'integer',
					'minimum'      => self::MIN_COLUMNS,
					'maximum'      => self::MAX_COLUMNS,
					'default'      => self::DEFAULT_COLUMNS,
					'x-responsive' => true,
				),
				'gap'       => array(
					'title'       => __( 'Gap between pieces (px)', 'sw-gallery' ),
					'description' => __( '0 lets the pieces touch like a finished puzzle.', 'sw-gallery' ),
					'type'        => 'integer',
					'minimum'     => 0,
					'maximum'     => self::MAX_GAP,
					'default'     => self::DEFAULT_GAP,
				),
				'ratio'     => array(
					'title'         => __( 'Piece shape', 'sw-gallery' ),
					'type'          => 'string',
					'enum'          => array_keys( self::RATIOS ),
					'x-enum-titles' => array(
						'1:1' => __( 'Square (1:1)', 'sw-gallery' ),
						'4:3' => __( 'Classic (4:3)', 'sw-gallery' ),
						'3:2' => __( 'Photo (3:2)', 'sw-gallery' ),
					),
					'default'       => '1:1',
				),
				'tab'       => array(
					'title'       => __( 'Tab size (%)', 'sw-gallery' ),
					'description' => __( 'How far the tabs reach out of a piece, in percent of the piece width.', 'sw-gallery' ),
					'type'        => 'integer',
					'minimum'     => self::MIN_TAB,
					'maximum'     => self::MAX_TAB,
					'default'     => self::DEFAULT_TAB,
				),
				'variation' => array(
					'title'       => __( 'Variation', 'sw-gallery' ),
					'description' => __( 'Another number gives another arrangement of tabs and blanks.', 'sw-gallery' ),
					'type'        => 'integer',
					'minimum'     => 0,
					'maximum'     => self::MAX_VARIATION,
					'default'     => 0,
				),
			),
			'x-item-settings' => FocalPoint::item_schema(),
		);
	}

	public function render( LayoutRenderRequest $request ): string {
		if ( array() === $request->items ) {
			return '';
		}
		$values    = $request->settings->values;
		$columns   = self::columns( $values['columns'] ?? null );
		$tab       = self::tab( $values['tab'] ?? null );
		$ratio_key = self::ratio_key( $values['ratio'] ?? null );
		$variation = ResponsiveSetting::integer( $values['variation'] ?? null, 0, 0, self::MAX_VARIATION );
		$seed      = PlanSeed::from( $request->galleryId, $request->revision, $variation );
		$count     = count( $request->items );

		$codes = array();
		foreach ( array_unique( array_values( $columns ) ) as $column_count ) {
			$codes[ $column_count ] = JigsawEdges::codes( $count, $column_count, $seed );
		}
		$used  = array();
		$items = '';
		foreach ( $request->items as $index => $item ) {
			$style    = '';
			$previous = -1;
			foreach ( array_values( $columns ) as $number => $column_count ) {
				$code = $codes[ $column_count ][ $index ];
				if ( $code !== $previous ) {
					$style        .= sprintf( '--swg-cp%d:url(#%s-jc%d);', $number, $request->instanceId, $code );
					$used[ $code ] = true;
					$previous      = $code;
				}
			}
			$items .= ImageMarkup::lightbox_item( $item, rtrim( FocalPoint::style( $item ) . $style, ';' ) );
		}

		$css = ResponsiveSetting::declarations( $values['columns'] ?? null, '--swg-columns', '', self::DEFAULT_COLUMNS, self::MIN_COLUMNS, self::MAX_COLUMNS )
			. sprintf( '--swg-gap:%dpx;--swg-tab:%d;--swg-jr:%s;--swg-jaspect:%s', ResponsiveSetting::integer( $values['gap'] ?? null, self::DEFAULT_GAP, 0, self::MAX_GAP ), $tab, self::number( self::RATIOS[ $ratio_key ][0] ), self::RATIOS[ $ratio_key ][1] );
		return sprintf(
			'<div class="swg-gallery swg-layout-jigsaw" data-swg-gallery data-swg-instance="%1$s" data-swg-labels="%2$s" style="%3$s">%4$s<ul class="swg-items" role="list">%5$s</ul></div>',
			esc_attr( $request->instanceId ),
			LightboxLabels::attribute(),
			esc_attr( $css ),
			self::definitions( $request->instanceId, array_keys( $used ), $tab / 100, self::RATIOS[ $ratio_key ][0] ),
			$items
		);
	}

	/**
	 * The pieces are drawn in a box larger than the slot by the tab depth on each side: `1 + 2 * tab` slot widths.
	 * Breakpoints are the media queries of the stylesheet; `100vw` overestimates in narrow containers.
	 */
	public function imageHints( GallerySettings $settings ): ImageHints {
		$columns = self::columns( $settings->values['columns'] ?? null );
		$factor  = 1 + 2 * self::tab( $settings->values['tab'] ?? null ) / 100;
		$sizes   = array();
		foreach ( $columns as $breakpoint => $count ) {
			$sizes[ $breakpoint ] = sprintf( 'calc(100vw * %s / %d)', self::number( $factor ), $count );
		}
		$largest = max( $columns );
		return new ImageHints(
			(int) ceil( self::REFERENCE_WIDTH * $factor / $largest ),
			ResponsiveSetting::sizes( $sizes ),
			max( 1, min( ImageHints::MAX_FIRST_ROW, $largest ) )
		);
	}

	/**
	 * The zero sized, hidden (but not `display:none`: that breaks `clip-path` references in some browsers) SVG that
	 * holds one clip path per edge combination in use.
	 *
	 * @param string          $instance Instance id (ids are prefixed with it).
	 * @param array<int, int> $codes Edge codes in use.
	 * @param float           $depth    Tab depth in slot widths.
	 * @param float           $ratio    Slot width / height.
	 */
	private static function definitions( string $instance, array $codes, float $depth, float $ratio ): string {
		sort( $codes );
		$paths = '';
		foreach ( $codes as $code ) {
			$paths .= sprintf(
				'<clipPath id="%s-jc%d" clipPathUnits="objectBoundingBox"><path d="%s"/></clipPath>',
				esc_attr( $instance ),
				$code,
				esc_attr( JigsawOutline::path( $code, $depth, $ratio ) )
			);
		}
		return '<svg class="swg-jigsaw-defs" width="0" height="0" aria-hidden="true" focusable="false"><defs>' . $paths . '</defs></svg>';
	}

	/**
	 * @param mixed $value Raw `columns` setting.
	 * @return non-empty-array<string, int>
	 */
	private static function columns( mixed $value ): array {
		return ResponsiveSetting::effective( $value, self::DEFAULT_COLUMNS, self::MIN_COLUMNS, self::MAX_COLUMNS );
	}

	private static function tab( mixed $value ): int {
		return ResponsiveSetting::integer( $value, self::DEFAULT_TAB, self::MIN_TAB, self::MAX_TAB );
	}

	/** The setting is validated upstream; anything unexpected is `1:1`. */
	private static function ratio_key( mixed $value ): string {
		return is_string( $value ) && isset( self::RATIOS[ $value ] ) ? $value : '1:1';
	}

	/** Locale independent decimal with at most four decimals, no trailing zeros. */
	private static function number( float $value ): string {
		$text = rtrim( rtrim( sprintf( '%.4F', $value ), '0' ), '.' );
		return '' === $text ? '0' : $text;
	}
}
