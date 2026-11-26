<?php
/**
 * Render item source that adds the per-item layout settings to the items of one page.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Rendering;

use SWGallery\Core\Application\Render\RenderItemPage;
use SWGallery\Core\Contract\RenderItemSourceInterface;
use SWGallery\Core\Domain\Gallery\GalleryItem;
use SWGallery\Core\Domain\Gallery\SortMode;
use SWGallery\WordPress\Database\Schema;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- core value objects use camelCase properties (CODE-STYLE 3.1).

/**
 * Decorates the render item source: one extra query per page (`id IN (...)`, independent of the page size) reads the
 * stored `settings` JSON of exactly the items the inner source returned, so layouts with `x-item-settings` (emphasis,
 * focal point) can read them. Layouts clamp what they read; nothing here validates against a layout schema. Malformed
 * JSON counts as no settings.
 */
final class ItemSettingsRenderSource implements RenderItemSourceInterface {

	public function __construct( private readonly RenderItemSourceInterface $inner ) {
	}

	public function findRenderPage( int $galleryId, SortMode $sortMode, int $offset, int $limit, bool $publicOnly = false ): RenderItemPage {
		$page = $this->inner->findRenderPage( $galleryId, $sortMode, $offset, $limit, $publicOnly );
		if ( array() === $page->items ) {
			return $page;
		}
		$settings = $this->settings_by_id( $galleryId, array_map( static fn( GalleryItem $item ): int => $item->id, $page->items ) );
		$items    = array();
		foreach ( $page->items as $item ) {
			$items[] = new GalleryItem( $item->id, $item->galleryId, $item->attachmentId, $item->position, $item->overrides, $settings[ $item->id ] ?? array() );
		}
		return new RenderItemPage( $items, $page->total );
	}

	/**
	 * @param int   $gallery_id Gallery id (the rows must belong to it).
	 * @param int[] $ids        Item ids of the page.
	 * @return array<int, array<string, mixed>>
	 */
	private function settings_by_id( int $gallery_id, array $ids ): array {
		global $wpdb;
		$table        = Schema::items_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the placeholders are generated, the arguments are merged below.
				"SELECT id, settings FROM {$table} WHERE gallery_id = %d AND id IN ({$placeholders}) AND settings IS NOT NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table name from prefix, placeholders generated.
				array_merge( array( $gallery_id ), $ids )
			),
			ARRAY_A
		);
		$result       = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) || ! is_string( $row['settings'] ?? null ) || '' === $row['settings'] ) {
				continue;
			}
			$decoded = json_decode( $row['settings'], true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			$map = array();
			foreach ( $decoded as $key => $value ) {
				$map[ (string) $key ] = $value;
			}
			$result[ (int) $row['id'] ] = $map;
		}
		return $result;
	}
}
