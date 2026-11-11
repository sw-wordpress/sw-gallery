<?php
/**
 * Simplified gallery markup for feeds, excerpts and `content.rendered`-like contexts (decision F-19).
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- core value objects use camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Rendering\View;

use SWGallery\Core\Contract\SimpleMarkupRendererInterface;
use SWGallery\Core\Domain\Layout\LayoutRenderRequest;
use SWGallery\Core\Domain\Layout\RenderItem;

/**
 * A plain `<ul>` of linked images with alt text and an optional plain-text caption. No classes, no data attributes,
 * no inline style, no script or stylesheet dependency: it reads correctly in any feed reader or excerpt.
 */
final class FeedMarkup implements SimpleMarkupRendererInterface {

	public function render( LayoutRenderRequest $request ): string {
		$items = '';
		foreach ( $request->items as $item ) {
			$items .= $this->item( $item );
		}
		return '' === $items ? '' : '<ul>' . $items . '</ul>';
	}

	private function item( RenderItem $item ): string {
		$link  = $item->full ?? $item->thumbnail;
		$image = sprintf( '<img src="%s" alt="%s"', esc_url( $item->thumbnail->url ), esc_attr( $item->alt ) );
		if ( $item->thumbnail->width > 0 && $item->thumbnail->height > 0 ) {
			$image .= sprintf( ' width="%d" height="%d"', $item->thumbnail->width, $item->thumbnail->height );
		}
		$image  .= '>';
		$caption = '' === $item->caption ? '' : '<p>' . CaptionPolicy::text( $item->caption ) . '</p>';
		return sprintf( '<li><a href="%s">%s</a>%s</li>', esc_url( $link->url ), $image, $caption );
	}
}
