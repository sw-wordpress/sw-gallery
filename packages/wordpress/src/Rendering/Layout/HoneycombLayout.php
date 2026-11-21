<?php
/**
 * The `honeycomb` layout: hexagon (or diamond) cells in interlocking, half-cell offset rows, pure CSS.
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
use SWGallery\WordPress\Rendering\View\FocalPoint;
use SWGallery\WordPress\Rendering\View\ImageMarkup;
use SWGallery\WordPress\Rendering\View\LightboxLabels;
use SWGallery\WordPress\Rendering\View\ResponsiveSetting;

/**
 * Honeycomb (L-07). The list is a CSS grid of `columns` tracks of `container / (columns + 0.5)`; items of odd rows
 * are pushed by half a cell (a partly filled last row keeps the offset and stays start aligned, RTL mirrors by
 * itself). The grid cannot know which items sit in odd rows (that depends on the column count of the current
 * breakpoint), so every item carries `--swg-hsN:1` for each breakpoint N (0 base, 1 sm, 2 md, 3 lg, 4 xl) where its
 * row number is odd; the stylesheet turns it into the offset margin. Only the number 1 and the validated class `swg-honeycomb-<shape>` reach the markup. Stylesheet:
 * `assets/frontend/src/honeycomb.css`; no JavaScript. DOM order is the stored order.
 */
final class HoneycombLayout implements GalleryLayoutInterface, ImageHintsProviderInterface {

	public const ID = 'honeycomb';

	public const DEFAULT_COLUMNS = 4;
	public const MIN_COLUMNS     = 2;
	public const MAX_COLUMNS     = 10;
	public const DEFAULT_GAP     = 6;
	public const MAX_GAP         = 32;

	private const SHAPES = array( 'hexagon', 'diamond' );

	/** Container width the thumbnail target is derived from, in pixels. */
	private const REFERENCE_WIDTH = 1200;

	public function getId(): string {
		return self::ID;
	}

	public function getLabel(): string {
		return 'Honeycomb';
	}

	public function getSettingsSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'columns' => array(
					'title'        => __( 'Cells per row', 'sw-gallery' ),
					'description'  => __( 'Below 480 px wide a plain two column grid is shown.', 'sw-gallery' ),
					'type'         => 'integer',
					'minimum'      => self::MIN_COLUMNS,
					'maximum'      => self::MAX_COLUMNS,
					'default'      => self::DEFAULT_COLUMNS,
					'x-responsive' => true,
				),
				'gap'     => array(
					'title'   => __( 'Gap between cells (px)', 'sw-gallery' ),
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => self::MAX_GAP,
					'default' => self::DEFAULT_GAP,
				),
				'shape'   => array(
					'title'         => __( 'Cell shape', 'sw-gallery' ),
					'type'          => 'string',
					'enum'          => self::SHAPES,
					'x-enum-titles' => array(
						'hexagon' => __( 'Hexagon', 'sw-gallery' ),
						'diamond' => __( 'Diamond', 'sw-gallery' ),
					),
					'default'       => 'hexagon',
				),
			),
			'x-item-settings' => FocalPoint::item_schema(),
		);
	}

	public function render( LayoutRenderRequest $request ): string {
		if ( array() === $request->items ) {
			return '';
		}
		$values  = $request->settings->values;
		$columns = self::columns( $values['columns'] ?? null );
		$items   = '';
		foreach ( $request->items as $index => $item ) {
			$items .= ImageMarkup::lightbox_item( $item, FocalPoint::style( $item ) . self::offset_style( $index, $columns ) );
		}
		$css = ResponsiveSetting::declarations( $values['columns'] ?? null, '--swg-columns', '', self::DEFAULT_COLUMNS, self::MIN_COLUMNS, self::MAX_COLUMNS )
			. sprintf( '--swg-gap:%dpx', ResponsiveSetting::integer( $values['gap'] ?? null, self::DEFAULT_GAP, 0, self::MAX_GAP ) );
		return sprintf(
			'<div class="swg-gallery swg-layout-honeycomb swg-honeycomb-%1$s" data-swg-gallery data-swg-instance="%2$s" data-swg-labels="%3$s" style="%4$s"><ul class="swg-items" role="list">%5$s</ul></div>',
			self::shape( $values['shape'] ?? null ),
			esc_attr( $request->instanceId ),
			LightboxLabels::attribute(),
			esc_attr( $css ),
			$items
		);
	}

	/**
	 * The cell is `container / (columns + 0.5)` wide at every breakpoint (the half cell is the row offset); the
	 * breakpoints are the media queries of the stylesheet, `100vw` overestimates in narrow containers.
	 */
	public function imageHints( GallerySettings $settings ): ImageHints {
		$columns = self::columns( $settings->values['columns'] ?? null );
		$sizes   = array();
		foreach ( $columns as $breakpoint => $count ) {
			$sizes[ $breakpoint ] = sprintf( 'calc(100vw / %d.5)', $count );
		}
		$largest = max( $columns );
		return new ImageHints(
			(int) ceil( self::REFERENCE_WIDTH / ( $largest + 0.5 ) ),
			ResponsiveSetting::sizes( $sizes ),
			max( 1, min( ImageHints::MAX_FIRST_ROW, $largest ) )
		);
	}

	/**
	 * @param mixed $value Raw `columns` setting.
	 * @return non-empty-array<string, int>
	 */
	private static function columns( mixed $value ): array {
		return ResponsiveSetting::effective( $value, self::DEFAULT_COLUMNS, self::MIN_COLUMNS, self::MAX_COLUMNS );
	}

	/**
	 * `--swg-hsN:1` for every breakpoint N where the item sits in an odd row (zero based row number).
	 *
	 * @param int                $index   Item index.
	 * @param array<string, int> $columns Effective columns per breakpoint.
	 */
	private static function offset_style( int $index, array $columns ): string {
		$css = '';
		foreach ( array_values( $columns ) as $number => $count ) {
			if ( 1 === intdiv( $index, $count ) % 2 ) {
				$css .= sprintf( '--swg-hs%d:1;', $number );
			}
		}
		return rtrim( $css, ';' );
	}

	/** The setting is validated upstream; anything unexpected is a hexagon. */
	private static function shape( mixed $value ): string {
		return is_string( $value ) && in_array( $value, self::SHAPES, true ) ? $value : 'hexagon';
	}
}
