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

class McpAbsenceTest extends SwgTestCase {

	use McpSecurityTestTrait;

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
		update_option( TierPolicy::OPTION, 'admin' );
		$this->reregisterAbilities( 'admin' );
		$this->assertSame( array(), $this->ourAbilities() );
	}
}
