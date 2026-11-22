<?php
/**
 * The list of feature registrars.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Bootstrap;

use SWGallery\WordPress\Admin\AdminRegistrar;
use SWGallery\WordPress\Admin\MediaLibraryRegistrar;
use SWGallery\WordPress\Admin\PrivacyRegistrar;
use SWGallery\WordPress\Block\BlockRegistrar;
use SWGallery\WordPress\Cli\CliRegistrar;
use SWGallery\WordPress\Database\SchemaRegistrar;
use SWGallery\WordPress\Frontend\FrontendRegistrar;
use SWGallery\WordPress\Hooks\CacheInvalidationRegistrar;
use SWGallery\WordPress\Hooks\GalleryLifecycleRegistrar;
use SWGallery\WordPress\Hooks\ImportProvidersRegistrar;
use SWGallery\WordPress\Infrastructure\EventBridgeRegistrar;
use SWGallery\WordPress\Mcp\McpRegistrar;
use SWGallery\WordPress\Rest\RestRegistrar;
use SWGallery\WordPress\Shortcode\ShortcodeRegistrar;
use SWGallery\WordPress\Update\UpdateRegistrar;

final class Registrars {

	/**
	 * One registrar per line, alphabetical by class name, so parallel feature work merges without conflicts.
	 *
	 * @return list<RegistrarInterface>
	 */
	public static function all(): array {
		return array(
			new AdminRegistrar(),
			new BlockRegistrar(),
			new CacheInvalidationRegistrar(),
			new CliRegistrar(),
			new CoreRegistrar(),
			new EventBridgeRegistrar(),
			new FrontendRegistrar(),
			new GalleryLifecycleRegistrar(),
			new ImportProvidersRegistrar(),
			new McpRegistrar(),
			new MediaLibraryRegistrar(),
			new PrivacyRegistrar(),
			new RestRegistrar(),
			new SchemaRegistrar(),
			new ShortcodeRegistrar(),
			new UpdateRegistrar(),
		);
	}
}
