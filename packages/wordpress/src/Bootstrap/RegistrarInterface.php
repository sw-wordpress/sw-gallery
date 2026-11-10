<?php
/**
 * Contract of a hook registrar.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Bootstrap;

/**
 * Registers the WordPress hooks of one feature. Every feature owns its registrar, so the plugin bootstrap never
 * changes when a feature is added (only the one-line list in {@see Registrars}).
 */
interface RegistrarInterface {

	/** Adds the actions and filters of the feature. Runs once while the plugin boots. */
	public function register(): void;
}
