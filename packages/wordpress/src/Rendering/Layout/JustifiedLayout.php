<?php
/**
 * The `justified` layout: equal-height rows that fill the container width, pure CSS.
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
use SWGallery\Core\Domain\Layout\RenderItem;
use SWGallery\WordPress\Rendering\View\ImageMarkup;
use SWGallery\WordPress\Rendering\View\LightboxLabels;
use SWGallery\WordPress\Rendering\View\ResponsiveSetting;

/**
 * Flickr-style rows without JavaScript. Every item is a flex item whose `flex-basis` is `ratio * rowHeight` and whose
 * `flex-grow` is proportional to the ratio (`--swg-ar` is set per item from the thumbnail's width and height). Free
 * space of a row is therefore shared in proportion to the ratios, which keeps every item of a row at the same height
 * while the row fills the container. The last row is held back by a zero-width filler with a huge `flex-grow`
 * (stylesheet), or stretched when `lastRow` is `justify`. DOM order is the stored order; the image keeps its
 * width/height attributes and an `aspect-ratio`, so nothing shifts when images load. The stylesheet is
 * `assets/frontend/src/layouts.css`.
 */
final class JustifiedLayout implements GalleryLayoutInterface, ImageHintsProviderInterface {

	public const ID = 'justified';

	public const DEFAULT_ROW_HEIGHT = 220;
	public const MIN_ROW_HEIGHT     = 80;
	public const MAX_ROW_HEIGHT     = 600;
	public const DEFAULT_GAP        = 8;
	public const MAX_GAP            = 64;

	/** Ratio used when an image has no usable dimensions (3:2). */
	public const FALLBACK_RATIO = 1.5;
	public const MIN_RATIO      = 0.25;
	public const MAX_RATIO      = 4.0;

	private const LAST_ROWS = array( 'left', 'justify' );

	/** Typical item width relative to the row height: landscape images plus the stretch of a row. */
	private const WIDTH_FACTOR = 2;

	/** Container width the number of eagerly loaded images is derived from, in pixels. */
	private const REFERENCE_WIDTH = 1200;

	public function getId(): string {
		return self::ID;
	}

	public function getLabel(): string {
		return 'Justified';
	}

	public function getSettingsSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'rowHeight' => array(
					'type'         => 'integer',
					'minimum'      => self::MIN_ROW_HEIGHT,
					'maximum'      => self::MAX_ROW_HEIGHT,
					'default'      => self::DEFAULT_ROW_HEIGHT,
					'x-responsive' => true,
				),
				'gap'       => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => self::MAX_GAP,
					'default' => self::DEFAULT_GAP,
				),
				'lastRow'   => array(
					'type'    => 'string',
					'enum'    => self::LAST_ROWS,
					'default' => 'left',
				),
			),
		);
	}

	public function render( LayoutRenderRequest $request ): string {
		if ( array() === $request->items ) {
			return '';
		}
		$items = '';
		foreach ( $request->items as $item ) {
			$items .= ImageMarkup::lightbox_item( $item, '--swg-ar:' . self::ratio_value( $item ) );
		}
		$values = $request->settings->values;
		$class  = 'justify' === ( $values['lastRow'] ?? 'left' ) ? 'swg-gallery swg-layout-justified swg-last-justify' : 'swg-gallery swg-layout-justified';
		return sprintf(
			'<div class="%1$s" data-swg-gallery data-swg-instance="%2$s" data-swg-labels="%3$s" style="%4$s"><ul class="swg-items" role="list">%5$s</ul></div>',
			esc_attr( $class ),
			esc_attr( $request->instanceId ),
			LightboxLabels::attribute(),
			esc_attr( $this->custom_properties( $values ) ),
			$items
		);
	}

	/**
	 * Thumbnails are about `rowHeight * 2` wide (a 3:2 image stretched by its row; narrower portrait images are smaller,
	 * panoramas can be wider and may be a little soft). `min(100vw, ...)` keeps narrow viewports exact.
	 */
	public function imageHints( GallerySettings $settings ): ImageHints {
		$rows    = ResponsiveSetting::integers( $settings->values['rowHeight'] ?? null, self::DEFAULT_ROW_HEIGHT, self::MIN_ROW_HEIGHT, self::MAX_ROW_HEIGHT );
		$widths  = array();
		$current = $rows['base'];
		foreach ( ResponsiveSetting::BREAKPOINTS as $breakpoint ) {
			$current               = $rows[ $breakpoint ] ?? $current;
			$widths[ $breakpoint ] = sprintf( 'min(100vw, %dpx)', $current * self::WIDTH_FACTOR );
		}
		$smallest = min( $rows );
		return new ImageHints(
			max( $rows ) * self::WIDTH_FACTOR,
			ResponsiveSetting::sizes( $widths ),
			max( 1, min( ImageHints::MAX_FIRST_ROW, (int) floor( self::REFERENCE_WIDTH / ( $smallest * self::FALLBACK_RATIO ) ) ) )
		);
	}

	/**
	 * Width divided by height of the displayed thumbnail, clamped, as a plain decimal (always a dot, at most four
	 * decimals). Items without usable dimensions use the fallback ratio.
	 */
	public static function ratio( int $width, int $height ): float {
		if ( $width <= 0 || $height <= 0 ) {
			return self::FALLBACK_RATIO;
		}
		return max( self::MIN_RATIO, min( self::MAX_RATIO, $width / $height ) );
	}

	private static function ratio_value( RenderItem $item ): string {
		$ratio = self::ratio( $item->thumbnail->width, $item->thumbnail->height );
		// `%F` is locale independent; the value is a bounded float, never raw input.
		return rtrim( rtrim( sprintf( '%.4F', $ratio ), '0' ), '.' );
	}

	/**
	 * Custom properties for row height (responsive) and gap. Only clamped integers are written.
	 *
	 * @param array<string, mixed> $values Layout settings.
	 */
	private function custom_properties( array $values ): string {
		return ResponsiveSetting::declarations( $values['rowHeight'] ?? null, '--swg-row-height', 'px', self::DEFAULT_ROW_HEIGHT, self::MIN_ROW_HEIGHT, self::MAX_ROW_HEIGHT )
			. sprintf( '--swg-gap:%dpx', ResponsiveSetting::integer( $values['gap'] ?? null, self::DEFAULT_GAP, 0, self::MAX_GAP ) );
	}
}
