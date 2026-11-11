<?php
/**
 * One stored gallery item together with its settings map.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Rest;

use SWGallery\Core\Domain\Gallery\GalleryItem;

/**
 * Read model of the item routes: the core item (ids, position, overrides) plus the per-item layout settings, which the
 * core repository contract only offers one item at a time.
 */
final class ItemRow {

	/**
	 * @param GalleryItem          $item     The item.
	 * @param array<string, mixed> $settings Per-item layout settings (empty when none are stored).
	 */
	public function __construct(
		public readonly GalleryItem $item,
		public readonly array $settings
	) {
	}
}
