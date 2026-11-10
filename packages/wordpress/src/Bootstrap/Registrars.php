<?php
/**
 * The list of feature registrars.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Bootstrap;

final class Registrars {

	/**
	 * One registrar per line, alphabetical by class name, so parallel feature work merges without conflicts.
	 *
	 * @return list<RegistrarInterface>
	 */
	public static function all(): array {
		return array(
			new CoreRegistrar(),
		);
	}
}
