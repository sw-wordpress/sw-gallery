<?php
/**
 * Chooses image variants from the sizes WordPress already generated (decision F-20).
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the framework independent core's value objects expose camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Media;

use SWGallery\Core\Contract\ThumbnailResolverInterface;
use SWGallery\Core\Domain\Layout\ImageRequest;
use SWGallery\Core\Domain\Layout\ImageRole;
use SWGallery\Core\Domain\Layout\ResolvedImage;

/**
 * Reads only the primed attachment post and meta (no query per item) and builds `src`, `srcset` and dimensions itself,
 * so no `wp_calculate_image_srcset`/`wp_get_attachment_image_*` filter can change the contract. Rules:
 *
 * - Only sub-sizes that core or the active theme currently registers are used; the plugin never registers a size
 *   and never generates one. Cropping is done by CSS (`object-fit`), so only sizes with the original's aspect ratio
 *   qualify (hard-cropped sizes like `thumbnail` are ignored).
 * - The "full" file is the (possibly `-scaled`) file WordPress keeps in the metadata; `original_image` is never used.
 * - Thumbnail: the smallest candidate that is at least as wide as the requested display width, else the largest.
 * - Lightbox: the largest candidate up to {@see self::LIGHTBOX_MAX_WIDTH} (so `full` when it fits, a registered
 *   `large`-class size otherwise).
 * - `srcset` lists the candidates up to {@see self::SRCSET_MAX_WIDTH} (the chosen one always included) and is omitted
 *   when only one candidate exists. Width and height of the chosen file are always returned.
 * - Attachments without a usable file, dimensions or an allowed image type resolve to null.
 */
final class ThumbnailResolver implements ThumbnailResolverInterface {

	/** Largest image the lightbox opens, in pixels (the WordPress big-image threshold). */
	public const LIGHTBOX_MAX_WIDTH = 2560;

	/** Largest candidate listed in a `srcset`, in pixels. */
	public const SRCSET_MAX_WIDTH = 2048;

	/** @var list<string>|null */
	private ?array $allowed_types = null;

	/** @var array<string, mixed>|null */
	private ?array $registered_sizes = null;

	public function resolve( ImageRequest $request ): ?ResolvedImage {
		$post = get_post( $request->attachmentId );
		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type || 'inherit' !== $post->post_status || ! $this->is_allowed( $post->post_mime_type ) ) {
			return null;
		}
		$meta = wp_get_attachment_metadata( $post->ID );
		$url  = wp_get_attachment_url( $post->ID );
		if ( ! is_array( $meta ) || ! is_string( $url ) || '' === $url ) {
			return null;
		}
		$candidates = $this->candidates( $meta, $url );
		if ( array() === $candidates ) {
			return null;
		}

		$chosen = ImageRole::Lightbox === $request->role
			? $this->largest_up_to( $candidates, self::LIGHTBOX_MAX_WIDTH )
			: $this->for_display_width( $candidates, $request->displayWidth );

		$listed = array_values(
			array_filter(
				$candidates,
				static fn( array $c ): bool => $c['width'] <= ( ImageRole::Lightbox === $request->role ? self::LIGHTBOX_MAX_WIDTH : self::SRCSET_MAX_WIDTH ) || $c['url'] === $chosen['url']
			)
		);
		$srcset = null;
		if ( count( $listed ) > 1 ) {
			$srcset = implode( ', ', array_map( static fn( array $c ): string => $c['url'] . ' ' . $c['width'] . 'w', $listed ) );
		}

		return new ResolvedImage( $chosen['url'], $chosen['width'], $chosen['height'], $srcset, null === $srcset ? null : $request->sizes );
	}

	/**
	 * Full file first, then the registered same-ratio sub-sizes; sorted by width, unique per width.
	 *
	 * @param array<mixed> $meta Attachment metadata.
	 * @param string       $url  URL of the full file.
	 * @return list<array{url: string, width: int, height: int}>
	 */
	private function candidates( array $meta, string $url ): array {
		$full_width  = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
		$full_height = isset( $meta['height'] ) ? (int) $meta['height'] : 0;
		if ( $full_width <= 0 || $full_height <= 0 ) {
			return array();
		}
		$by_width = array(
			$full_width => array(
				'url'    => $url,
				'width'  => $full_width,
				'height' => $full_height,
			),
		);
		$sizes    = isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : array();
		if ( array() !== $sizes ) {
			$registered = $this->registered_sizes ??= wp_get_registered_image_subsizes();
			$base       = substr( $url, 0, (int) strrpos( $url, '/' ) + 1 );
			foreach ( $sizes as $name => $data ) {
				if ( ! is_array( $data ) || ! isset( $registered[ $name ], $data['file'], $data['width'], $data['height'] ) || ! is_string( $data['file'] ) ) {
					continue;
				}
				$width  = (int) $data['width'];
				$height = (int) $data['height'];
				$file   = basename( $data['file'] );
				if ( $width <= 0 || $height <= 0 || '' === $file || isset( $by_width[ $width ] ) || ! $this->same_ratio( $width, $height, $full_width, $full_height ) ) {
					continue;
				}
				$by_width[ $width ] = array(
					'url'    => $base . $file,
					'width'  => $width,
					'height' => $height,
				);
			}
		}
		ksort( $by_width );
		return array_values( $by_width );
	}

	/** Same tolerance as core: the height may differ by one pixel from the rounded proportional height. */
	private function same_ratio( int $width, int $height, int $full_width, int $full_height ): bool {
		return abs( $height - (int) round( $width * $full_height / $full_width ) ) <= 1;
	}

	/**
	 * @param list<array{url: string, width: int, height: int}> $candidates Sorted by width.
	 * @return array{url: string, width: int, height: int}
	 */
	private function for_display_width( array $candidates, int $display_width ): array {
		foreach ( $candidates as $candidate ) {
			if ( $candidate['width'] >= $display_width ) {
				return $candidate;
			}
		}
		return $candidates[ count( $candidates ) - 1 ];
	}

	/**
	 * @param list<array{url: string, width: int, height: int}> $candidates Sorted by width.
	 * @return array{url: string, width: int, height: int}
	 */
	private function largest_up_to( array $candidates, int $max_width ): array {
		$chosen = $candidates[0];
		foreach ( $candidates as $candidate ) {
			if ( $candidate['width'] <= $max_width ) {
				$chosen = $candidate;
			}
		}
		return $chosen;
	}

	private function is_allowed( string $mime_type ): bool {
		$this->allowed_types ??= AllowedMimeTypes::all();
		return in_array( $mime_type, $this->allowed_types, true );
	}
}
