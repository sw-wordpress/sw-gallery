<?php
/**
 * The `carousel` layout (film strip): a horizontal scroll-snap strip that works without JavaScript.
 *
 * @package sw-gallery
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- core value objects use camelCase properties (CODE-STYLE 3.1).

declare(strict_types=1);

namespace SWGallery\WordPress\Rendering\Layout;

use SWGallery\Core\Application\Render\RenderAsset;
use SWGallery\Core\Contract\GalleryLayoutInterface;
use SWGallery\Core\Contract\ImageHintsProviderInterface;
use SWGallery\Core\Contract\LayoutAssetsProviderInterface;
use SWGallery\Core\Domain\Gallery\GallerySettings;
use SWGallery\Core\Domain\Layout\ImageHints;
use SWGallery\Core\Domain\Layout\LayoutRenderRequest;
use SWGallery\Core\Domain\Layout\RenderItem;
use SWGallery\WordPress\Rendering\View\ImageMarkup;
use SWGallery\WordPress\Rendering\View\LightboxLabels;
use SWGallery\WordPress\Rendering\View\ResponsiveSetting;

/**
 * Proposal L-15. The strip is a focusable, labelled scroll container (`tabindex="0"`, `role="region"`) holding the usual
 * `ul > li > a.swg-link` list in editor order; native CSS scroll snap does the work, so swiping, scrolling and the
 * arrow keys work without JavaScript. Each slide is as wide as its height times its aspect ratio (`--swg-ar`).
 * `assets/frontend/src/carousel.ts` (reported as {@see RenderAsset::Carousel}) only adds previous/next buttons and a
 * position indicator. There is no autoplay. The stylesheet is `assets/frontend/src/carousel.css`.
 */
final class CarouselLayout implements GalleryLayoutInterface, ImageHintsProviderInterface, LayoutAssetsProviderInterface {

	public const ID = 'carousel';

	public const DEFAULT_HEIGHT = 320;
	public const MIN_HEIGHT     = 120;
	public const MAX_HEIGHT     = 800;
	public const DEFAULT_GAP    = 8;
	public const MAX_GAP        = 64;
	public const DEFAULT_RADIUS = 4;
	public const MAX_RADIUS     = 64;

	private const SNAPS = array( 'start', 'center' );

	/** Typical slide width relative to its height (a 3:2 landscape image). */
	private const WIDTH_FACTOR = 1.5;

	/** Container width the number of eagerly loaded slides is derived from, in pixels. */
	private const REFERENCE_WIDTH = 1200;

	public function getId(): string {
		return self::ID;
	}

	public function getLabel(): string {
		return 'Carousel';
	}

	public function getSettingsSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'height'  => array(
					'title'        => __( 'Slide height (px)', 'sw-gallery' ),
					'description'  => __( 'Every slide is as wide as its height times its aspect ratio.', 'sw-gallery' ),
					'type'         => 'integer',
					'minimum'      => self::MIN_HEIGHT,
					'maximum'      => self::MAX_HEIGHT,
					'default'      => self::DEFAULT_HEIGHT,
					'x-responsive' => true,
				),
				'gap'     => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => self::MAX_GAP,
					'default' => self::DEFAULT_GAP,
				),
				'radius'  => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => self::MAX_RADIUS,
					'default' => self::DEFAULT_RADIUS,
					'title'   => __( 'Corner radius (px)', 'sw-gallery' ),
				),
				'buttons' => array(
					'title'       => __( 'Previous/next buttons', 'sw-gallery' ),
					'description' => __( 'Shows buttons when JavaScript is available; swiping, scrolling and the arrow keys always work.', 'sw-gallery' ),
					'type'        => 'boolean',
					'default'     => true,
				),
				'snap'    => array(
					'title'         => __( 'Snap position', 'sw-gallery' ),
					'type'          => 'string',
					'enum'          => self::SNAPS,
					'x-enum-titles' => array(
						'start'  => __( 'Slide starts at the edge', 'sw-gallery' ),
						'center' => __( 'Slide is centred', 'sw-gallery' ),
					),
					'default'       => 'start',
				),
			),
		);
	}

	public function renderAssets(): array {
		return array( RenderAsset::Carousel );
	}

	public function render( LayoutRenderRequest $request ): string {
		if ( array() === $request->items ) {
			return '';
		}
		$items = '';
		foreach ( $request->items as $item ) {
			$items .= ImageMarkup::lightbox_item( $item, '--swg-ar:' . self::ratio_value( $item ) );
		}
		$values  = $request->settings->values;
		$classes = 'swg-gallery swg-layout-carousel';
		if ( 'center' === ( $values['snap'] ?? 'start' ) ) {
			$classes .= ' swg-snap-center';
		}
		if ( false === ( $values['buttons'] ?? true ) ) {
			$classes .= ' swg-no-buttons';
		}
		$css = ResponsiveSetting::declarations( $values['height'] ?? null, '--swg-carousel-height', 'px', self::DEFAULT_HEIGHT, self::MIN_HEIGHT, self::MAX_HEIGHT )
			. sprintf( '--swg-gap:%dpx;', ResponsiveSetting::integer( $values['gap'] ?? null, self::DEFAULT_GAP, 0, self::MAX_GAP ) )
			. sprintf( '--swg-thumb-radius:%dpx', ResponsiveSetting::integer( $values['radius'] ?? null, self::DEFAULT_RADIUS, 0, self::MAX_RADIUS ) );
		return sprintf(
			'<div class="%1$s" data-swg-gallery data-swg-instance="%2$s" data-swg-labels="%3$s" style="%4$s"><div class="swg-carousel__frame"><div class="swg-carousel__viewport" tabindex="0" role="region" aria-label="%5$s"><ul class="swg-items" role="list">%6$s</ul></div></div></div>',
			esc_attr( $classes ),
			esc_attr( $request->instanceId ),
			LightboxLabels::attribute( array( 'carousel' => __( 'Image carousel', 'sw-gallery' ) ) ),
			esc_attr( $css ),
			esc_attr( __( 'Image carousel', 'sw-gallery' ) ),
			$items
		);
	}

	/**
	 * Slides are requested by height: a slide is `height * ratio` wide, estimated with a 3:2 ratio. Portrait slides
	 * therefore download a somewhat larger candidate than needed, panoramas may be a little soft; that is the price of
	 * one thumbnail per item (the resolver never crops). `min(100vw, ...)` keeps narrow viewports exact.
	 */
	public function imageHints( GallerySettings $settings ): ImageHints {
		$heights = ResponsiveSetting::integers( $settings->values['height'] ?? null, self::DEFAULT_HEIGHT, self::MIN_HEIGHT, self::MAX_HEIGHT );
		$widths  = array();
		foreach ( $heights as $breakpoint => $height ) {
			$widths[ $breakpoint ] = sprintf( 'min(100vw, %dpx)', (int) round( $height * self::WIDTH_FACTOR ) );
		}
		return new ImageHints(
			(int) round( max( $heights ) * self::WIDTH_FACTOR ),
			ResponsiveSetting::sizes( $widths ),
			max( 1, min( ImageHints::MAX_FIRST_ROW, (int) floor( self::REFERENCE_WIDTH / ( min( $heights ) * self::WIDTH_FACTOR ) ) ) )
		);
	}

	/** Width divided by height of the thumbnail as a bounded decimal (same clamp as the justified layout). */
	private static function ratio_value( RenderItem $item ): string {
		$ratio = JustifiedLayout::ratio( $item->thumbnail->width, $item->thumbnail->height );
		return rtrim( rtrim( sprintf( '%.4F', $ratio ), '0' ), '.' );
	}
}
