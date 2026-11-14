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

	/**
	 * Accessible name of the thumbnail link (FU-25), only needed when the image has no alt text (otherwise the image
	 * names the link and an aria-label would duplicate it): the item's title, else "Image N of M" with the position
	 * among the items of the whole gallery. Empty when nothing applies (position unknown).
	 */
	public static function link_label( RenderItem $item ): string {
		if ( '' !== trim( $item->alt ) ) {
			return '';
		}
		if ( '' !== trim( $item->title ) ) {
			return $item->title;
		}
		if ( $item->position <= 0 || $item->total < $item->position ) {
			return '';
		}
		/* translators: 1: position of the image in the gallery, 2: number of images in the gallery. */
		return sprintf( __( 'Image %1$d of %2$d', 'sw-gallery' ), $item->position, $item->total );
	}

	/**
	 * The lightbox link `<a class="swg-link">` with the thumbnail image (the only element the lightbox enhances).
	 */
	public static function lightbox_link( RenderItem $item ): string {
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
		$label = self::link_label( $item );
		if ( '' !== $label ) {
			$link .= sprintf( ' aria-label="%s"', esc_attr( $label ) );
		}
		return sprintf( '<a class="swg-link"%s>%s</a>', $link, self::image( $item->thumbnail, $item->alt, $item->loading ) );
	}

	/** The caption paragraph (allowlisted markup) or an empty string. */
	public static function caption( RenderItem $item ): string {
		return '' === $item->caption ? '' : sprintf( '<p class="swg-caption">%s</p>', CaptionPolicy::html( $item->caption ) );
	}

	/**
	 * `<li class="swg-item">` with the lightbox link, the thumbnail and the optional caption.
	 *
	 * @param RenderItem $item       The item.
	 * @param string     $item_style Optional style attribute value of the `li`; callers pass validated numbers only.
	 */
	public static function lightbox_item( RenderItem $item, string $item_style = '' ): string {
		return sprintf(
			'<li class="swg-item"%3$s>%1$s%2$s</li>',
			self::lightbox_link( $item ),
			self::caption( $item ),
			'' === $item_style ? '' : sprintf( ' style="%s"', esc_attr( $item_style ) )
		);
	}
}
