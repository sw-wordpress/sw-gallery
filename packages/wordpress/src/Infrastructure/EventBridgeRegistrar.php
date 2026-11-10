<?php
/**
 * Connects the shared core event dispatcher to the public `swg_*` hooks.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Infrastructure;

use SWGallery\WordPress\Bootstrap\RegistrarInterface;

final class EventBridgeRegistrar implements RegistrarInterface {

	public function register(): void {
		( new EventBridge( Services::logger() ) )->attach( Services::events() );
	}
}
