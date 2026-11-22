<?php
/**
 * Mandatory MCP security test (docs/mcp-harness.md §8): inventory, meta lint, callbacks, actor matrix, tier gate,
 * audit, input hardening, read-only honesty and the transport of the mcp-adapter.
 *
 * @package sw-gallery
 */

require_once dirname( __DIR__, 2 ) . '/support/McpSecurityTestTrait.php';

use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Tests\Support\RestFixtures;
use SWGallery\WordPress\Mcp\AbilityFactory;
use SWGallery\WordPress\Mcp\McpAuditLog;
use SWGallery\WordPress\Mcp\TierPolicy;

class McpAbilitySecurityTest extends SwgTestCase {

	use RestFixtures;
	use McpSecurityTestTrait;

	private const ACTORS = array( 'anonymous', 'subscriber', 'contributor', 'author', 'other_author', 'editor', 'administrator' );

	/** A allowed, F forbidden, N not found, X anonymous (refused by the ability, 401). Order of ACTORS. */
	private const MATRIX = array(
		'get-gallery'    => array( 'tier' => 'read', 'expect' => array( 'X', 'F', 'N', 'A', 'N', 'A', 'A' ) ),
		'update-gallery' => array( 'tier' => 'write', 'expect' => array( 'X', 'F', 'N', 'A', 'N', 'A', 'A' ) ),
	);

	/** @var array<string, int> */
	private array $users = array();
	private int $gallery = 0;

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( '\\WP\\MCP\\Plugin', false ) ) {
			$this->markTestSkipped( 'The mcp-adapter is not loaded (absence run).' );
		}
		$this->bootRest();
		foreach ( self::ACTORS as $actor ) {
			if ( 'anonymous' !== $actor ) {
				$role                   = array( 'subscriber' => 'subscriber', 'contributor' => 'contributor', 'editor' => 'editor', 'administrator' => 'administrator' )[ $actor ] ?? 'author';
				$this->users[ $actor ] = $this->makeUser( $role );
			}
		}
		$this->users['anonymous'] = 0;
		$this->gallery            = $this->makeGallery( $this->users['author'], GalleryStatus::Draft, 'Secret draft' );
		$this->reregisterAbilities( 'admin' );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		foreach ( array_keys( $this->ourAbilities() ) as $name ) {
			wp_unregister_ability( $name );
		}
		delete_option( TierPolicy::OPTION );
		delete_option( McpAuditLog::OPTION );
		$this->shutdownRest();
		parent::tear_down();
	}

	/** @return array<string, mixed> */
	private function validInput( string $name ): array {
		$base = array( 'id' => $this->gallery );
		return 'update-gallery' === $name ? $base + array( 'expectedRevision' => 1, 'title' => 'Renamed' ) : $base;
	}

	public function test_inventory_matches_the_matrix_and_names_are_valid(): void {
		$names = array_map( static fn( string $id ): string => substr( $id, strlen( 'sw-gallery/' ) ), array_keys( $this->ourAbilities() ) );
		sort( $names );
		$expected = array_keys( self::MATRIX );
		sort( $expected );
		$this->assertSame( $expected, $names, 'A new ability needs a matrix row (and a catalogue entry); a removed one must leave the matrix.' );
		foreach ( $this->ourAbilities() as $id => $ability ) {
			$this->assertMatchesRegularExpression( '#^sw-gallery/[a-z]+(-[a-z]+)+$#', $id );
			$this->assertSame( 'sw-gallery', $ability->get_category() );
		}
	}

	public function test_meta_lint(): void {
		foreach ( $this->ourAbilities() as $id => $ability ) {
			$meta = $ability->get_meta();
			$name = substr( $id, strlen( 'sw-gallery/' ) );
			$this->assertTrue( $meta['mcp']['public'] ?? null, $id . ' mcp.public' );
			$this->assertArrayNotHasKey( 'public', $meta, $id . ' high-level public flag stays unset' );
			$this->assertFalse( $meta['show_in_rest'], $id . ' is not exposed through the core REST abilities endpoint' );
			foreach ( array( 'readonly', 'destructive', 'idempotent' ) as $annotation ) {
				$this->assertIsBool( $meta['annotations'][ $annotation ] ?? null, $id . ' annotation ' . $annotation );
			}
			$tier = self::MATRIX[ $name ]['tier'];
			$this->assertContains( $tier, TierPolicy::all() );
			if ( 'read' === $tier ) {
				$this->assertTrue( $meta['annotations']['readonly'], $id . ' read tier is readonly' );
			} else {
				$this->assertFalse( $meta['annotations']['readonly'], $id . ' non-read tier is not readonly' );
			}
			$this->assertSame( 'destructive' === $tier, $meta['annotations']['destructive'], $id . ' destructive annotation follows the tier' );
			$this->assertNotSame( array(), $ability->get_output_schema(), $id . ' output schema' );
			$this->assertSchemaBounded( $ability->get_input_schema(), $id );
			$this->assertDoesNotMatchRegularExpression( '/\p{L}*[äöüß]/u', $ability->get_description(), $id . ' description stays English' );
		}
	}

	/** @param array<string, mixed> $schema */
	private function assertSchemaBounded( array $schema, string $where ): void {
		$this->assertSame( 'object', $schema['type'], $where );
		$this->assertFalse( $schema['additionalProperties'], $where . ' rejects unknown keys' );
		foreach ( $schema['properties'] as $key => $property ) {
			$types = (array) ( $property['type'] ?? array() );
			if ( in_array( 'string', $types, true ) ) {
				$this->assertTrue( isset( $property['maxLength'] ) || isset( $property['enum'] ), "$where.$key string needs maxLength or enum" );
			}
			if ( in_array( 'integer', $types, true ) ) {
				$this->assertTrue( isset( $property['minimum'], $property['maximum'] ), "$where.$key integer needs bounds" );
			}
			if ( in_array( 'array', $types, true ) ) {
				$this->assertArrayHasKey( 'maxItems', $property, "$where.$key array needs maxItems" );
			}
			if ( in_array( 'object', $types, true ) && true === ( $property['additionalProperties'] ?? false ) ) {
				$this->assertArrayHasKey( 'maxProperties', $property, "$where.$key free-form object needs maxProperties" );
			}
		}
	}

	public function test_callbacks_are_the_factory_wrappers_and_the_code_has_no_forbidden_calls(): void {
		foreach ( $this->ourAbilities() as $id => $ability ) {
			foreach ( array( 'permission_callback', 'execute_callback' ) as $property ) {
				$reflection = new ReflectionProperty( WP_Ability::class, $property );
				$reflection->setAccessible( true );
				$callback = $reflection->getValue( $ability );
				$this->assertInstanceOf( Closure::class, $callback, "$id $property" );
				$this->assertSame( AbilityFactory::class, ( new ReflectionFunction( $callback ) )->getClosureScopeClass()?->getName(), "$id $property is the factory wrapper (not __return_true)" );
			}
		}
		$root = dirname( __DIR__, 4 ) . '/packages/wordpress/src';
		$iter = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/Mcp' ) );
		foreach ( $iter as $file ) {
			if ( ! $file instanceof SplFileInfo || 'php' !== $file->getExtension() ) {
				continue;
			}
			$code = (string) file_get_contents( $file->getPathname() );
			$this->assertDoesNotMatchRegularExpression( '/\b(rest_do_request|unserialize|eval|__return_true)\s*\(|\$wpdb|\$_(GET|POST|REQUEST)/', $code, $file->getFilename() );
			if ( 'AbilityFactory.php' !== $file->getFilename() ) {
				$this->assertStringNotContainsString( 'wp_register_ability(', $code, $file->getFilename() . ' must not register abilities' );
			}
		}
		$all = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );
		foreach ( $all as $file ) {
			if ( $file instanceof SplFileInfo && 'php' === $file->getExtension() && ! str_contains( $file->getPathname(), '/Mcp/' ) ) {
				$this->assertStringNotContainsString( 'wp_register_ability(', (string) file_get_contents( $file->getPathname() ), $file->getPathname() );
			}
		}
	}

	/** @return array<string, array{string, string}> */
	public static function matrixCases(): array {
		$cases = array();
		foreach ( self::MATRIX as $name => $row ) {
			foreach ( self::ACTORS as $index => $actor ) {
				$cases[ "$name as $actor" ] = array( $name, $actor, $row['expect'][ $index ] );
			}
		}
		return $cases;
	}

	/**
	 * @dataProvider matrixCases
	 */
	public function test_actor_matrix( string $name, string $actor, string $expect ): void {
		$input = $this->validInput( $name );
		if ( 'A' === $expect ) {
			$result = $this->runAbility( $name, $input, $this->users[ $actor ] );
			$this->assertNoLeak( $result, "$name as $actor" );
			$this->assertIsArray( $result, "$name as $actor" );
			$this->assertSame( $this->gallery, $result['id'] );
			return;
		}
		// The plugin's own decision (codes and statuses as REST).
		$check = $this->checkAbility( $name, $input, $this->users[ $actor ] );
		$this->assertInstanceOf( WP_Error::class, $check, "$name as $actor must be refused" );
		$this->assertNoLeak( $check, "$name as $actor" );
		$codes = array(
			'X' => array( 'swg_forbidden', 401 ),
			'F' => array( 'swg_forbidden', 403 ),
			'N' => array( 'swg_gallery_not_found', 404 ),
		)[ $expect ];
		$this->assertSame( $codes, array( $check->get_error_code(), $check->get_error_data()['status'] ), "$name as $actor" );
		// What the client gets through core: one generic refusal, the same for every reason.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$result = $this->runAbility( $name, $input, $this->users[ $actor ] );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertNoLeak( $result, "$name as $actor via execute" );
		
	}

	public function test_missing_and_hidden_galleries_answer_identically(): void {
		foreach ( array( 'get-gallery', 'update-gallery' ) as $name ) {
			$missing = $this->checkAbility( $name, array( 'id' => 999999 ) + $this->validInput( $name ), $this->users['other_author'] );
			$hidden  = $this->checkAbility( $name, $this->validInput( $name ), $this->users['other_author'] );
			$this->assertInstanceOf( WP_Error::class, $missing );
			$this->assertSame( $missing->get_error_code(), $hidden->get_error_code() );
			$this->assertSame( $missing->get_error_message(), $hidden->get_error_message() );
			$this->assertSame( $missing->get_error_data(), $hidden->get_error_data() );
		}
	}

	public function test_default_tier_is_read_and_registers_only_read_abilities(): void {
		delete_option( TierPolicy::OPTION );
		$this->assertSame( 'read', TierPolicy::effective() );
		$this->reregisterAbilities( 'read' );
		$this->assertSame( array( 'sw-gallery/get-gallery' ), array_keys( $this->ourAbilities() ) );
		$this->reregisterAbilities( 'off' );
		$this->assertSame( array(), $this->ourAbilities() );
		$this->assertFalse( \SWGallery\WordPress\Mcp\McpAvailability::isAvailable() );
	}

	public function test_invalid_tier_values_fail_closed_and_ceiling_never_raises(): void {
		update_option( TierPolicy::OPTION, 'root' );
		$this->assertSame( 'off', TierPolicy::effective() );
		$this->assertSame( 'off', TierPolicy::normalize( array( 'admin' ) ) );
		$this->assertSame( 'read', TierPolicy::lower( 'read', 'admin' ) );
		$this->assertSame( 'read', TierPolicy::lower( 'admin', 'read' ) );
		$this->assertFalse( TierPolicy::allows( 'off' ) );
		$this->assertFalse( TierPolicy::allows( 'nonsense' ) );
	}

	public function test_lowering_the_tier_after_registration_refuses_at_call_time(): void {
		update_option( TierPolicy::OPTION, 'read' );
		$check = $this->checkAbility( 'update-gallery', $this->validInput( 'update-gallery' ), $this->users['administrator'] );
		$this->assertInstanceOf( WP_Error::class, $check );
		$this->assertSame( AbilityFactory::CODE_TIER_DISABLED, $check->get_error_code() );
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$result = $this->runAbility( 'update-gallery', $this->validInput( 'update-gallery' ), $this->users['administrator'] );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertSame( 'Secret draft', $this->runAbility( 'get-gallery', array( 'id' => $this->gallery ), $this->users['author'] )['title'], 'nothing was written' );
	}

	public function test_read_ability_does_not_change_state_and_hides_urls(): void {
		$before = $this->stateSnapshot();
		$result = $this->runAbility( 'get-gallery', array( 'id' => $this->gallery ), $this->users['administrator'] );
		$this->assertSame( $before, $this->stateSnapshot() );
		$this->assertArrayNotHasKey( 'coverUrl', $result );
		$this->assertSame( array_keys( \SWGallery\WordPress\Mcp\GallerySchema::output()['properties'] ), array_keys( $result ) );
	}

	public function test_update_changes_only_the_gallery_and_enforces_the_revision(): void {
		$read = $this->runAbility( 'get-gallery', array( 'id' => $this->gallery ), $this->users['author'] );
		$ok   = $this->runAbility( 'update-gallery', array( 'id' => $this->gallery, 'expectedRevision' => $read['revision'], 'title' => 'Renamed', 'sortMode' => 'title_asc', 'perPage' => null ), $this->users['author'] );
		$this->assertSame( 'Renamed', $ok['title'] );
		$this->assertSame( 'title_asc', $ok['sortMode'] );
		$this->assertGreaterThan( $read['revision'], $ok['revision'] );

		$stale = $this->runAbility( 'update-gallery', array( 'id' => $this->gallery, 'expectedRevision' => $read['revision'], 'title' => 'Lost update' ), $this->users['author'] );
		$this->assertInstanceOf( WP_Error::class, $stale );
		$this->assertSame( 'swg_revision_conflict', $stale->get_error_code() );
		$this->assertSame( $ok['revision'], $stale->get_error_data()['currentRevision'] );
		$this->assertSame( 'Renamed', $this->runAbility( 'get-gallery', array( 'id' => $this->gallery ), $this->users['author'] )['title'] );
	}

	public function test_contributor_cannot_publish_and_empty_change_is_rejected(): void {
		$own = $this->makeGallery( $this->users['contributor'], GalleryStatus::Draft, 'Mine' );
		$rev = $this->runAbility( 'get-gallery', array( 'id' => $own ), $this->users['contributor'] )['revision'];
		$pub = $this->runAbility( 'update-gallery', array( 'id' => $own, 'expectedRevision' => $rev, 'status' => 'publish' ), $this->users['contributor'] );
		$this->assertSame( 'swg_cannot_publish', $pub->get_error_code() );
		$none = $this->runAbility( 'update-gallery', array( 'id' => $own, 'expectedRevision' => $rev ), $this->users['contributor'] );
		$this->assertSame( 'swg_no_changes', $none->get_error_code() );
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function hostileInputs(): array {
		return array(
			'owner not exposed'   => array( array( 'ownerId' => 1 ) ),
			'unknown key'         => array( array( 'evil' => 'x' ) ),
			'title too long'      => array( array( 'title' => str_repeat( 'a', 201 ) ) ),
			'empty title'         => array( array( 'title' => '' ) ),
			'title array'         => array( array( 'title' => array( 'a' ) ) ),
			'bad status'          => array( array( 'status' => 'trash' ) ),
			'bad sort'            => array( array( 'sortMode' => 'random' ) ),
			'per page zero'       => array( array( 'perPage' => 0 ) ),
			'per page float'      => array( array( 'perPage' => 2.5 ) ),
			'cover zero'          => array( array( 'coverAttachmentId' => 0 ) ),
			'layout pattern'      => array( array( 'layout' => '../../etc' ) ),
			'hover unknown key'   => array( array( 'hover' => array( 'x' => true ) ) ),
			'settings too many'   => array( array( 'settings' => array_fill_keys( array_map( 'strval', range( 1, 201 ) ), 1 ) ) ),
			'settings too deep'   => array( array( 'settings' => array( 'a' => array( 'b' => array( 'c' => array( 'd' => array( 'e' => array( 'f' => array( 'g' => 1 ) ) ) ) ) ) ) ) ),
			'settings too big'    => array( array( 'settings' => array( 'a' => str_repeat( 'x', 40000 ) ) ) ),
		);
	}

	/**
	 * @dataProvider hostileInputs
	 * @param array<string, mixed> $extra
	 */
	public function test_hostile_input_is_rejected_and_nothing_is_written( array $extra ): void {
		$before = $this->stateSnapshot();
		$result = $this->runAbility( 'update-gallery', array( 'id' => $this->gallery, 'expectedRevision' => 1 ) + $extra, $this->users['administrator'] );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNoLeak( $result, 'hostile input' );
		$this->assertSame( $before, $this->stateSnapshot() );
	}

	public function test_invalid_ids_are_rejected_by_the_schema(): void {
		foreach ( array( 0, -1, '1; DROP', 2.5, array( 1 ), 2147483648 ) as $id ) {
			$this->assertInstanceOf( WP_Error::class, $this->runAbility( 'get-gallery', array( 'id' => $id ), $this->users['administrator'] ), wp_json_encode( $id ) );
		}
	}

	public function test_audit_records_denials_and_mutations_without_payload(): void {
		$this->runAbility( 'get-gallery', array( 'id' => $this->gallery ), $this->users['author'] );
		$this->assertSame( array(), ( new McpAuditLog() )->recent(), 'successful reads are not audited' );

		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$this->runAbility( 'get-gallery', array( 'id' => $this->gallery ), $this->users['subscriber'] );
		$this->runAbility( 'update-gallery', array( 'id' => $this->gallery, 'expectedRevision' => 1, 'title' => 'Audited title' ), $this->users['author'] );
		$entries = ( new McpAuditLog() )->recent();
		$this->assertNotSame( array(), $entries );
		$outcomes = array_column( $entries, 'outcome' );
		$this->assertContains( 'denied', $outcomes );
		$this->assertContains( 'ok', $outcomes );
		foreach ( $entries as $entry ) {
			$this->assertSame( array( 'time', 'ability', 'user', 'outcome', 'code' ), array_keys( $entry ) );
			$this->assertStringNotContainsString( 'Audited title', (string) wp_json_encode( $entry ) );
			$this->assertMatchesRegularExpression( '/^[a-z]+(-[a-z]+)+$/', $entry['ability'] );
		}
		$this->assertContains( 'update-gallery', array_column( $entries, 'ability' ) );
	}

	public function test_audit_is_bounded_and_survives_a_throwing_observer(): void {
		add_action(
			McpAuditLog::ACTION,
			static function (): void {
				throw new RuntimeException( 'observer failure' );
			}
		);
		$log = new McpAuditLog();
		for ( $i = 0; $i < McpAuditLog::MAX_ENTRIES + 10; $i++ ) {
			$log->record( 'get-gallery', 1, McpAuditLog::DENIED, 'swg_forbidden' );
		}
		$this->assertCount( McpAuditLog::MAX_ENTRIES, $log->recent() );
		$log->record( 'Bad/Name', 1, 'weird', 'Bad Code' );
		$last = $log->recent()[0];
		$this->assertSame( array( 'invalid', 'error', 'swg_invalid_code' ), array( $last['ability'], $last['outcome'], $last['code'] ) );
		$this->assertArrayNotHasKey( McpAuditLog::OPTION, wp_load_alloptions( true ), 'the audit option is not autoloaded' );
	}

	public function test_throwing_definitions_become_a_generic_error(): void {
		$logger = \SWGallery\WordPress\Infrastructure\Services::logger();
		$logger->clear();
		add_filter(
			'swg_before_gallery_update',
			static function (): never {
				throw new RuntimeException( '/var/www/secret/path SELECT leak' );
			}
		);
		$result = $this->runAbility( 'update-gallery', array( 'id' => $this->gallery, 'expectedRevision' => 1, 'title' => 'Boom' ), $this->users['administrator'] );
		$this->assertNoLeak( $result, 'throwing listener' );
		$this->assertStringNotContainsString( 'secret', (string) wp_json_encode( $result instanceof WP_Error ? $result->get_error_message() : $result ) );
	}

	public function test_transport_refuses_anonymous_requests_on_every_route_and_method(): void {
		global $wp_rest_server;
		$wp_rest_server = null; // the adapter registers its routes on the next rest_api_init.
		$server         = rest_get_server();
		$routes = array_filter( array_keys( $server->get_routes() ), static fn( string $route ): bool => str_starts_with( $route, '/mcp/' ) );
		$this->assertNotSame( array(), $routes, 'the adapter registered its MCP route' );
		foreach ( $routes as $route ) {
			foreach ( array( 'POST', 'GET', 'DELETE' ) as $method ) {
				wp_set_current_user( 0 );
				$request = new WP_REST_Request( $method, $route );
				$request->set_header( 'content-type', 'application/json' );
				$request->set_body( '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' );
				$response = rest_do_request( $request );
				$this->assertContains( $response->get_status(), array( 401, 403 ), "$method $route anonymous" );
			}
		}
	}

	public function test_cookie_authentication_needs_a_valid_nonce_before_the_transport_runs(): void {
		// REST authentication runs in serve_request() through this filter (core), before any route callback.
		$GLOBALS['wp_rest_auth_cookie'] = true; // the request authenticated through the login cookie.
		wp_set_current_user( $this->users['administrator'] );
		unset( $_SERVER['HTTP_X_WP_NONCE'], $_REQUEST['_wpnonce'] );
		apply_filters( 'rest_authentication_errors', null );
		$this->assertSame( 0, get_current_user_id(), 'a cookie session without a nonce is anonymous' );

		wp_set_current_user( $this->users['administrator'] );
		$_SERVER['HTTP_X_WP_NONCE'] = 'invalid';
		$error                      = apply_filters( 'rest_authentication_errors', null );
		unset( $_SERVER['HTTP_X_WP_NONCE'], $GLOBALS['wp_rest_auth_cookie'] );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 403, $error->get_error_data()['status'] );
	}
}
