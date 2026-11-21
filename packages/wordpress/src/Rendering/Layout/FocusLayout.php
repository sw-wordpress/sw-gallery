<?php
/**
 * The `focus` layout (spotlight): one large stage image and a thumbnail strip; works without JavaScript via `:target`.
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
use SWGallery\Core\Domain\Layout\LoadingStrategy;
use SWGallery\Core\Domain\Layout\RenderItem;
use SWGallery\Core\Domain\Layout\ResolvedImage;
use SWGallery\WordPress\Rendering\View\ImageMarkup;
use SWGallery\WordPress\Rendering\View\LightboxLabels;
use SWGallery\WordPress\Rendering\View\ResponsiveSetting;

/**
 * Proposal L-14. Markup: a labelled stage region holding one panel per image (`ul > li > a.swg-link`, each preceded by an empty `span.swg-anchor#<instance>-s<n>`,
 * the only lightbox links, in editor order, so the lightbox lists every image exactly once) and a separate list of
 * thumbnail links (`a.swg-thumb href="#<instance>-s<n>"`, deliberately not `a.swg-link`). Without JavaScript the stage
 * shows the panel whose anchor is `:target` (the first one by default; the anchor is `position: fixed`, so following a thumbnail link does not scroll the page); `assets/frontend/src/focus.ts` (reported as
 * {@see RenderAsset::Focus}) upgrades the thumbnails to instant switching. Element ids derive from the instance id only
 * (unique per embed; the render cache rewrites them like the instance attribute). Stylesheet: `focus.css`.
 */
final class FocusLayout implements GalleryLayoutInterface, ImageHintsProviderInterface, LayoutAssetsProviderInterface {

	public const ID = 'focus';

	public const DEFAULT_THUMB_SIZE = 80;
	public const MIN_THUMB_SIZE     = 40;
	public const MAX_THUMB_SIZE     = 160;
	public const DEFAULT_GAP        = 8;
	public const MAX_GAP            = 64;
	public const DEFAULT_RADIUS     = 4;
	public const MAX_RADIUS         = 64;

	/** Stage ratios; `auto` follows the first image. Values are the CSS ratios. */
	private const RATIOS = array(
		'auto' => '',
		'16:9' => '16/9',
		'3:2'  => '3/2',
		'4:3'  => '4/3',
		'1:1'  => '1/1',
	);

	private const THUMBS = array( 'bottom', 'start' );

	/** Ratio limits of the `auto` stage (extreme panoramas or banners would make a useless stage). */
	private const AUTO_MIN = 0.5;
	private const AUTO_MAX = 2.5;

	/** Container width the stage image and the number of eager thumbnails are derived from, in pixels. */
	private const REFERENCE_WIDTH = 1200;

	public function getId(): string {
		return self::ID;
	}

	public function getLabel(): string {
		return 'Focus';
	}

	public function getSettingsSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'stageRatio' => array(
					'title'         => __( 'Stage ratio', 'sw-gallery' ),
					'description'   => __( 'Images are never cropped on the stage; auto uses the ratio of the first image.', 'sw-gallery' ),
					'type'          => 'string',
					'enum'          => array_keys( self::RATIOS ),
					'x-enum-titles' => array(
						'auto' => __( 'Like the first image', 'sw-gallery' ),
						'16:9' => '16:9',
						'3:2'  => '3:2',
						'4:3'  => '4:3',
						'1:1'  => '1:1',
					),
					'default'       => '3:2',
				),
				'thumbs'     => array(
					'title'         => __( 'Thumbnails', 'sw-gallery' ),
					'description'   => __( 'Narrow containers always show the thumbnails below.', 'sw-gallery' ),
					'type'          => 'string',
					'enum'          => self::THUMBS,
					'x-enum-titles' => array(
						'bottom' => __( 'Below the stage', 'sw-gallery' ),
						'start'  => __( 'Beside the stage', 'sw-gallery' ),
					),
					'default'       => 'bottom',
				),
				'thumbSize'  => array(
					'title'   => __( 'Thumbnail size (px)', 'sw-gallery' ),
					'type'    => 'integer',
					'minimum' => self::MIN_THUMB_SIZE,
					'maximum' => self::MAX_THUMB_SIZE,
					'default' => self::DEFAULT_THUMB_SIZE,
				),
				'gap'        => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => self::MAX_GAP,
					'default' => self::DEFAULT_GAP,
				),
				'radius'     => array(
					'title'   => __( 'Corner radius (px)', 'sw-gallery' ),
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => self::MAX_RADIUS,
					'default' => self::DEFAULT_RADIUS,
				),
			),
		);
	}

	public function renderAssets(): array {
		return array( RenderAsset::Focus );
	}

	public function render( LayoutRenderRequest $request ): string {
		if ( array() === $request->items ) {
			return '';
		}
		$values     = $request->settings->values;
		$thumb_size = ResponsiveSetting::integer( $values['thumbSize'] ?? null, self::DEFAULT_THUMB_SIZE, self::MIN_THUMB_SIZE, self::MAX_THUMB_SIZE );
		$panels     = '';
		$thumbs     = '';
		foreach ( $request->items as $index => $item ) {
			$id      = sprintf( '%s-s%d', $request->instanceId, $index + 1 );
			$panels .= sprintf(
				'<li class="swg-item swg-panel"><span class="swg-anchor" id="%1$s"></span>%2$s%3$s</li>',
				esc_attr( $id ),
				ImageMarkup::lightbox_link( 0 === $index ? $item : self::lazy( $item ) ),
				ImageMarkup::caption( $item )
			);
			$thumbs .= self::thumb( $item, $id, $thumb_size );
		}
		$classes = 'swg-gallery swg-layout-focus';
		if ( 'start' === ( $values['thumbs'] ?? 'bottom' ) ) {
			$classes .= ' swg-thumbs-start';
		}
		$css = sprintf( '--swg-stage-ratio:%s;', $this->stage_ratio( $values['stageRatio'] ?? null, $request->items[0] ) )
			. sprintf( '--swg-thumb-size:%dpx;', $thumb_size )
			. sprintf( '--swg-gap:%dpx;', ResponsiveSetting::integer( $values['gap'] ?? null, self::DEFAULT_GAP, 0, self::MAX_GAP ) )
			. sprintf( '--swg-thumb-radius:%dpx', ResponsiveSetting::integer( $values['radius'] ?? null, self::DEFAULT_RADIUS, 0, self::MAX_RADIUS ) );
		return sprintf(
			'<div class="%1$s" data-swg-gallery data-swg-instance="%2$s" data-swg-labels="%3$s" style="%4$s"><div class="swg-focus__body"><div class="swg-stage" role="region" aria-label="%5$s"><ul class="swg-items" role="list">%6$s</ul></div>%7$s</div></div>',
			esc_attr( $classes ),
			esc_attr( $request->instanceId ),
			LightboxLabels::attribute(
				array(
					'stage'      => __( 'Selected image', 'sw-gallery' ),
					'thumbnails' => __( 'Thumbnails', 'sw-gallery' ),
				)
			),
			esc_attr( $css ),
			esc_attr( __( 'Selected image', 'sw-gallery' ) ),
			$panels,
			count( $request->items ) > 1 ? sprintf( '<ul class="swg-thumbs" role="list" aria-label="%s">%s</ul>', esc_attr( __( 'Thumbnails', 'sw-gallery' ) ), $thumbs ) : ''
		);
	}

	/**
	 * The stage image is as wide as the container (estimated at 1200 px, capped by the viewport); only the first image
	 * is eager. The thumbnails reuse the same candidates with their own, tiny `sizes`.
	 */
	public function imageHints( GallerySettings $settings ): ImageHints {
		$size = ResponsiveSetting::integer( $settings->values['thumbSize'] ?? null, self::DEFAULT_THUMB_SIZE, self::MIN_THUMB_SIZE, self::MAX_THUMB_SIZE );
		$gap  = ResponsiveSetting::integer( $settings->values['gap'] ?? null, self::DEFAULT_GAP, 0, self::MAX_GAP );
		return new ImageHints(
			self::REFERENCE_WIDTH,
			sprintf( 'min(100vw, %dpx)', self::REFERENCE_WIDTH ),
			max( 1, min( ImageHints::MAX_FIRST_ROW, (int) floor( self::REFERENCE_WIDTH / ( $size + $gap ) ) ) )
		);
	}

	/** The same item with lazy loading (only the first stage image may be eager). */
	private static function lazy( RenderItem $item ): RenderItem {
		return new RenderItem( $item->itemId, $item->attachmentId, $item->title, $item->caption, $item->alt, $item->thumbnail, $item->full, LoadingStrategy::Lazy, $item->position, $item->total, array(), $item->hover );
	}

	/** `<li><a class="swg-thumb" href="#id">` with a small image; eager only for the first row (never high priority). */
	private static function thumb( RenderItem $item, string $id, int $size ): string {
		$image   = $item->thumbnail;
		$small   = new ResolvedImage( $image->url, $image->width, $image->height, $image->srcset, null === $image->srcset ? null : sprintf( '%dpx', $size ) );
		$loading = LoadingStrategy::Lazy === $item->loading ? LoadingStrategy::Lazy : LoadingStrategy::Eager;
		$label   = ImageMarkup::link_label( $item );
		return sprintf(
			'<li class="swg-thumb-item"><a class="swg-thumb" href="#%1$s"%2$s>%3$s</a></li>',
			esc_attr( $id ),
			'' === $label ? '' : sprintf( ' aria-label="%s"', esc_attr( $label ) ),
			ImageMarkup::image( $small, $item->alt, $loading )
		);
	}

	/**
	 * CSS ratio of the stage: a fixed one from the allowlist, or the first image's ratio (clamped, locale independent).
	 *
	 * @param mixed      $setting Raw setting.
	 * @param RenderItem $first   First item.
	 */
	private function stage_ratio( mixed $setting, RenderItem $first ): string {
		$key = is_string( $setting ) && isset( self::RATIOS[ $setting ] ) ? $setting : '3:2';
		if ( 'auto' !== $key ) {
			return self::RATIOS[ $key ];
		}
		$ratio = $first->thumbnail->width <= 0 || $first->thumbnail->height <= 0 ? 1.5 : $first->thumbnail->width / $first->thumbnail->height;
		return rtrim( rtrim( sprintf( '%.4F', max( self::AUTO_MIN, min( self::AUTO_MAX, $ratio ) ) ), '0' ), '.' );
	}
}
