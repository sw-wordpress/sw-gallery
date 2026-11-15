<?php
/**
 * The `magazine` layout: rows like a photo book, each row a CSS grid chosen by the core rule engine.
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
use SWGallery\Core\Domain\Layout\Magazine\MagazinePlanner;
use SWGallery\Core\Domain\Layout\Plan\PlanItem;
use SWGallery\Core\Domain\Layout\Plan\PlanSeed;
use SWGallery\Core\Domain\Layout\RenderItem;
use SWGallery\WordPress\Rendering\View\ImageMarkup;
use SWGallery\WordPress\Rendering\View\LightboxLabels;
use SWGallery\WordPress\Rendering\View\ResponsiveSetting;

/**
 * Photo book rows (L-12). The core {@see MagazinePlanner} walks the items in stored order and picks a row template per
 * group of 1 to 5 consecutive pictures (seed = gallery id + stored revision, so the pattern is stable per revision).
 * Markup: one outer `ul.swg-items` whose `li.swg-row.swg-tpl-<id>` are the rows (`--swg-row` = row index), each holding
 * an inner `ul.swg-row-items` with the usual `li.swg-item` (`--swg-slot` = grid area name `a`..`e`). Nested lists keep
 * the list semantics valid; the lightbox only needs `a.swg-link` inside the container, found in document order = stored
 * order. The grid per template lives in `assets/frontend/src/magazine.css`; below 600 px container width every row is
 * a plain two column stack. No JavaScript is involved.
 */
final class MagazineLayout implements GalleryLayoutInterface, ImageHintsProviderInterface {

	public const ID = 'magazine';

	public const DEFAULT_GAP        = 8;
	public const MAX_GAP            = 64;
	public const DEFAULT_RADIUS     = 2;
	public const MAX_RADIUS         = 64;
	public const DEFAULT_ROW_HEIGHT = 320;
	public const MIN_ROW_HEIGHT     = 160;
	public const MAX_ROW_HEIGHT     = 640;

	private const RHYTHMS = array(
		MagazinePlanner::RHYTHM_BALANCED,
		MagazinePlanner::RHYTHM_CALM,
		MagazinePlanner::RHYTHM_BOLD,
	);

	/** Largest tile: a full width plate. The thumbnail is chosen for the widest container we style (1200 px). */
	private const DISPLAY_WIDTH = 1200;

	/** The first row holds up to five pictures. */
	private const FIRST_ROW = 4;

	public function getId(): string {
		return self::ID;
	}

	public function getLabel(): string {
		return 'Magazine';
	}

	public function getSettingsSchema(): array {
		return array(
			'type'            => 'object',
			'properties'      => array(
				'rowHeight' => array(
					'title'        => __( 'Row height (px)', 'sw-gallery' ),
					'description'  => __( 'Height of a row of three pictures in a container 1200 px wide; other rows are higher or lower in proportion, and all rows scale with the width.', 'sw-gallery' ),
					'type'         => 'integer',
					'minimum'      => self::MIN_ROW_HEIGHT,
					'maximum'      => self::MAX_ROW_HEIGHT,
					'default'      => self::DEFAULT_ROW_HEIGHT,
					'x-responsive' => true,
				),
				'gap'       => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => self::MAX_GAP,
					'default' => self::DEFAULT_GAP,
				),
				'radius'    => array(
					'title'   => __( 'Corner radius (px)', 'sw-gallery' ),
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => self::MAX_RADIUS,
					'default' => self::DEFAULT_RADIUS,
				),
				'rhythm'    => array(
					'title'         => __( 'Rhythm', 'sw-gallery' ),
					'description'   => __( 'Calm prefers even rows (pairs, three or four equal pictures), bold prefers one large picture with smaller ones and full-width plates. The pictures always keep their order.', 'sw-gallery' ),
					'type'          => 'string',
					'enum'          => self::RHYTHMS,
					'x-enum-titles' => array(
						MagazinePlanner::RHYTHM_BALANCED => __( 'Balanced', 'sw-gallery' ),
						MagazinePlanner::RHYTHM_CALM     => __( 'Calm', 'sw-gallery' ),
						MagazinePlanner::RHYTHM_BOLD     => __( 'Bold', 'sw-gallery' ),
					),
					'default'       => MagazinePlanner::RHYTHM_BALANCED,
				),
			),
			'x-item-settings' => array(
				'type'       => 'object',
				'properties' => array(
					'focal' => array(
						'title'       => __( 'Focal point', 'sw-gallery' ),
						'description' => __( 'Which part of the picture stays visible when the row crops it, in percent from the left and the top (50 / 50 is the centre).', 'sw-gallery' ),
						'type'        => 'object',
						'properties'  => array(
							'x' => array(
								'title'   => __( 'Focal point, horizontal (%)', 'sw-gallery' ),
								'type'    => 'number',
								'minimum' => 0,
								'maximum' => 100,
								'default' => 50,
							),
							'y' => array(
								'title'   => __( 'Focal point, vertical (%)', 'sw-gallery' ),
								'type'    => 'number',
								'minimum' => 0,
								'maximum' => 100,
								'default' => 50,
							),
						),
						'default'     => array(
							'x' => 50,
							'y' => 50,
						),
					),
				),
			),
		);
	}

	public function render( LayoutRenderRequest $request ): string {
		if ( array() === $request->items ) {
			return '';
		}
		$values = $request->settings->values;
		$rhythm = isset( $values['rhythm'] ) && is_string( $values['rhythm'] ) && in_array( $values['rhythm'], self::RHYTHMS, true ) ? $values['rhythm'] : MagazinePlanner::RHYTHM_BALANCED;
		$plan   = ( new MagazinePlanner() )->plan(
			array_map( array( self::class, 'plan_item' ), $request->items ),
			PlanSeed::from( $request->galleryId, $request->revision ),
			$rhythm
		);
		$rows   = '';
		foreach ( $plan->rows as $index => $row ) {
			$items = '';
			foreach ( array_slice( $request->items, $row->start, $row->count ) as $slot => $item ) {
				$items .= ImageMarkup::lightbox_item( $item, self::item_style( $item, $row->template->slots[ $slot ] ) );
			}
			$rows .= sprintf(
				'<li class="swg-row swg-tpl-%1$s" style="--swg-row:%2$d"><ul class="swg-row-items" role="list">%3$s</ul></li>',
				esc_attr( $row->template->id ),
				$index,
				$items
			);
		}
		$css = ResponsiveSetting::declarations( $values['rowHeight'] ?? null, '--swg-mag-h', '', self::DEFAULT_ROW_HEIGHT, self::MIN_ROW_HEIGHT, self::MAX_ROW_HEIGHT )
			. sprintf( '--swg-gap:%dpx;', ResponsiveSetting::integer( $values['gap'] ?? null, self::DEFAULT_GAP, 0, self::MAX_GAP ) )
			. sprintf( '--swg-thumb-radius:%dpx', ResponsiveSetting::integer( $values['radius'] ?? null, self::DEFAULT_RADIUS, 0, self::MAX_RADIUS ) );
		return sprintf(
			'<div class="swg-gallery swg-layout-magazine" data-swg-gallery data-swg-instance="%1$s" data-swg-labels="%2$s" style="%3$s"><ul class="swg-items" role="list">%4$s</ul></div>',
			esc_attr( $request->instanceId ),
			LightboxLabels::attribute(),
			esc_attr( $css ),
			$rows
		);
	}

	/**
	 * Tiles differ a lot (a full width plate to a quarter-width portrait) and one `sizes` value serves every picture, so
	 * the hints use the largest tile, a full width plate: small tiles load a larger file than they need. That keeps the
	 * big tiles sharp, which is what a photo book is about; the srcset still lets narrow viewports pick small files.
	 */
	public function imageHints( GallerySettings $settings ): ImageHints {
		return new ImageHints(
			self::DISPLAY_WIDTH,
			'(min-width: 1200px) 1200px, 100vw',
			self::FIRST_ROW
		);
	}

	private static function plan_item( RenderItem $item ): PlanItem {
		return PlanItem::fromSize( $item->thumbnail->width, $item->thumbnail->height );
	}

	/**
	 * Inline custom properties of one item: its grid area (a letter of the template catalogue) and, off-centre only, the
	 * focal point as a bounded number.
	 *
	 * @param RenderItem $item The item.
	 * @param string     $slot Area name from the core catalogue (`a`..`e`).
	 */
	private static function item_style( RenderItem $item, string $slot ): string {
		$css   = '--swg-slot:' . $slot;
		$focal = $item->settings['focal'] ?? null;
		if ( is_array( $focal ) ) {
			$x = self::percent( $focal['x'] ?? null );
			$y = self::percent( $focal['y'] ?? null );
			if ( 50.0 !== $x || 50.0 !== $y ) {
				$css .= sprintf( ';--swg-fx:%s%%;--swg-fy:%s%%', self::number( $x ), self::number( $y ) );
			}
		}
		return $css;
	}

	/** A focal coordinate: finite number clamped to 0..100, else the centre. */
	private static function percent( mixed $value ): float {
		return ( is_int( $value ) || is_float( $value ) ) && is_finite( (float) $value ) ? max( 0.0, min( 100.0, (float) $value ) ) : 50.0;
	}

	/** Locale independent decimal with at most three decimals, no trailing zeros. */
	private static function number( float $value ): string {
		$text = rtrim( rtrim( sprintf( '%.3F', $value ), '0' ), '.' );
		return '' === $text || '-0' === $text ? '0' : $text;
	}
}
