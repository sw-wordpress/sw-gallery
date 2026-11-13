<?php
/**
 * The registry handed to third-party code through `swg_register_layouts`: rejections are logged, never thrown.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Hooks;

use SWGallery\Core\Contract\GalleryLayoutInterface;
use SWGallery\Core\Contract\LayoutRegistryInterface;
use SWGallery\Core\Contract\LoggerInterface;
use SWGallery\Core\Domain\Layout\LayoutResolution;
use SWGallery\Core\Logging\LogLevel;

/**
 * Third-party code must not break a request: a layout that fails the contract (invalid id, empty schema, a throwing
 * `getId()`/`getSettingsSchema()`) or reuses an id (built-in layouts are never replaced) is rejected and logged as
 * `swg_layout_rejected` (layout class and exception class only), and `register()` returns normally.
 */
final class GuardedLayoutRegistry implements LayoutRegistryInterface {

	public function __construct( private readonly LayoutRegistryInterface $inner, private readonly LoggerInterface $logger ) {
	}

	public function register( GalleryLayoutInterface $layout ): void {
		try {
			$this->inner->register( $layout );
		} catch ( \Throwable $e ) {
			$this->logger->log(
				LogLevel::Error,
				'swg_layout_rejected',
				array(
					'layout'    => $layout::class,
					'exception' => $e::class,
				)
			);
		}
	}

	public function has( string $id ): bool {
		return $this->inner->has( $id );
	}

	public function get( string $id ): GalleryLayoutInterface {
		return $this->inner->get( $id );
	}

	public function resolve( string $id ): LayoutResolution {
		return $this->inner->resolve( $id );
	}

	public function all(): array {
		return $this->inner->all();
	}
}
