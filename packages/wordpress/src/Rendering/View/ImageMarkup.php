<?php
/**
 * `<img>` and lightbox item markup shared by the layouts (markup contract: assets/README.md).
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- core value objects use camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Rendering\View;

use SWGallery\Core\Domain\Layout\LoadingStrategy;
use SWGallery\Core\Domain\Layout\RenderItem;
use SWGallery\Core\Domain\Layout\ResolvedImage;

final class ImageMarkup {

	/**
	 * The thumbnail. Dimensions are always written (no layout shift); decoding is always async; the first row loads
	 * eagerly and the very first image gets `fetchpriority="high"`, everything else is lazy.
	 */
	public static function image( ResolvedImage $image, string $alt, LoadingStrategy $loading ): string {
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
		$attributes .= LoadingStrategy::Lazy === $loading ? ' loading="lazy"' : ' loading="eager"';
		if ( LoadingStrategy::Priority === $loading ) {
			$attributes .= ' fetchpriority="high"';
		}
		return '<img' . $attributes . ' decoding="async">';
	}

	/** `<li class="swg-item">` with the lightbox link, the thumbnail and the optional caption. */
	public static function lightbox_item( RenderItem $item ): string {
		$full = $item->full ?? $item->thumbnail;
		$link = sprintf( ' href="%s"', esc_url( $full->url ) );
		if ( $full->width > 0 && $full->height > 0 ) {
			$link .= sprintf( ' data-swg-full-width="%d" data-swg-full-height="%d"', $full->width, $full->height );
		}
		if ( null !== $full->srcset && '' !== $full->srcset ) {
			$link .= sprintf( ' data-swg-full-srcset="%s"', esc_attr( $full->srcset ) );
		}
		if ( null !== $full->sizes && '' !== $full->sizes ) {
			$link .= sprintf( ' data-swg-full-sizes="%s"', esc_attr( $full->sizes ) );
		}
		$caption = '' === $item->caption ? '' : sprintf( '<p class="swg-caption">%s</p>', CaptionPolicy::html( $item->caption ) );
		return sprintf(
			'<li class="swg-item"><a class="swg-link"%1$s>%2$s</a>%3$s</li>',
			$link,
			self::image( $item->thumbnail, $item->alt, $item->loading ),
			$caption
		);
	}
}
