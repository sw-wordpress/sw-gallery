<?php
/**
 * Registers the Media Library import provider through the public `swg_register_import_providers` action.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Hooks;

use SWGallery\Core\Contract\ImportProviderRegistryInterface;
use SWGallery\WordPress\Bootstrap\RegistrarInterface;
use SWGallery\WordPress\Import\MediaLibraryProvider;

/**
 * The plugin uses the same extension point as third parties (priority 0, so its provider id is taken first and
 * cannot be replaced). No cloud connector ships with the plugin.
 */
final class ImportProvidersRegistrar implements RegistrarInterface {

	public function register(): void {
		add_action( 'swg_register_import_providers', array( $this, 'register_built_in' ), 0 );
	}

	public function register_built_in( ImportProviderRegistryInterface $registry ): void {
		$registry->register( new MediaLibraryProvider() );
	}
}
