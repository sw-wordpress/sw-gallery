<?php
/**
 * Annotations shared by read-only abilities, including administration queries.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Mcp\Ability;

trait ReadAnnotationsTrait {

	/** @return array{readonly: bool, destructive: bool, idempotent: bool} */
	public function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}
}
