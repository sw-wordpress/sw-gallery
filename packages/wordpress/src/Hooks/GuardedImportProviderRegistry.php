<?php
/**
 * The registry handed to third-party code through `swg_register_import_providers`: rejections are logged, never thrown.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Hooks;

use SWGallery\Core\Contract\ImportProviderRegistryInterface;
use SWGallery\Core\Contract\LoggerInterface;
use SWGallery\Core\Contract\MediaImportProviderInterface;
use SWGallery\Core\Logging\LogLevel;

/**
 * An invalid or duplicate provider id (or a throwing `getProviderId()`) is rejected and logged as
 * `swg_import_provider_rejected` (provider class and exception class only); `register()` returns normally.
 */
final class GuardedImportProviderRegistry implements ImportProviderRegistryInterface {

	public function __construct( private readonly ImportProviderRegistryInterface $inner, private readonly LoggerInterface $logger ) {
	}

	public function register( MediaImportProviderInterface $provider ): void {
		try {
			$this->inner->register( $provider );
		} catch ( \Throwable $e ) {
			$this->logger->log(
				LogLevel::Error,
				'swg_import_provider_rejected',
				array(
					'provider'  => $provider::class,
					'exception' => $e::class,
				)
			);
		}
	}

	public function has( string $providerId ): bool {
		return $this->inner->has( $providerId );
	}

	public function get( string $providerId ): MediaImportProviderInterface {
		return $this->inner->get( $providerId );
	}

	public function all(): array {
		return $this->inner->all();
	}
}
