<?php
/**
 * Suggested privacy policy text for Settings > Privacy > Policy Guide.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Admin;

use SWGallery\WordPress\Bootstrap\RegistrarInterface;

/**
 * Core requires `wp_add_privacy_policy_content()` to be called on `admin_init`. The text states only facts that hold
 * for the code (no outbound requests, no own cookie, data in the site's database); it is a suggestion for the site owner.
 */
final class PrivacyRegistrar implements RegistrarInterface {

	public function register(): void {
		add_action( 'admin_init', array( $this, 'add_policy_content' ) );
	}

	public function add_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content( 'SW Gallery', $this->policy_text() );
	}

	/** The suggested text as escaped HTML (wp_kses_post). */
	public function policy_text(): string {
		$paragraphs = array(
			__( 'SW Gallery stores gallery definitions (titles, descriptions, settings) and the relations between galleries and images in this site\'s database. It does not collect or store any data about visitors, does not set tracking cookies and makes no requests to external services; scripts, styles and fonts are served from this site.', 'sw-gallery' ),
			__( 'Images in galleries are served from this site\'s own Media Library. Metadata such as EXIF or GPS data that is present in the original image files stays in the served files unless it is removed before upload.', 'sw-gallery' ),
			__( 'SW Gallery sets no cookie of its own. For a password-protected gallery, WordPress core\'s password form is shown; after the correct password is entered, WordPress sets its own cookie (wp-postpass_ followed by a hash), which by default expires after 10 days.', 'sw-gallery' ),
			__( 'When the plugin is deleted, its data is kept unless the setting "Delete all plugin data when the plugin is deleted" is enabled; in that case the galleries, their image relations and the plugin settings are removed. Images in the Media Library are never deleted by the plugin.', 'sw-gallery' ),
		);
		$html       = '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested text for your privacy policy. Review it and adapt it to your site.', 'sw-gallery' ) . '</p>';
		foreach ( $paragraphs as $paragraph ) {
			$html .= '<p>' . esc_html( $paragraph ) . '</p>';
		}
		return wp_kses_post( $html );
	}
}
