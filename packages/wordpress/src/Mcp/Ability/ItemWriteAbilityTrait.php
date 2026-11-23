<?php
/**
 * Lazy editors and gallery edit permission shared by item mutations.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Mcp\Ability;

use SWGallery\Core\Exception\AccessDeniedException;
use SWGallery\WordPress\Gallery\GalleryEditor;
use SWGallery\WordPress\Gallery\ItemEditor;

trait ItemWriteAbilityTrait {

	use WriteAbilityTrait;

	/**
	 * @param \Closure $items  Lazy provider of the {@see ItemEditor}.
	 * @param \Closure $editor Lazy provider of the {@see GalleryEditor}.
	 * @phpstan-param \Closure():ItemEditor $items
	 * @phpstan-param \Closure():GalleryEditor $editor
	 */
	public function __construct( private readonly \Closure $items, private readonly \Closure $editor ) {
	}

	/**
	 * @param array<string, mixed> $input Validated input.
	 * @throws AccessDeniedException When the user lacks edit permission.
	 */
	public function permission( array $input ): true {
		if ( ! current_user_can( 'edit_posts' ) ) {
			throw new AccessDeniedException();
		}
		( $this->editor )()->editable( $this->actor(), self::int( $input, 'id' ) );
		return true;
	}
}
