<?php
/**
 * Lazy editor and delete permission shared by gallery removal and restore.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Mcp\Ability;

use SWGallery\Core\Exception\AccessDeniedException;
use SWGallery\WordPress\Gallery\GalleryEditor;

trait GalleryDeletionPermissionTrait {

	use WriteAbilityTrait;

	/**
	 * @param \Closure $editor Lazy provider of the {@see GalleryEditor}.
	 * @phpstan-param \Closure():GalleryEditor $editor
	 */
	public function __construct( private readonly \Closure $editor ) {
	}

	/**
	 * @param array<string, mixed> $input Validated input.
	 * @throws AccessDeniedException When the user lacks delete permission.
	 */
	public function permission( array $input ): true {
		if ( ! current_user_can( 'delete_posts' ) ) {
			throw new AccessDeniedException();
		}
		( $this->editor )()->deletable( $this->actor(), self::int( $input, 'id' ) );
		return true;
	}
}
