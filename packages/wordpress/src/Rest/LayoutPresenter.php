<?php
/**
 * REST representation of the registered layouts.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Rest;

use SWGallery\Core\Contract\GalleryLayoutInterface;
use SWGallery\Core\Contract\LayoutRegistryInterface;

/**
 * Layouts return English labels; they are translated here, at presentation time, from the plugin catalog (labels of
 * third-party layouts that the catalog does not know stay as they are).
 */
final class LayoutPresenter {

	/**
	 * @return array{fallbackId: string, layouts: list<array<string, mixed>>}
	 */
	public function collection( LayoutRegistryInterface $registry ): array {
		$layouts = array();
		foreach ( $registry->all() as $layout ) {
			$layouts[] = $this->layout( $layout );
		}
		return array(
			'fallbackId' => LayoutRegistryInterface::FALLBACK_ID,
			'layouts'    => $layouts,
		);
	}

	/**
	 * @return array{id: string, label: string, settingsSchema: array<string, mixed>}
	 */
	public function layout( GalleryLayoutInterface $layout ): array {
		return array(
			'id'             => $layout->getId(),
			'label'          => self::translate_label( $layout->getLabel() ),
			'settingsSchema' => $this->schema( $layout->getSettingsSchema() ),
		);
	}

	/**
	 * Translates a layout label that is part of the plugin catalog; unknown labels are returned unchanged.
	 *
	 * @param string $label English label.
	 */
	public static function translate_label( string $label ): string {
		// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- runtime lookup of catalog entries declared in label_catalog().
		return __( $label, 'sw-gallery' );
	}

	/**
	 * Declares the translatable labels of the shipped layouts (never executed, only scanned by xgettext).
	 *
	 * @return list<string>
	 */
	public static function label_catalog(): array {
		return array(
			__( 'Grid', 'sw-gallery' ),
		);
	}

	/**
	 * Keeps empty objects as objects in JSON (an empty PHP array would encode as a list) and the schema otherwise as
	 * the layout declared it, including the `x-responsive` and `x-item-settings` extension keys.
	 *
	 * @param array<string, mixed> $schema Layout schema.
	 * @return array<string, mixed>
	 */
	private function schema( array $schema ): array {
		if ( array() === ( $schema['properties'] ?? null ) ) {
			$schema['properties'] = new \stdClass();
		}
		return $schema;
	}
}
