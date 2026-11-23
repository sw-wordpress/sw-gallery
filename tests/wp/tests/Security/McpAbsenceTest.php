<?php
/**
 * Without the mcp-adapter the plugin leaves no MCP footprint (docs/mcp-harness.md §8 #11). Runs in the second group
 * `composer test:wp:nomcp` (adapter not loaded); skipped in the normal run.
 *
 * @package sw-gallery
 */

require_once dirname( __DIR__, 2 ) . '/support/McpSecurityTestTrait.php';

use SWGallery\WordPress\Mcp\McpAuditLog;
use SWGallery\WordPress\Mcp\McpAvailability;
use SWGallery\WordPress\Mcp\TierPolicy;
use SWGallery\Tests\Support\RestFixtures;

class McpAbsenceTest extends SwgTestCase {

	use McpSecurityTestTrait;
	use RestFixtures;

	public function set_up(): void {
		parent::set_up();
		if ( class_exists( '\\WP\\MCP\\Plugin', false ) ) {
			$this->markTestSkipped( 'The adapter is loaded; run composer test:wp:nomcp.' );
		}
	}

	public function test_nothing_is_registered_and_no_option_exists(): void {
		$this->assertFalse( McpAvailability::adapterPresent() );
		$this->assertFalse( McpAvailability::isAvailable() );
		$this->assertSame( array(), $this->ourAbilities() );
		$this->assertFalse( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'sw-gallery' ) );
		$this->assertFalse( get_option( TierPolicy::OPTION, false ) );
		$this->assertFalse( get_option( McpAuditLog::OPTION, false ) );
	}

	public function test_even_a_high_tier_registers_nothing_without_the_adapter(): void {
		try {
			$this->reregisterAbilities( 'admin' );
			$this->assertSame( array(), $this->ourAbilities() );
		} finally {
			delete_option( TierPolicy::OPTION );
		}
	}

	public function test_settings_hide_the_control_and_reject_updates_without_adapter(): void {
		$this->bootRest();
		try {
			$admin = $this->makeUser( 'administrator' );
			$data  = $this->data( $this->call( 'GET', '/settings', array(), $admin ) );
			$this->assertArrayNotHasKey( 'mcpAccessLevel', $data );
			$this->assertArrayNotHasKey( 'mcpEffectiveLevel', $data );
			$response = $this->call( 'PATCH', '/settings', array( 'mcpAccessLevel' => 'admin' ), $admin );
			$this->assertSame( 400, $response->get_status() );
			$this->assertFalse( get_option( TierPolicy::OPTION, false ) );
		} finally {
			$this->shutdownRest();
		}
	}
}
