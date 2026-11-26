<?php
/**
 * WordPress entry of the render pipeline: the one place the shortcode, block, PHP API, preview and feed surfaces call.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- core value objects use camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Rendering;

use SWGallery\Core\Application\Render\EntryPoint;
use SWGallery\Core\Application\Render\RenderContext;
use SWGallery\Core\Application\Render\RenderGallery;
use SWGallery\Core\Application\Render\RenderResult;
use SWGallery\Core\Contract\GalleryRepositoryInterface;
use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Access\RenderAccess;
use SWGallery\Core\Domain\Gallery\Gallery;
use SWGallery\Core\Domain\Gallery\GallerySettings;
use SWGallery\Core\Domain\Gallery\HoverAnimation;
use SWGallery\Core\Domain\Gallery\HoverEffect;
use SWGallery\WordPress\Rendering\View\PaginationMarkup;
use SWGallery\WordPress\Security\WpGalleryAccessPolicy;
use SWGallery\WordPress\Settings\PluginSettings;

/**
 * Builds the {@see RenderContext} from the current request (user, password cookie, instance numbering), runs the pipeline
 * and turns its outcome into final markup: the core password form for protected galleries, editor-only diagnostics
 * before the gallery. It enqueues nothing; callers read {@see RenderResult::$assets}.
 *
 * Options (all optional, all untrusted and sanitised here): `page`, `per_page`, `layout`, `settings` (array),
 * `eager` (bool, default true). Without `page` the page comes from the request argument `swg_page_<galleryId>`
 * (shortcode, block and PHP API only; previews and feeds always start at page 1). Without `per_page` the gallery's own page size, else the plugin
 * setting `itemsPerPage` applies.
 *
 * Output paths: published, password-free output for users who cannot edit the gallery is served from the fragment
 * cache ({@see RenderCache}); everything else is rendered fresh. Paging controls are appended after the cache,
 * because the links depend on the request URL.
 */
final class GalleryRenderer {

	/** @var array<int, int> Embeds rendered so far in this request, per gallery id. */
	private array $instances = array();

	private int $embeds = 0;

	public function __construct(
		private readonly RenderGallery $pipeline,
		private readonly GalleryRepositoryInterface $galleries,
		private readonly WpGalleryAccessPolicy $access,
		private readonly PluginSettings $settings,
		private readonly RenderCache $cache
	) {
	}

	/**
	 * @param int                  $gallery_id Gallery id.
	 * @param EntryPoint           $entry      The calling surface.
	 * @param array<string, mixed> $options    See the class description.
	 */
	public function render( int $gallery_id, EntryPoint $entry, array $options = array() ): RenderResult {
		if ( $gallery_id <= 0 ) {
			return new RenderResult( '', RenderAccess::Nothing );
		}
		$gallery = $this->galleries->find( $gallery_id );
		$context = $this->context( $gallery_id, $entry, $options, $gallery );
		$result  = $this->cached_or_fresh( $context, $gallery );

		if ( ! $entry->isInteractive() ) {
			return $result->needsPasswordForm() ? $result->withHtml( '' ) : $this->with_paging( $result, $context, $gallery );
		}
		if ( $result->needsPasswordForm() ) {
			return $result->withHtml( get_the_password_form( $gallery_id ) );
		}
		$result = $this->with_paging( $result, $context, $gallery );
		if ( array() === $result->diagnostics ) {
			return $result;
		}
		return $result->withHtml( DiagnosticMarkup::render( $result->diagnostics ) . $result->html );
	}

	/**
	 * Serves public output from the fragment cache and fills it on a miss; everything else bypasses the cache.
	 *
	 * @param RenderContext $context Render context.
	 * @param Gallery|null  $gallery The gallery as loaded for this request.
	 */
	private function cached_or_fresh( RenderContext $context, ?Gallery $gallery ): RenderResult {
		if ( null === $gallery || ! $this->cache->is_cacheable( $gallery, $context->entryPoint, $this->access->canEdit( $context->actor, $gallery ) ) ) {
			return $this->pipeline->render( $context );
		}
		$cache_base = $this->cache->key_base( $context );
		$key        = RenderCache::page_key( $cache_base, $context->page );
		$hit        = $this->cache->lookup( $key, $context->instanceId );
		if ( null !== $hit ) {
			return $hit;
		}
		$result = $this->pipeline->render( $context );
		// A page past the end is stored under the clamped page only, so arbitrary page numbers cannot fill the cache.
		$this->cache->store( RenderCache::page_key( $cache_base, $result->page ), $result, $context->instanceId );
		return $result;
	}

	/**
	 * Appends the paging controls: links for the frontend surfaces, a plain "page n of m" text for feeds and previews.
	 *
	 * @param RenderResult  $result  Result of the pipeline.
	 * @param RenderContext $context Render context.
	 * @param Gallery|null  $gallery The gallery as loaded for this request.
	 */
	private function with_paging( RenderResult $result, RenderContext $context, ?Gallery $gallery ): RenderResult {
		if ( RenderAccess::Render !== $result->access || '' === $result->html || $result->totalPages < 2 ) {
			return $result;
		}
		$links  = in_array( $context->entryPoint, array( EntryPoint::Shortcode, EntryPoint::Block, EntryPoint::PhpApi ), true );
		$paging = $links
			? PaginationMarkup::nav( $context->galleryId, null === $gallery ? '' : $gallery->title, $result->page, $result->totalPages, PaginationMarkup::current_url() )
			: PaginationMarkup::status( $result->page, $result->totalPages );
		return $result->withHtml( $result->html . $paging );
	}

	/**
	 * Convenience for callers that only need the markup.
	 *
	 * @param int                  $gallery_id Gallery id.
	 * @param EntryPoint           $entry      The calling surface.
	 * @param array<string, mixed> $options    See the class description.
	 */
	public function html( int $gallery_id, EntryPoint $entry, array $options = array() ): string {
		return $this->render( $gallery_id, $entry, $options )->html;
	}

	/**
	 * @param int                  $gallery_id Gallery id.
	 * @param EntryPoint           $entry      The calling surface.
	 * @param array<string, mixed> $options    Untrusted embed options.
	 * @param Gallery|null         $gallery    The gallery as loaded for this request.
	 */
	private function context( int $gallery_id, EntryPoint $entry, array $options, ?Gallery $gallery ): RenderContext {
		$user     = get_current_user_id();
		$unlocked = null === $gallery || $this->access->isPasswordUnlocked( $gallery );

		$number                         = ( $this->instances[ $gallery_id ] ?? 0 ) + 1;
		$this->instances[ $gallery_id ] = $number;
		$first_embed                    = 0 === $this->embeds;
		++$this->embeds;

		$layout   = isset( $options['layout'] ) && is_string( $options['layout'] ) && 1 === preg_match( GallerySettings::LAYOUT_ID_PATTERN, $options['layout'] ) ? $options['layout'] : null;
		$settings = array();
		if ( isset( $options['settings'] ) && is_array( $options['settings'] ) ) {
			foreach ( $options['settings'] as $key => $value ) {
				$settings[ (string) $key ] = $value;
			}
		}

		return new RenderContext(
			$gallery_id,
			$entry,
			new Actor( max( 0, $user ) ),
			sprintf( 'swg-%d-%d', $gallery_id, $number ),
			max( 1, min( PaginationMarkup::MAX_PAGE, $this->int_option( $options, 'page', $this->request_page( $gallery_id, $entry ) ) ) ),
			max( PluginSettings::MIN_ITEMS_PER_PAGE, min( PluginSettings::MAX_ITEMS_PER_PAGE, $this->int_option( $options, 'per_page', $gallery?->settings->perPage ?? $this->settings->get()['itemsPerPage'] ) ) ),
			$layout,
			$settings,
			$unlocked,
			! isset( $options['eager'] ) || (bool) $options['eager'],
			$first_embed,
			$this->effective_hover( $gallery )
		);
	}

	/**
	 * The gallery's own hover texts, else the site-wide default; null when nothing is shown (a canonical "off", so the
	 * cache key is the same however it was switched off).
	 *
	 * @param Gallery|null $gallery The gallery as loaded for this request.
	 */
	private function effective_hover( ?Gallery $gallery ): ?HoverEffect {
		$site   = $this->settings->get();
		$effect = $gallery?->settings->hover ?? new HoverEffect( $site['hoverTitle'], $site['hoverCaption'], HoverAnimation::tryFrom( $site['hoverAnimation'] ) ?? HoverAnimation::Fade );
		return $effect->isActive() ? $effect : null;
	}

	/** The page requested through the URL; previews and feeds never read the request. */
	private function request_page( int $gallery_id, EntryPoint $entry ): int {
		return EntryPoint::Preview === $entry || ! $entry->isInteractive() ? 1 : PaginationMarkup::requested_page( $gallery_id );
	}

	/**
	 * @param array<string, mixed> $options Untrusted embed options.
	 */
	private function int_option( array $options, string $key, int $fallback ): int {
		$value = $options[ $key ] ?? null;
		return is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ? (int) $value : $fallback;
	}
}
