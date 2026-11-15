<?php
/**
 * The layouts this plugin ships. One line per layout, alphabetical, so parallel layout work merges without conflicts.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Rendering\Layout;

use SWGallery\Core\Application\Service\LayoutRegistry;

final class BuiltInLayouts {

	/** Registers every built-in layout. The public extension point (`swg_register_layouts`) runs after this. */
	public static function register( LayoutRegistry $registry ): void {
		$registry->register( new BubblesLayout() );
		$registry->register( new CarouselLayout() );
		$registry->register( new FocusLayout() );
		$registry->register( new GridLayout() );
		$registry->register( new JustifiedLayout() );
		$registry->register( new MagazineLayout() );
		$registry->register( new MasonryLayout() );
		$registry->register( new MosaicLayout() );
		$registry->register( new ScatteredLayout() );
	}
}
