<?php
/**
 * Real client path through the pinned mcp-adapter: initialize, discover, execute (JSON-RPC over the REST route).
 *
 * @package sw-gallery
 */

require_once dirname( __DIR__, 2 ) . '/support/McpSecurityTestTrait.php';

use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Tests\Support\RestFixtures;
use SWGallery\WordPress\Mcp\McpAuditLog;
use SWGallery\WordPress\Mcp\TierPolicy;

class McpTransportEndToEndTest extends SwgTestCase {

	use RestFixtures;
	use McpSecurityTestTrait;

	private const ROUTE = '/mcp/mcp-adapter-default-server';

	private int $author  = 0;
	private int $other   = 0;
	private int $gallery = 0;

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( '\\WP\\MCP\\Plugin', false ) ) {
			$this->markTestSkipped( 'The mcp-adapter is not loaded (absence run).' );
		}
		$this->bootRest();
		$this->author  = $this->makeUser( 'author' );
		$this->other   = $this->makeUser( 'author' );
		$this->gallery = $this->makeGallery( $this->author, GalleryStatus::Draft, 'Transport draft' );
		$this->reregisterAbilities( 'admin' );
		global $wp_rest_server;
		$wp_rest_server = null;
		rest_get_server();
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

	/**
	 * @param array<string, mixed> $body JSON-RPC message.
	 * @return array<string, mixed>
	 */
	private function rpc( array $body, ?string $session = null ): array {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'accept', 'application/json, text/event-stream' );
		if ( null !== $session ) {
			$request->set_header( 'mcp-session-id', $session );
		}
		$request->set_body( (string) wp_json_encode( $body ) );
		$response = rest_do_request( $request );
		$data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );
		return array(
			'status'  => $response->get_status(),
			'session' => $response->get_headers()['Mcp-Session-Id'] ?? null,
			'data'    => is_array( $data ) ? $data : array(),
		);
	}

	private function connect( int $user ): string {
		wp_set_current_user( $user );
		$init = $this->rpc( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => array( 'protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => array( 'name' => 'phpunit', 'version' => '1' ) ) ) );
		$this->assertSame( 200, $init['status'] );
		$this->assertIsString( $init['session'] );
		$this->rpc( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ), $init['session'] );
		return $init['session'];
	}

	/**
	 * @param array<string, mixed>|stdClass $arguments Tool arguments.
	 * @return array<string, mixed> The tool result (`success`, `data`, `error`).
	 */
	private function tool( string $session, string $tool, array|stdClass $arguments ): array {
		$reply = $this->rpc( array( 'jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => array( 'name' => $tool, 'arguments' => $arguments ) ), $session );
		$this->assertSame( 200, $reply['status'] );
		$result = $reply['data']['result'] ?? array();
		if ( true === ( $result['isError'] ?? false ) ) {
			// Tool-level failure as a client sees it: a refusal text only, no code, no data.
			return array( 'success' => false, 'error' => (string) ( $result['content'][0]['text'] ?? '' ) );
		}
		$this->assertArrayHasKey( 'structuredContent', $result, wp_json_encode( $reply['data'] ) );
		return $result['structuredContent'];
	}

	/** @return list<string> */
	private function discovered( string $session ): array {
		return array_column( $this->tool( $session, 'mcp-adapter-discover-abilities', new stdClass() )['abilities'], 'name' );
	}

	public function test_discovery_lists_only_abilities_of_the_effective_tier(): void {
		$session = $this->connect( $this->author );
		$names   = $this->discovered( $session );
		$this->assertContains( 'sw-gallery/get-gallery', $names );
		$this->assertContains( 'sw-gallery/update-gallery', $names );

		$this->reregisterAbilities( 'read' );
		$names = $this->discovered( $session );
		$this->assertContains( 'sw-gallery/get-gallery', $names );
		$this->assertNotContains( 'sw-gallery/update-gallery', $names );
		$refused = $this->tool( $session, 'mcp-adapter-execute-ability', array( 'ability_name' => 'sw-gallery/update-gallery', 'parameters' => array( 'id' => $this->gallery, 'expectedRevision' => 1, 'title' => 'x' ) ) );
		$this->assertFalse( $refused['success'] );
	}

	public function test_owner_reads_and_writes_while_others_get_one_generic_refusal(): void {
		$session = $this->connect( $this->author );
		$read    = $this->tool( $session, 'mcp-adapter-execute-ability', array( 'ability_name' => 'sw-gallery/get-gallery', 'parameters' => array( 'id' => $this->gallery ) ) );
		$this->assertTrue( $read['success'], wp_json_encode( $read ) );
		$this->assertSame( 'Transport draft', $read['data']['title'] );

		$write = $this->tool( $session, 'mcp-adapter-execute-ability', array( 'ability_name' => 'sw-gallery/update-gallery', 'parameters' => array( 'id' => $this->gallery, 'expectedRevision' => $read['data']['revision'], 'title' => 'Via MCP' ) ) );
		$this->assertTrue( $write['success'], wp_json_encode( $write ) );
		$this->assertSame( 'Via MCP', $write['data']['title'] );

		$replay = $this->tool( $session, 'mcp-adapter-execute-ability', array( 'ability_name' => 'sw-gallery/update-gallery', 'parameters' => array( 'id' => $this->gallery, 'expectedRevision' => $read['data']['revision'], 'title' => 'Stale' ) ) );
		$this->assertFalse( $replay['success'], 'the stale revision is refused' );
		$this->assertSame( 'Via MCP', $this->tool( $session, 'mcp-adapter-execute-ability', array( 'ability_name' => 'sw-gallery/get-gallery', 'parameters' => array( 'id' => $this->gallery ) ) )['data']['title'] );

		$other   = $this->connect( $this->other );
		$hidden  = $this->tool( $other, 'mcp-adapter-execute-ability', array( 'ability_name' => 'sw-gallery/get-gallery', 'parameters' => array( 'id' => $this->gallery ) ) );
		$missing = $this->tool( $other, 'mcp-adapter-execute-ability', array( 'ability_name' => 'sw-gallery/get-gallery', 'parameters' => array( 'id' => 999999 ) ) );
		$this->assertFalse( $hidden['success'] );
		$this->assertSame( $missing, $hidden, 'a hidden gallery looks exactly like a missing one' );
		$this->assertSame( 'The gallery was not found.', $hidden['error'] );
		$this->assertStringNotContainsString( 'Via MCP', (string) wp_json_encode( $hidden ) );
	}

	public function test_a_subscriber_reaches_the_transport_but_no_gallery_ability(): void {
		$session = $this->connect( $this->makeUser( 'subscriber' ) );
		$result = $this->tool( $session, 'mcp-adapter-execute-ability', array( 'ability_name' => 'sw-gallery/get-gallery', 'parameters' => array( 'id' => $this->gallery ) ) );
		$this->assertFalse( $result['success'] );
	}

	public function test_unknown_and_foreign_abilities_are_not_executable(): void {
		$session = $this->connect( $this->author );
		foreach ( array( 'sw-gallery/delete-everything', 'other-plugin/anything', '../../etc/passwd' ) as $name ) {
			$result = $this->tool( $session, 'mcp-adapter-execute-ability', array( 'ability_name' => $name, 'parameters' => array() ) );
			$this->assertFalse( $result['success'], $name );
		}
	}

	public function test_anonymous_clients_cannot_open_a_session(): void {
		wp_set_current_user( 0 );
		$init = $this->rpc( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => array( 'protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => array( 'name' => 'x', 'version' => '1' ) ) ) );
		$this->assertContains( $init['status'], array( 401, 403 ) );
		$this->assertNull( $init['session'] );
	}
}
