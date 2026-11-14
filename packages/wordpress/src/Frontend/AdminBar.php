<?php
/**
 * Admin bar entries "Edit gallery" for galleries rendered on the current frontend request.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- core value objects use camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Frontend;

use SWGallery\Core\Contract\GalleryRepositoryInterface;
use SWGallery\Core\Domain\Access\Actor;
use SWGallery\WordPress\Admin\AdminRegistrar;
use SWGallery\WordPress\Security\WpGalleryAccessPolicy;

/**
 * One node per distinct rendered gallery (at most five), only on the frontend and only for galleries the user may edit
 * (the shared access policy decides). The admin bar is built in the footer, after the content has rendered.
 */
final class AdminBar {

	public const MAX_NODES = 5;

	public function __construct(
		private readonly AssetCollector $collector,
		private readonly GalleryRepositoryInterface $galleries,
		private readonly WpGalleryAccessPolicy $access
	) {
	}

	/**
	 * @param \WP_Admin_Bar $bar The admin bar.
	 */
	public function add_nodes( \WP_Admin_Bar $bar ): void {
		if ( is_admin() || ! is_user_logged_in() ) {
			return;
		}
		$actor    = new Actor( max( 0, get_current_user_id() ) );
		$editable = array();
		foreach ( $this->collector->gallery_ids() as $id ) {
			$gallery = $this->galleries->find( $id );
			if ( null !== $gallery && $this->access->canEdit( $actor, $gallery ) ) {
				$editable[ $id ] = $gallery->title;
			}
			if ( count( $editable ) >= self::MAX_NODES ) {
				break;
			}
		}
		foreach ( $editable as $id => $title ) {
			$label = 1 === count( $editable )
				? __( 'Edit gallery', 'sw-gallery' )
				/* translators: %s: gallery title */
				: sprintf( __( 'Edit gallery: %s', 'sw-gallery' ), wp_html_excerpt( $title, 40, '…' ) );
			$bar->add_node(
				array(
					'id'    => 'swg-edit-gallery-' . $id,
					'title' => esc_html( $label ),
					'href'  => AdminRegistrar::editor_url( $id ),
				)
			);
		}
	}
}
