<?php
/**
 * Link-based pagination markup (decision D5): real links built from the current request URL, no JavaScript.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Rendering\View;

/**
 * One query argument per gallery (`swg_page_<galleryId>`), so several galleries on a page paginate independently.
 * Page 1 carries no argument. Every other query argument of the request is preserved; every URL goes through
 * `esc_url`. Feeds and the admin preview get a plain "page n of m" text instead of links.
 */
final class PaginationMarkup {

	/** Pages shown on each side of the current page. */
	public const WINDOW = 2;

	/** Highest page number accepted from a request (keeps offsets far from integer overflow). */
	public const MAX_PAGE = 100000;

	/** Name of the query argument that selects the page of one gallery. */
	public static function arg( int $gallery_id ): string {
		return 'swg_page_' . $gallery_id;
	}

	/**
	 * The requested page of a gallery from the current request (`$_GET`), 1 when absent or malformed.
	 */
	public static function requested_page( int $gallery_id ): int {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- public, read-only paging argument; validated by the strict digits pattern below.
		$value = isset( $_GET[ self::arg( $gallery_id ) ] ) ? wp_unslash( $_GET[ self::arg( $gallery_id ) ] ) : null;
		// phpcs:enable
		return is_string( $value ) && 1 === preg_match( '/^[0-9]{1,6}$/', $value ) ? max( 1, min( self::MAX_PAGE, (int) $value ) ) : 1;
	}

	/** The current request URI (path and query only), sanitised; empty outside web requests. */
	public static function current_url(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by esc_url_raw() below.
		$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		return esc_url_raw( $uri );
	}

	/**
	 * URL of one page of a gallery, based on `$base_url` with all other arguments kept.
	 *
	 * @param string $base_url   Current request URL.
	 * @param int    $gallery_id Gallery id.
	 * @param int    $page       Target page (1 drops the argument).
	 */
	public static function page_url( string $base_url, int $gallery_id, int $page ): string {
		$arg = self::arg( $gallery_id );
		return $page <= 1 ? remove_query_arg( $arg, $base_url ) : add_query_arg( $arg, (string) $page, $base_url );
	}

	/**
	 * Page numbers to show: first, last, the current page with its neighbours; `null` marks an ellipsis (a gap of a
	 * single page is filled instead).
	 *
	 * @param int $page        Current page.
	 * @param int $total_pages Number of pages.
	 * @return list<int|null>
	 */
	public static function window( int $page, int $total_pages ): array {
		$shown = array(
			1            => true,
			$total_pages => true,
		);
		for ( $i = $page - self::WINDOW; $i <= $page + self::WINDOW; $i++ ) {
			if ( $i >= 1 && $i <= $total_pages ) {
				$shown[ $i ] = true;
			}
		}
		$numbers = array_keys( $shown );
		sort( $numbers );
		$result   = array();
		$previous = 0;
		foreach ( $numbers as $number ) {
			if ( 2 === $number - $previous ) {
				$result[] = $previous + 1;
			} elseif ( $number - $previous > 2 ) {
				$result[] = null;
			}
			$result[] = $number;
			$previous = $number;
		}
		return $result;
	}

	/**
	 * The `<nav>` with previous/next, numbered pages and the current page marked. Empty for a single page.
	 *
	 * @param int    $gallery_id  Gallery id.
	 * @param string $title       Gallery title, used to tell several navigations apart for assistive technology.
	 * @param int    $page        Current page.
	 * @param int    $total_pages Number of pages.
	 * @param string $base_url    Current request URL.
	 */
	public static function nav( int $gallery_id, string $title, int $page, int $total_pages, string $base_url ): string {
		if ( $total_pages < 2 ) {
			return '';
		}
		$page  = max( 1, min( $total_pages, $page ) );
		$label = '' === trim( $title )
			? __( 'Gallery pages', 'sw-gallery' )
			/* translators: %s: gallery title. */
			: sprintf( __( 'Pages of gallery "%s"', 'sw-gallery' ), $title );

		$items = $page > 1
			? self::link_item( self::page_url( $base_url, $gallery_id, $page - 1 ), __( 'Previous page', 'sw-gallery' ), ' rel="prev"' )
			: self::disabled_item( __( 'Previous page', 'sw-gallery' ) );
		foreach ( self::window( $page, $total_pages ) as $number ) {
			if ( null === $number ) {
				$items .= '<li class="swg-pagination__item"><span class="swg-pagination__gap" aria-hidden="true">&hellip;</span></li>';
			} elseif ( $number === $page ) {
				$items .= sprintf( '<li class="swg-pagination__item"><span class="swg-pagination__current" aria-current="page">%d</span></li>', $number );
			} else {
				$items .= self::link_item(
					self::page_url( $base_url, $gallery_id, $number ),
					(string) $number,
					sprintf( ' aria-label="%s"', esc_attr( sprintf( /* translators: %d: page number. */ __( 'Page %d', 'sw-gallery' ), $number ) ) )
				);
			}
		}
		$items .= $page < $total_pages
			? self::link_item( self::page_url( $base_url, $gallery_id, $page + 1 ), __( 'Next page', 'sw-gallery' ), ' rel="next"' )
			: self::disabled_item( __( 'Next page', 'sw-gallery' ) );

		return sprintf( '<nav class="swg-pagination" aria-label="%1$s"><ul class="swg-pagination__list">%2$s</ul></nav>', esc_attr( $label ), $items );
	}

	/**
	 * Plain "page n of m" paragraph for feeds and the admin preview. Empty for a single page.
	 *
	 * @param int $page        Current page.
	 * @param int $total_pages Number of pages.
	 */
	public static function status( int $page, int $total_pages ): string {
		if ( $total_pages < 2 ) {
			return '';
		}
		return sprintf(
			'<p class="swg-pagination__status">%s</p>',
			esc_html( sprintf( /* translators: 1: current page, 2: number of pages. */ __( 'Page %1$d of %2$d', 'sw-gallery' ), $page, $total_pages ) )
		);
	}

	/**
	 * @param string $url   Target URL (escaped here).
	 * @param string $text  Visible text (escaped here).
	 * @param string $extra Trusted attribute markup (already escaped).
	 */
	private static function link_item( string $url, string $text, string $extra ): string {
		return sprintf( '<li class="swg-pagination__item"><a class="swg-pagination__link" href="%1$s"%2$s>%3$s</a></li>', esc_url( $url ), $extra, esc_html( $text ) );
	}

	/**
	 * @param string $text Visible text (escaped here).
	 */
	private static function disabled_item( string $text ): string {
		return sprintf( '<li class="swg-pagination__item"><span class="swg-pagination__disabled" aria-disabled="true">%s</span></li>', esc_html( $text ) );
	}
}
