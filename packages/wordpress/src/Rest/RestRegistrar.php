<?php
/**
 * Registers the swg/v1 REST routes.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Rest;

use SWGallery\WordPress\Bootstrap\RegistrarInterface;
use SWGallery\WordPress\Infrastructure\Services;

/**
 * The only REST class that reads the service holder; controllers get their dependencies through constructors.
 * 1.0 has no unauthenticated route (decision D5): every controller answers anonymous requests with 401.
 */
final class RestRegistrar implements RegistrarInterface {

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$errors = new ErrorMapper( Services::logger() );
		foreach ( $this->controllers( $errors ) as $controller ) {
			$controller->register_routes();
		}
	}

	/**
	 * @param ErrorMapper $errors Shared error mapper.
	 * @return list<AbstractController>
	 */
	private function controllers( ErrorMapper $errors ): array {
		return array(
			new GalleryController(
				$errors,
				Services::gallery_service(),
				Services::revisions(),
				Services::relations(),
				Services::access(),
				new GalleryPresenter( Services::access() ),
				Services::gallery_editor(),
				Services::gallery_lister()
			),
			new ItemController(
				$errors,
				Services::item_service(),
				Services::galleries(),
				Services::items(),
				Services::revisions(),
				Services::access(),
				new ItemPresenter( Services::media(), Services::access() ),
				Services::item_editor()
			),
			new LayoutController( $errors, Services::layouts(), new LayoutPresenter() ),
			new MediaController(
				$errors,
				new MediaSearch(),
				new MediaUploader(),
				new MediaPresenter(),
				Services::media_lister()
			),
			new PreviewController( $errors, Services::galleries(), Services::access(), Services::layouts(), Services::preview_renderer( ... ), Services::frontend_assets() ),
			new SettingsController( $errors, Services::settings() ),
			new StatusController( $errors, new StatusReport( Services::logger(), Services::relations(), SWGALLERY_DIR . 'assets/admin' ) ),
		);
	}
}
