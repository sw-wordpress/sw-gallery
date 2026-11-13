<?php
/**
 * Fires the registration actions of the public extension points with listener isolation.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Hooks;

use SWGallery\Core\Contract\GalleryLayoutInterface;
use SWGallery\Core\Contract\ImportProviderRegistryInterface;
use SWGallery\Core\Contract\LayoutRegistryInterface;
use SWGallery\Core\Contract\LoggerInterface;
use SWGallery\Core\Logging\LogLevel;

final class ExtensionPoints {

	/**
	 * `swg_register_layouts( LayoutRegistryInterface $registry, int $api_version )`. A throwing callback is logged
	 * and ignored (remaining callbacks of the hook are skipped; what was registered before it stays).
	 */
	public static function register_layouts( LayoutRegistryInterface $registry, LoggerInterface $logger ): void {
		try {
			do_action( 'swg_register_layouts', new GuardedLayoutRegistry( $registry, $logger ), GalleryLayoutInterface::API_VERSION ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- "swg_" is the documented plugin prefix.
		} catch ( \Throwable $e ) {
			self::failed( $logger, 'swg_register_layouts', $e );
		}
	}

	/** `swg_register_import_providers( ImportProviderRegistryInterface $registry )`, same isolation. */
	public static function register_import_providers( ImportProviderRegistryInterface $registry, LoggerInterface $logger ): void {
		try {
			do_action( 'swg_register_import_providers', new GuardedImportProviderRegistry( $registry, $logger ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- "swg_" is the documented plugin prefix.
		} catch ( \Throwable $e ) {
			self::failed( $logger, 'swg_register_import_providers', $e );
		}
	}

	private static function failed( LoggerInterface $logger, string $hook, \Throwable $e ): void {
		$logger->log(
			LogLevel::Error,
			'swg_listener_failed',
			array(
				'hook'      => $hook,
				'exception' => $e::class,
			)
		);
	}
}
