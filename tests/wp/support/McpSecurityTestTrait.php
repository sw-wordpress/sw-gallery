<?php
/**
 * Helpers of the mandatory MCP security test (docs/mcp-harness.md §8). Copied between sw-* plugins; only the
 * constants below are plugin specific.
 *
 * @package sw-gallery
 */

use SWGallery\WordPress\Mcp\McpRegistrar;
use SWGallery\WordPress\Mcp\TierPolicy;

trait McpSecurityTestTrait {

	private const MCP_PREFIX = 'sw-gallery/';

	/** Sets or clears the tier option and registers again (the registries are built once per process). */
	protected function reregisterAbilities( ?string $tier ): void {
		if ( null === $tier ) {
			delete_option( TierPolicy::OPTION );
		} else {
			update_option( TierPolicy::OPTION, $tier );
		}
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return; // WordPress < 6.9 has no Abilities API.
		}
		foreach ( array_keys( $this->ourAbilities() ) as $name ) {
			wp_unregister_ability( $name );
		}
		global $wp_current_filter;
		$registrar = new McpRegistrar();
		if ( ! wp_has_ability_category( 'sw-gallery' ) ) {
			$wp_current_filter[] = 'wp_abilities_api_categories_init';
			$registrar->register_category();
			array_pop( $wp_current_filter );
		}
		$wp_current_filter[] = 'wp_abilities_api_init';
		$registrar->register_abilities();
		array_pop( $wp_current_filter );
	}

	/** @return array<string, WP_Ability> Registered abilities of this plugin by id. */
	protected function ourAbilities(): array {
		$found = array();
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return $found;
		}
		foreach ( wp_get_abilities() as $ability ) {
			if ( str_starts_with( $ability->get_name(), self::MCP_PREFIX ) ) {
				$found[ $ability->get_name() ] = $ability;
			}
		}
		return $found;
	}

	/**
	 * Executes an ability as a user (permission check, input validation, execution and output validation of core).
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return mixed|WP_Error
	 */
	protected function runAbility( string $name, array $input, int $user ) {
		wp_set_current_user( $user );
		$ability = wp_get_ability( self::MCP_PREFIX . $name );
		$this->assertInstanceOf( WP_Ability::class, $ability, $name . ' is registered' );
		return $ability->execute( $input );
	}

	/**
	 * Permission result of an ability for a user: `true` or the plugin's WP_Error. Core's `execute()` replaces every
	 * refusal by the generic `ability_invalid_permissions` (and raises a `_doing_it_wrong` notice), so the plugin's own
	 * codes are asserted here.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return true|WP_Error
	 */
	protected function checkAbility( string $name, array $input, int $user ) {
		wp_set_current_user( $user );
		$ability = wp_get_ability( self::MCP_PREFIX . $name );
		$this->assertInstanceOf( WP_Ability::class, $ability, $name . ' is registered' );
		return $ability->check_permissions( $input );
	}

	/** Hash of everything an ability must not change when it is read-only (the audit trail is expected to grow). */
	protected function stateSnapshot(): string {
		global $wpdb;
		$parts = array();
		foreach ( array( $wpdb->posts, $wpdb->postmeta, \SWGallery\WordPress\Database\Schema::items_table() ) as $table ) {
			$parts[] = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY 1, 2", ARRAY_A ); // phpcs:ignore WordPress.DB
		}
		$parts[] = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$wpdb->options}` WHERE option_name NOT IN (%s, %s) AND option_name NOT LIKE %s ORDER BY 1, 2", \SWGallery\WordPress\Mcp\McpAuditLog::OPTION, \SWGallery\WordPress\Infrastructure\WpLogger::OPTION, '\_transient%' ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return md5( (string) wp_json_encode( $parts ) );
	}

	/** Forbidden fragments in any response (paths, SQL, secrets). */
	protected function assertNoLeak( mixed $response, string $context ): void {
		$text = (string) wp_json_encode( $response instanceof WP_Error ? array( $response->get_error_code(), $response->get_error_message(), $response->get_error_data() ) : $response );
		$this->assertDoesNotMatchRegularExpression( '#(/var/www|/home/|wp-content|SELECT |INSERT |UPDATE `|wp_swg|password_hash|AUTH_KEY|_wpnonce|stack trace)#i', $text, $context );
	}
}
