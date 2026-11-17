<?php
/**
 * Verified GitHub update hooks.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Update;

use SWGallery\WordPress\Bootstrap\RegistrarInterface;

final class UpdateRegistrar implements RegistrarInterface {
	public function register(): void {
		Provider::init();
	}
}
