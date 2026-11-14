<?php
/**
 * Suggested privacy policy text (Settings > Privacy > Policy Guide).
 *
 * @package sw-gallery
 */

use SWGallery\WordPress\Admin\PrivacyRegistrar;
use SWGallery\WordPress\Bootstrap\Registrars;

class PrivacyRegistrarTest extends SwgTestCase {

	public function test_it_is_registered_exactly_once_on_admin_init(): void {
		$classes = array_map( static fn( $r ): string => $r::class, Registrars::all() );
		$this->assertSame( 1, count( array_keys( $classes, PrivacyRegistrar::class, true ) ) );

		$registrar = new PrivacyRegistrar();
		$registrar->register();
		$this->assertSame( 10, has_action( 'admin_init', array( $registrar, 'add_policy_content' ) ) );
	}

	public function test_the_text_reaches_the_policy_guide(): void {
		if ( ! class_exists( 'WP_Privacy_Policy_Content' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		set_current_screen( 'dashboard' );
		// Core accepts policy content only while `admin_init` runs; running every admin_init callback would send headers.
		$GLOBALS['wp_current_filter'][] = 'admin_init';
		try {
			( new PrivacyRegistrar() )->add_policy_content();
		} finally {
			array_pop( $GLOBALS['wp_current_filter'] );
		}
		$found = array_filter(
			WP_Privacy_Policy_Content::get_suggested_policy_text(),
			static fn( $entry ): bool => 'SW Gallery' === $entry['plugin_name']
		);
		$this->assertNotSame( array(), $found );
	}

	public function test_the_text_states_the_key_facts(): void {
		$text = wp_strip_all_tags( ( new PrivacyRegistrar() )->policy_text() );
		$this->assertStringContainsString( 'database', $text );
		$this->assertStringContainsString( 'does not collect', $text );
		$this->assertStringContainsString( 'no requests to external services', $text );
		$this->assertStringContainsString( 'EXIF', $text );
		$this->assertStringContainsString( 'wp-postpass_', $text );
		$this->assertStringContainsString( '10 days', $text );
		$this->assertStringContainsString( 'Delete all plugin data', $text );
		$this->assertStringNotContainsString( 'GitHub', $text );
		$this->assertStringNotContainsString( '<script', ( new PrivacyRegistrar() )->policy_text() );
	}

	public function test_the_german_variant_is_translated(): void {
		switch_to_locale( 'de_DE' );
		try {
			load_textdomain( 'sw-gallery', SWGALLERY_DIR . 'languages/sw-gallery-de_DE.mo' );
			$text = wp_strip_all_tags( ( new PrivacyRegistrar() )->policy_text() );
			$this->assertStringContainsString( 'Datenbank', $text );
			$this->assertStringContainsString( 'wp-postpass_', $text );
			$this->assertStringNotContainsString( 'does not collect', $text );
		} finally {
			restore_previous_locale();
		}
	}
}
