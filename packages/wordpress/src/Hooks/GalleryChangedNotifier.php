<?php
/**
 * Fires the public `swg_gallery_changed` action (isolated).
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Hooks;

use SWGallery\Core\Contract\LoggerInterface;
use SWGallery\Core\Logging\LogLevel;
use SWGallery\WordPress\Infrastructure\Services;

/**
 * One place for the action so every source (core events, attachment deletion, repair) behaves the same: payload is
 * the gallery id only, a throwing callback is caught, logged (`swg_listener_failed`) and ignored.
 */
final class GalleryChangedNotifier {

	public function __construct( private readonly ?LoggerInterface $logger = null ) {
	}

	/**
	 * @param int         $gallery_id Gallery whose persisted state or revision changed.
	 * @param string|null $source     Event name or source label for the log entry on failure.
	 */
	public function notify( int $gallery_id, ?string $source = null ): void {
		if ( $gallery_id <= 0 ) {
			return;
		}
		try {
			do_action( 'swg_gallery_changed', $gallery_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- "swg_" is the documented plugin prefix (see phpcs-wordpress.xml.dist).
		} catch ( \Throwable $e ) {
			( $this->logger ?? Services::logger() )->log(
				LogLevel::Error,
				'swg_listener_failed',
				array(
					'event'     => $source ?? 'gallery_changed',
					'hook'      => 'swg_gallery_changed',
					'exception' => $e::class,
				)
			);
		}
	}
}
