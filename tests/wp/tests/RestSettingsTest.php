<?php
/**
 * GET/PATCH /swg/v1/settings: role matrix, validation (400 vs 422), round trip, unknown-key preservation and the
 * confirmation rule for deleting data on uninstall.
 *
 * @package sw-gallery
 */

use SWGallery\Tests\Support\RestFixtures;
use SWGallery\WordPress\Infrastructure\Services;
use SWGallery\WordPress\Lifecycle;
use SWGallery\WordPress\Settings\PluginSettings;

class RestSettingsTest extends SwgTestCase {

	use RestFixtures;

	private int $admin;

	public function set_up(): void {
		parent::set_up();
		$this->bootRest();
		$this->admin = $this->makeUser( 'administrator' );
		delete_option( PluginSettings::OPTION );
		delete_option( Lifecycle::OPTION_DELETE_ON_UNINSTALL );
	}

	public function tear_down(): void {
		delete_option( PluginSettings::OPTION );
		delete_option( Lifecycle::OPTION_DELETE_ON_UNINSTALL );
		$this->shutdownRest();
		parent::tear_down();
	}

	public function test_role_matrix_for_read_and_write(): void {
		$expected = array(
			'administrator' => 200,
			'editor'        => 403,
			'author'        => 403,
			'contributor'   => 403,
			'subscriber'    => 403,
			'anonymous'     => 401,
		);
		foreach ( $expected as $role => $status ) {
			$user = 'anonymous' === $role ? 0 : $this->makeUser( $role );
			$this->assertSame( $status, $this->call( 'GET', '/settings', array(), $user )->get_status(), 'GET as ' . $role );
			if ( 'administrator' !== $role ) {
				$this->assertSame( $status, $this->call( 'PATCH', '/settings', array( 'lightbox' => false ), $user )->get_status(), 'PATCH as ' . $role );
			}
		}
		$this->assertTrue( $this->data( $this->call( 'GET', '/settings', array(), $this->admin ) )['lightbox'], 'refused writes changed nothing' );
		$this->assertSame( 200, $this->call( 'PATCH', '/settings', array( 'lightbox' => false ), $this->admin )->get_status() );
	}

	public function test_defaults_and_the_response_shape(): void {
		$data = $this->data( $this->call( 'GET', '/settings', array(), $this->admin ) );

		$this->assertSame( 'grid', $data['defaultLayout'] );
		$this->assertSame( 24, $data['itemsPerPage'] );
		$this->assertTrue( $data['lightbox'] );
		$this->assertFalse( $data['deleteDataOnUninstall'] );
		$this->assertSame( PluginSettings::SCHEMA_VERSION, $data['schemaVersion'] );
		$this->assertSame( PluginSettings::DEFAULTS, $data['defaults'] );
		$this->assertStringContainsString( 'never deleted', $data['deleteDataNotice'] );
		$this->assertFalse( get_option( PluginSettings::OPTION ), 'reading does not write' );
	}

	public function test_a_partial_update_round_trips_and_keeps_the_other_keys(): void {
		$first = $this->call( 'PATCH', '/settings', array( 'itemsPerPage' => 60, 'lightbox' => false ), $this->admin );
		$this->assertSame( 200, $first->get_status() );
		$second = $this->call( 'PATCH', '/settings', array( 'itemsPerPage' => 12 ), $this->admin );
		$data   = $this->data( $second );

		$this->assertSame( 12, $data['itemsPerPage'] );
		$this->assertFalse( $data['lightbox'], 'an untouched key keeps its stored value' );
		$this->assertEquals( $data, $this->data( $this->call( 'GET', '/settings', array(), $this->admin ) ) );
	}

	public function test_the_object_is_stored_as_one_versioned_non_autoloaded_json_option(): void {
		global $wpdb;
		$this->call( 'PATCH', '/settings', array( 'itemsPerPage' => 50 ), $this->admin );

		$stored = json_decode( (string) get_option( PluginSettings::OPTION ), true );
		$this->assertSame( 50, $stored['itemsPerPage'] );
		$this->assertSame( PluginSettings::SCHEMA_VERSION, $stored['schemaVersion'] );
		$this->assertArrayNotHasKey( 'deleteDataOnUninstall', $stored );
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", PluginSettings::OPTION ) );
		$this->assertContains( $autoload, array( 'no', 'off' ), 'autoload is off' );
	}

	public function test_unknown_stored_keys_survive_a_save(): void {
		update_option( PluginSettings::OPTION, (string) wp_json_encode( array( 'schemaVersion' => 9, 'futureKey' => array( 'a' => 1 ), 'itemsPerPage' => 30 ) ), false );

		$this->call( 'PATCH', '/settings', array( 'lightbox' => false ), $this->admin );

		$stored = json_decode( (string) get_option( PluginSettings::OPTION ), true );
		$this->assertSame( array( 'a' => 1 ), $stored['futureKey'] );
		$this->assertSame( 30, $stored['itemsPerPage'] );
		$this->assertFalse( $stored['lightbox'] );
		$this->assertSame( PluginSettings::SCHEMA_VERSION, $stored['schemaVersion'] );
	}

	public function test_broken_or_out_of_range_stored_values_fall_back_to_defaults(): void {
		update_option( PluginSettings::OPTION, (string) wp_json_encode( array( 'defaultLayout' => 'gone', 'itemsPerPage' => 5000, 'lightbox' => 'yes' ) ), false );
		$data = $this->data( $this->call( 'GET', '/settings', array(), $this->admin ) );
		$this->assertSame( 'grid', $data['defaultLayout'] );
		$this->assertSame( 24, $data['itemsPerPage'] );
		$this->assertTrue( $data['lightbox'] );

		update_option( PluginSettings::OPTION, 'not json {', false );
		$this->assertSame( 24, $this->data( $this->call( 'GET', '/settings', array(), $this->admin ) )['itemsPerPage'] );
		update_option( PluginSettings::OPTION, '"scalar"', false );
		$this->assertSame( 24, $this->data( $this->call( 'GET', '/settings', array(), $this->admin ) )['itemsPerPage'] );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: int, 2: string}>
	 */
	public static function invalid_updates(): array {
		return array(
			'items too low'       => array( array( 'itemsPerPage' => 0 ), 400, 'rest_invalid_param' ),
			'items too high'      => array( array( 'itemsPerPage' => 201 ), 400, 'rest_invalid_param' ),
			'items wrong type'    => array( array( 'itemsPerPage' => 'many' ), 400, 'rest_invalid_param' ),
			'lightbox wrong type' => array( array( 'lightbox' => 'maybe' ), 400, 'rest_invalid_param' ),
			'layout empty'        => array( array( 'defaultLayout' => '' ), 400, 'rest_invalid_param' ),
			'layout too long'     => array( array( 'defaultLayout' => str_repeat( 'a', 65 ) ), 400, 'rest_invalid_param' ),
			'layout null'         => array( array( 'defaultLayout' => null ), 422, 'swg_validation_failed' ),
			'unknown layout'      => array( array( 'defaultLayout' => 'does-not-exist' ), 422, 'swg_unknown_layout' ),
			'empty body'          => array( array(), 400, 'swg_no_changes' ),
			'only unknown keys'   => array( array( 'foo' => 1 ), 400, 'swg_no_changes' ),
		);
	}

	/**
	 * @dataProvider invalid_updates
	 * @param array<string, mixed> $body Request body.
	 */
	public function test_invalid_updates_are_refused_and_change_nothing( array $body, int $status, string $code ): void {
		$response = $this->call( 'PATCH', '/settings', $body, $this->admin );

		$this->assertSame( $status, $response->get_status() );
		$this->assertSame( $code, $response->as_error()->get_error_code() );
		$this->assertFalse( get_option( PluginSettings::OPTION ), 'nothing was stored' );
	}

	public function test_turning_the_uninstall_deletion_on_needs_an_explicit_confirmation(): void {
		$refused = $this->call( 'PATCH', '/settings', array( 'deleteDataOnUninstall' => true ), $this->admin );
		$this->assertSame( 422, $refused->get_status() );
		$this->assertSame( 'swg_confirmation_required', $refused->as_error()->get_error_code() );
		$this->assertFalse( get_option( Lifecycle::OPTION_DELETE_ON_UNINSTALL ), 'the real option is untouched' );

		$false_confirm = $this->call( 'PATCH', '/settings', array( 'deleteDataOnUninstall' => true, 'confirmDeleteData' => false ), $this->admin );
		$this->assertSame( 422, $false_confirm->get_status() );
		$this->assertFalse( get_option( Lifecycle::OPTION_DELETE_ON_UNINSTALL ) );

		$confirmed = $this->call( 'PATCH', '/settings', array( 'deleteDataOnUninstall' => true, 'confirmDeleteData' => true ), $this->admin );
		$data      = $this->data( $confirmed );
		$this->assertSame( 200, $confirmed->get_status() );
		$this->assertTrue( $data['deleteDataOnUninstall'] );
		$this->assertSame( '1', get_option( Lifecycle::OPTION_DELETE_ON_UNINSTALL ) );
		$this->assertStringContainsString( 'never deleted', $data['deleteDataNotice'] );
	}

	public function test_turning_it_off_and_repeating_it_on_need_no_confirmation(): void {
		update_option( Lifecycle::OPTION_DELETE_ON_UNINSTALL, '1', false );

		$again = $this->call( 'PATCH', '/settings', array( 'deleteDataOnUninstall' => true ), $this->admin );
		$this->assertSame( 200, $again->get_status(), 'already on: not a transition' );

		$off = $this->call( 'PATCH', '/settings', array( 'deleteDataOnUninstall' => false ), $this->admin );
		$this->assertSame( 200, $off->get_status() );
		$this->assertFalse( $this->data( $off )['deleteDataOnUninstall'] );
		$this->assertSame( '0', get_option( Lifecycle::OPTION_DELETE_ON_UNINSTALL ) );
	}

	public function test_a_registered_layout_can_become_the_default(): void {
		Services::layouts()->register( new \SWGallery\Tests\Support\StubLayout( 'masonry' ) );

		$response = $this->call( 'PATCH', '/settings', array( 'defaultLayout' => 'masonry' ), $this->admin );

		$this->assertSame( 'masonry', $this->data( $response )['defaultLayout'] );
	}

	public function test_the_response_holds_no_secrets_or_paths(): void {
		$body = (string) wp_json_encode( $this->data( $this->call( 'GET', '/settings', array(), $this->admin ) ) );
		foreach ( array( ABSPATH, WP_CONTENT_DIR, '/var/www', 'password', 'secret', 'token' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $body );
		}
	}
}
