<?php
/**
 * Editor-only diagnostics of an embed.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- core value objects use camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Rendering;

use SWGallery\Core\Application\Render\RenderDiagnostic;

/**
 * Maps the stable diagnostic codes of the pipeline to translated sentences. The pipeline only produces diagnostics
 * for users who can edit the gallery, so this class never decides visibility.
 */
final class DiagnosticMarkup {

	/**
	 * @param array<int, RenderDiagnostic> $diagnostics Diagnostics of one render.
	 */
	public static function render( array $diagnostics ): string {
		$html = '';
		foreach ( $diagnostics as $diagnostic ) {
			$html .= sprintf( '<p class="swg-diagnostic" role="note">%s</p>', esc_html( self::message( $diagnostic ) ) );
		}
		return $html;
	}

	public static function message( RenderDiagnostic $diagnostic ): string {
		switch ( $diagnostic->code ) {
			case RenderDiagnostic::GALLERY_NOT_FOUND:
				return __( 'Gallery diagnostic (visible to editors only): this gallery does not exist.', 'sw-gallery' );
			case RenderDiagnostic::GALLERY_NOT_PUBLIC:
				return __( 'Gallery diagnostic (visible to editors only): this gallery is not published, so visitors see nothing here.', 'sw-gallery' );
			case RenderDiagnostic::UNKNOWN_LAYOUT:
				return sprintf(
					/* translators: %s: layout id that is not available */
					__( 'Gallery diagnostic (visible to editors only): the layout "%s" is not available, the grid layout is used instead.', 'sw-gallery' ),
					self::context_string( $diagnostic, 'layout' )
				);
			case RenderDiagnostic::INVALID_OVERRIDE:
				return __( 'Gallery diagnostic (visible to editors only): the settings of this embed are invalid and were ignored.', 'sw-gallery' );
			case RenderDiagnostic::EMPTY_GALLERY:
				return __( 'Gallery diagnostic (visible to editors only): this gallery has no images yet.', 'sw-gallery' );
			case RenderDiagnostic::IMAGES_UNAVAILABLE:
				$count = (int) ( $diagnostic->context['count'] ?? 0 );
				return sprintf(
					/* translators: %d: number of images that could not be shown */
					_n(
						'Gallery diagnostic (visible to editors only): %d image is missing or unusable and was skipped.',
						'Gallery diagnostic (visible to editors only): %d images are missing or unusable and were skipped.',
						$count,
						'sw-gallery'
					),
					$count
				);
		}
		return __( 'Gallery diagnostic (visible to editors only).', 'sw-gallery' );
	}

	private static function context_string( RenderDiagnostic $diagnostic, string $key ): string {
		return (string) ( $diagnostic->context[ $key ] ?? '' );
	}
}
