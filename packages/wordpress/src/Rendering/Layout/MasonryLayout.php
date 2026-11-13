<?php
/**
 * The `masonry` layout: row-ordered grid markup that a small script packs into balanced columns.
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
use SWGallery\WordPress\Rendering\View\ImageMarkup;
use SWGallery\WordPress\Rendering\View\LightboxLabels;
use SWGallery\WordPress\Rendering\View\ResponsiveSetting;

/**
 * Decision D10: the markup is a list in editor order and is a plain responsive grid without JavaScript (natural image
 * heights, items aligned to the start). `assets/frontend/src/masonry.ts` (reported as {@see RenderAsset::Masonry}) adds
 * a CSS-grid row-span packing; it never moves DOM nodes, so tab and screen reader order stay the editor order.
 */
final class MasonryLayout implements GalleryLayoutInterface, ImageHintsProviderInterface, LayoutAssetsProviderInterface {

	public const ID = 'masonry';

	private const DEFAULT_COLUMNS = 3;
	private const MAX_COLUMNS     = 12;
	private const DEFAULT_GAP     = 8;
	private const MAX_GAP         = 64;

	/** Container width the thumbnail target is derived from, in pixels. */
	private const REFERENCE_WIDTH = 1200;

	public function getId(): string {
		return self::ID;
	}

	public function getLabel(): string {
		return 'Masonry';
	}

	public function getSettingsSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'columns' => array(
					'type'         => 'integer',
					'minimum'      => 1,
					'maximum'      => self::MAX_COLUMNS,
					'default'      => self::DEFAULT_COLUMNS,
					'x-responsive' => true,
				),
				'gap'     => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => self::MAX_GAP,
					'default' => self::DEFAULT_GAP,
				),
			),
		);
	}

	public function renderAssets(): array {
		return array( RenderAsset::Masonry );
	}

	public function render( LayoutRenderRequest $request ): string {
		if ( array() === $request->items ) {
			return '';
		}
		$items = '';
		foreach ( $request->items as $item ) {
			$items .= ImageMarkup::lightbox_item( $item );
		}
		$values = $request->settings->values;
		$css    = ResponsiveSetting::declarations( $values['columns'] ?? null, '--swg-columns', '', self::DEFAULT_COLUMNS, 1, self::MAX_COLUMNS )
			. sprintf( '--swg-gap:%dpx', ResponsiveSetting::integer( $values['gap'] ?? null, self::DEFAULT_GAP, 0, self::MAX_GAP ) );
		return sprintf(
			'<div class="swg-gallery swg-layout-masonry" data-swg-gallery data-swg-instance="%1$s" data-swg-labels="%2$s" style="%3$s"><ul class="swg-items" role="list">%4$s</ul></div>',
			esc_attr( $request->instanceId ),
			LightboxLabels::attribute(),
			esc_attr( $css ),
			$items
		);
	}

	/** Same estimate as the grid: container width divided by the column count, the breakpoints are the stylesheet's. */
	public function imageHints( GallerySettings $settings ): ImageHints {
		$columns = ResponsiveSetting::integers( $settings->values['columns'] ?? null, self::DEFAULT_COLUMNS, 1, self::MAX_COLUMNS );
		$largest = max( $columns );
		$lengths = array();
		foreach ( $columns as $breakpoint => $count ) {
			$lengths[ $breakpoint ] = sprintf( 'calc(100vw / %d)', $count );
		}
		return new ImageHints(
			(int) ceil( self::REFERENCE_WIDTH / $largest ),
			ResponsiveSetting::sizes( $lengths ),
			max( 1, min( ImageHints::MAX_FIRST_ROW, $largest ) )
		);
	}
}
