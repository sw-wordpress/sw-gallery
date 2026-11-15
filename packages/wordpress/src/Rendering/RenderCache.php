<?php
/**
 * Fragment cache of rendered gallery markup (object cache group `swg_render`).
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- core value objects use camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Rendering;

use SWGallery\Core\Application\Render\EntryPoint;
use SWGallery\Core\Application\Render\RenderAsset;
use SWGallery\Core\Application\Render\RenderContext;
use SWGallery\Core\Application\Render\RenderResult;
use SWGallery\Core\Domain\Access\RenderAccess;
use SWGallery\Core\Domain\Gallery\Gallery;
use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\WordPress\Repository\PostMetaRevisionStore;
use SWGallery\WordPress\Settings\PluginSettings;

/**
 * Only output every visitor would receive is cached (the `public` visibility class): a published gallery without
 * password, rendered for a user who cannot edit it, outside the admin preview. Everything else (drafts, private
 * galleries, password forms, editors with their diagnostics, previews) is rendered fresh every time and never stored.
 *
 * The key holds everything the markup depends on: gallery revision (every persisted gallery or item change bumps
 * it), effective plugin settings, entry point, page and page size, embed overrides, loading flags, plugin version,
 * locale, text direction, site URL and the {@see MediaEpoch}. The request URL is deliberately not part of it:
 * pagination links are built per request after the cache. The per-request instance id is cached as a placeholder
 * inside the `data-swg-instance` attribute and filled on every hit, so ids stay unique per request and a hit equals
 * a fresh render byte for byte.
 *
 * Works with and without a persistent object cache; without one the cache lives for one request.
 */
final class RenderCache {

	public const GROUP = 'swg_render';

	/** Entries expire after twelve hours even if nothing invalidates them. */
	public const TTL = 12 * HOUR_IN_SECONDS;

	/** Bump to drop all entries when the stored shape changes. */
	private const FORMAT = 1;

	private const PLACEHOLDER = 'swg-instance-placeholder';

	public function __construct(
		private readonly PluginSettings $settings,
		private readonly PostMetaRevisionStore $revisions,
		private readonly MediaEpoch $epoch
	) {
	}

	/**
	 * Whether the output for this request belongs to the public visibility class.
	 *
	 * @param Gallery    $gallery   The gallery (fresh from the repository, so status and password are current).
	 * @param EntryPoint $entry     Calling surface.
	 * @param bool       $can_edit  Whether the current user can edit the gallery (they would see diagnostics).
	 */
	public function is_cacheable( Gallery $gallery, EntryPoint $entry, bool $can_edit ): bool {
		return GalleryStatus::Publish === $gallery->status
			&& ! $gallery->passwordProtected
			&& EntryPoint::Preview !== $entry
			&& ! $can_edit;
	}

	/**
	 * @param RenderContext $context Render context of the request.
	 * @param int           $page    Page the entry is stored for (the clamped page of a result).
	 */
	public function key( RenderContext $context, int $page ): string {
		$settings = $this->settings->get();
		unset( $settings['deleteDataOnUninstall'] );
		$parts = array(
			'format'    => self::FORMAT,
			'gallery'   => $context->galleryId,
			'revision'  => $this->revisions->current( $context->galleryId )->value,
			'class'     => 'public',
			'entry'     => $context->entryPoint->value,
			'page'      => $page,
			'perPage'   => $context->perPage,
			'layout'    => $context->layoutOverride,
			'overrides' => self::normalize( $context->settingsOverrides ),
			'eager'     => $context->eagerLoading,
			'priority'  => $context->highPriority,
			'settings'  => $settings,
			'version'   => defined( 'SWGALLERY_VERSION' ) ? SWGALLERY_VERSION : '0',
			'locale'    => determine_locale(),
			'rtl'       => is_rtl(),
			'site'      => home_url(),
			'media'     => $this->epoch->current(),
		);
		return 'g' . $context->galleryId . '-' . hash( 'sha256', (string) wp_json_encode( $parts ) );
	}

	/**
	 * @param string $key         Cache key from {@see key()}.
	 * @param string $instance_id Instance id of this request's embed.
	 */
	public function lookup( string $key, string $instance_id ): ?RenderResult {
		$data = wp_cache_get( $key, self::GROUP );
		if ( ! is_array( $data ) || ! isset( $data['html'], $data['page'], $data['totalPages'], $data['totalItems'], $data['renderedItems'] ) ) {
			return null;
		}
		if ( ! is_string( $data['html'] ) || ! is_int( $data['page'] ) || ! is_int( $data['totalPages'] ) || ! is_int( $data['totalItems'] ) || ! is_int( $data['renderedItems'] ) ) {
			return null;
		}
		$assets = array();
		foreach ( is_array( $data['assets'] ?? null ) ? $data['assets'] : array() as $value ) {
			$asset = is_string( $value ) ? RenderAsset::tryFrom( $value ) : null;
			if ( null !== $asset ) {
				$assets[] = $asset;
			}
		}
		return new RenderResult(
			self::swap_instance( $data['html'], self::PLACEHOLDER, $instance_id ),
			RenderAccess::Render,
			array(),
			$assets,
			$data['page'],
			$data['totalPages'],
			$data['totalItems'],
			$data['renderedItems'],
			is_string( $data['layoutId'] ?? null ) ? $data['layoutId'] : null
		);
	}

	/**
	 * Stores a result when it is safe to share: access `Render` and no diagnostics.
	 *
	 * @param string       $key         Cache key from {@see key()}.
	 * @param RenderResult $result      Fresh result.
	 * @param string       $instance_id Instance id used in the result's html.
	 */
	public function store( string $key, RenderResult $result, string $instance_id ): void {
		if ( RenderAccess::Render !== $result->access || array() !== $result->diagnostics ) {
			return;
		}
		wp_cache_set(
			$key,
			array(
				'html'          => self::swap_instance( $result->html, $instance_id, self::PLACEHOLDER ),
				'assets'        => array_map( static fn ( RenderAsset $asset ): string => $asset->value, $result->assets ),
				'page'          => $result->page,
				'totalPages'    => $result->totalPages,
				'totalItems'    => $result->totalItems,
				'renderedItems' => $result->renderedItems,
				'layoutId'      => $result->layoutId,
			),
			self::GROUP,
			self::TTL
		);
	}

	private static function instance_attribute( string $instance_id ): string {
		return 'data-swg-instance="' . $instance_id . '"';
	}

	/**
	 * Replaces the instance id in the places a layout writes it: the instance attribute and element ids or in-page
	 * links derived from it (`id="<instance>-…"`, `href="#<instance>-…"`, `url(#<instance>-…)`; e.g. the `focus` layout's `:target` anchors).
	 *
	 * @param string $html Markup.
	 * @param string $from Instance id (or placeholder) to replace.
	 * @param string $to   Replacement.
	 */
	private static function swap_instance( string $html, string $from, string $to ): string {
		return str_replace(
			array( self::instance_attribute( $from ), 'id="' . $from . '-', 'href="#' . $from . '-', 'url(#' . $from . '-' ),
			array( self::instance_attribute( $to ), 'id="' . $to . '-', 'href="#' . $to . '-', 'url(#' . $to . '-' ),
			$html
		);
	}

	/**
	 * Order-independent form of untrusted override values.
	 *
	 * @param array<array-key, mixed> $values Values.
	 * @return array<array-key, mixed>
	 */
	private static function normalize( array $values ): array {
		ksort( $values );
		foreach ( $values as $key => $value ) {
			if ( is_array( $value ) ) {
				$values[ $key ] = self::normalize( $value );
			}
		}
		return $values;
	}
}
