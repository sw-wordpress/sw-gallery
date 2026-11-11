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
use SWGallery\Core\Domain\Layout\LayoutRenderRequest;
use SWGallery\Core\Domain\Layout\RenderItem;
use SWGallery\Core\Domain\Layout\ResolvedImage;

/**
 * Emits the markup contract of the lightbox (assets/README.md): a list of plain links that work without JavaScript.
 * Column count and gap travel as CSS custom properties; the stylesheet and the loading strategy follow with M3-06/M3-08.
 * Everything is escaped for its context; captions pass a small inline-markup allowlist.
 */
final class GridLayout implements GalleryLayoutInterface {

	public const ID = 'grid';

	private const BREAKPOINTS = array( 'base', 'sm', 'md', 'lg', 'xl' );

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
			$items .= $this->item( $item );
		}

		return sprintf(
			'<div class="swg-gallery swg-layout-grid" data-swg-gallery data-swg-instance="%1$s" data-swg-labels="%2$s" style="%3$s"><ul class="swg-items" role="list">%4$s</ul></div>',
			esc_attr( $request->instanceId ),
			esc_attr( (string) wp_json_encode( $this->labels() ) ),
			esc_attr( $this->custom_properties( $request->settings->values ) ),
			$items
		);
	}

	private function item( RenderItem $item ): string {
		$full = $item->full ?? $item->thumbnail;

		$link  = sprintf( ' href="%s"', esc_url( $full->url ) );
		$link .= sprintf( ' data-swg-full-width="%d" data-swg-full-height="%d"', max( 0, $full->width ), max( 0, $full->height ) );
		if ( null !== $full->srcset && '' !== $full->srcset ) {
			$link .= sprintf( ' data-swg-full-srcset="%s"', esc_attr( $full->srcset ) );
		}
		if ( null !== $full->sizes && '' !== $full->sizes ) {
			$link .= sprintf( ' data-swg-full-sizes="%s"', esc_attr( $full->sizes ) );
		}

		$caption = '' === $item->caption ? '' : sprintf( '<p class="swg-caption">%s</p>', $this->caption( $item->caption ) );

		return sprintf(
			'<li class="swg-item"><a class="swg-link"%1$s>%2$s</a>%3$s</li>',
			$link,
			$this->image( $item->thumbnail, $item->alt ),
			$caption
		);
	}

	private function image( ResolvedImage $image, string $alt ): string {
		$attributes = sprintf( ' src="%s" alt="%s"', esc_url( $image->url ), esc_attr( $alt ) );
		if ( $image->width > 0 && $image->height > 0 ) {
			$attributes .= sprintf( ' width="%d" height="%d"', $image->width, $image->height );
		}
		if ( null !== $image->srcset && '' !== $image->srcset ) {
			$attributes .= sprintf( ' srcset="%s"', esc_attr( $image->srcset ) );
		}
		if ( null !== $image->sizes && '' !== $image->sizes ) {
			$attributes .= sprintf( ' sizes="%s"', esc_attr( $image->sizes ) );
		}
		return '<img' . $attributes . ' loading="lazy" decoding="async">';
	}

	private function caption( string $caption ): string {
		return wp_kses(
			$caption,
			array(
				'a'      => array(
					'href' => true,
					'rel'  => true,
				),
				'em'     => array(),
				'strong' => array(),
				'br'     => array(),
			)
		);
	}

	/**
	 * Translated labels of the lightbox (read by the frontend script from data-swg-labels).
	 *
	 * @return array<string, string>
	 */
	private function labels(): array {
		return array(
			'dialog'   => __( 'Image viewer', 'sw-gallery' ),
			'close'    => __( 'Close', 'sw-gallery' ),
			'previous' => __( 'Previous image', 'sw-gallery' ),
			'next'     => __( 'Next image', 'sw-gallery' ),
			/* translators: 1: {current} placeholder, 2: {total} placeholder; keep both placeholders unchanged. */
			'counter'  => __( '{current} of {total}', 'sw-gallery' ),
			'error'    => __( 'The image could not be loaded.', 'sw-gallery' ),
		);
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
