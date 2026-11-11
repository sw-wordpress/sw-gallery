<?php
/**
 * The `grid` layout: a responsive grid of linked thumbnails (also the fallback layout).
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
use SWGallery\WordPress\Rendering\View\ImageMarkup;
use SWGallery\WordPress\Rendering\View\LightboxLabels;

/**
 * Emits the markup contract of the lightbox (assets/README.md): a list of plain links that work without JavaScript.
 * Column count and gap travel as CSS custom properties; the stylesheet follows with M3-08. Item markup, labels and the
 * caption allowlist live in `Rendering/View`; everything is escaped for its context.
 */
final class GridLayout implements GalleryLayoutInterface, ImageHintsProviderInterface {

	public const ID = 'grid';

	private const BREAKPOINTS = array( 'base', 'sm', 'md', 'lg', 'xl' );

	/** Provisional minimum viewport widths of the breakpoints, in pixels (see imageHints()). */
	private const BREAKPOINT_WIDTHS = array(
		'sm' => 576,
		'md' => 768,
		'lg' => 1024,
		'xl' => 1280,
	);

	private const DEFAULT_COLUMNS = 3;

	/** Container width the thumbnail target is derived from, in pixels. */
	private const REFERENCE_WIDTH = 1200;

	public function getId(): string {
		return self::ID;
	}

	public function getLabel(): string {
		return 'Grid';
	}

	public function getSettingsSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'columns' => array(
					'type'         => 'integer',
					'minimum'      => 1,
					'maximum'      => 12,
					'default'      => 3,
					'x-responsive' => true,
				),
				'gap'     => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 64,
					'default' => 8,
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
			$items .= ImageMarkup::lightbox_item( $item );
		}
		return sprintf(
			'<div class="swg-gallery swg-layout-grid" data-swg-gallery data-swg-instance="%1$s" data-swg-labels="%2$s" style="%3$s"><ul class="swg-items" role="list">%4$s</ul></div>',
			esc_attr( $request->instanceId ),
			LightboxLabels::attribute(),
			esc_attr( $this->custom_properties( $request->settings->values ) ),
			$items
		);
	}

	/**
	 * Thumbnails are displayed at roughly the container width divided by the column count. The breakpoints below are
	 * provisional until the grid stylesheet (M3-08) fixes them; `100vw / n` overestimates in narrow containers, which
	 * only costs a slightly larger download, never a blurry image.
	 */
	public function imageHints( GallerySettings $settings ): ImageHints {
		$columns = $this->responsive_columns( $settings->values );
		$largest = max( $columns['base'], ...array_values( $columns ) );
		$sizes   = array();
		foreach ( array_reverse( self::BREAKPOINT_WIDTHS, true ) as $breakpoint => $min_width ) {
			if ( isset( $columns[ $breakpoint ] ) ) {
				$sizes[] = sprintf( '(min-width: %dpx) calc(100vw / %d)', $min_width, $columns[ $breakpoint ] );
			}
		}
		$sizes[] = sprintf( 'calc(100vw / %d)', $columns['base'] );
		return new ImageHints(
			(int) ceil( self::REFERENCE_WIDTH / $largest ),
			implode( ', ', $sizes ),
			max( 1, min( ImageHints::MAX_FIRST_ROW, $largest ) )
		);
	}

	/**
	 * Column count per breakpoint that is set explicitly, `base` always present.
	 *
	 * @param array<string, mixed> $values Layout settings.
	 * @return array<string, int>
	 */
	private function responsive_columns( array $values ): array {
		$columns = $values['columns'] ?? self::DEFAULT_COLUMNS;
		$result  = array( 'base' => self::DEFAULT_COLUMNS );
		if ( is_int( $columns ) ) {
			$result['base'] = max( 1, min( 12, $columns ) );
		} elseif ( is_array( $columns ) ) {
			foreach ( self::BREAKPOINTS as $breakpoint ) {
				if ( isset( $columns[ $breakpoint ] ) && is_int( $columns[ $breakpoint ] ) ) {
					$result[ $breakpoint ] = max( 1, min( 12, $columns[ $breakpoint ] ) );
				}
			}
		}
		return $result;
	}

	/**
	 * CSS custom properties for columns (responsive) and gap. Only validated integers are written.
	 *
	 * @param array<string, mixed> $values Layout settings.
	 */
	private function custom_properties( array $values ): string {
		$columns = $values['columns'] ?? 3;
		$gap     = $values['gap'] ?? 8;
		$css     = '';

		if ( is_array( $columns ) ) {
			foreach ( self::BREAKPOINTS as $breakpoint ) {
				if ( isset( $columns[ $breakpoint ] ) && is_int( $columns[ $breakpoint ] ) ) {
					$css .= sprintf( '--swg-columns%s:%d;', 'base' === $breakpoint ? '' : '-' . $breakpoint, max( 1, min( 12, $columns[ $breakpoint ] ) ) );
				}
			}
		} elseif ( is_int( $columns ) ) {
			$css .= sprintf( '--swg-columns:%d;', max( 1, min( 12, $columns ) ) );
		}
		if ( '' === $css ) {
			$css = '--swg-columns:3;';
		}

		return $css . sprintf( '--swg-gap:%dpx', is_int( $gap ) ? max( 0, min( 64, $gap ) ) : 8 );
	}
}
