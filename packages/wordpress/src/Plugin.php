<?php
/**
 * Plugin bootstrap: runs the feature registrars.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress;

use SWGallery\WordPress\Bootstrap\Registrars;

final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		return self::$instance ??= new self();
	}

	public function boot(): void {
		foreach ( Registrars::all() as $registrar ) {
			$registrar->register();
		}
	}
}
