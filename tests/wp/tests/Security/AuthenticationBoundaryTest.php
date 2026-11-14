<?php
/**
 * Nonce, cookie and credential boundary of the REST API (M2-13 open part, M5-04 b): cookie authentication without a
 * valid nonce is anonymous, application passwords authenticate as the real user and meet the same object-level checks,
 * a state change is never reachable through a safe method, and the (core) method override cannot skip any check.
 *
 * The requests run through the same pipeline WordPress uses for a real HTTP request: authentication filters first
 * (`WP_REST_Server::check_authentication()`), then dispatch.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Tests\Support\RestItemFixtures;

class AuthenticationBoundaryTest extends SwgTestCase {

	use RestItemFixtures;

	/** @var array<string, mixed> */
	private array $server_backup = array();

	public function set_up(): void {
		parent::set_up();
		$this->server_backup = $_SERVER;
		$this->bootRest();
	}

	public function tear_down(): void {
		$_SERVER = $this->server_backup;
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTP_X_WP_NONCE'], $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] );
		unset( $GLOBALS['wp_rest_auth_cookie'], $GLOBALS['wp_rest_application_password_status'], $GLOBALS['wp_rest_application_password_uuid'] );
		remove_all_filters( 'wp_is_application_passwords_available' );
		remove_all_filters( 'application_password_is_api_request' );
		$this->shutdownRest();
		parent::tear_down();
	}

	/**
	 * Runs one request the way a browser with a login cookie would: the cookie identifies the user, the nonce header is
	 * optional.
	 *
	 * @param array<string, string> $headers Extra headers (`X-WP-Nonce`, override headers).
	 * @param array<string, mixed>  $query   Query parameters.
	 * @param array<string, mixed>  $body    JSON body.
	 */
	private function cookie_request( int $user, string $method, string $path, array $headers = array(), array $query = array(), array $body = array() ): WP_REST_Response {
		wp_set_current_user( $user );
		$GLOBALS['wp_rest_auth_cookie'] = true;
		unset( $_SERVER['HTTP_X_WP_NONCE'], $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );
		foreach ( $headers as $name => $value ) {
			$_SERVER[ 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) ) ] = $value;
		}
		$error = rest_get_server()->check_authentication();
		if ( is_wp_error( $error ) ) {
			return rest_convert_error_to_response( $error );
		}
		$request = new WP_REST_Request( $method, '/swg/v1' . $path );
		$request->set_query_params( $query );
		if ( array() !== $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_do_request( $request );
	}

	public function test_cookie_without_a_nonce_is_anonymous(): void {
		$admin = $this->makeUser( 'administrator' );
		$id    = $this->makeGallery( $admin );
		foreach ( array( '/galleries', '/galleries/' . $id, '/settings', '/status' ) as $path ) {
			$response = $this->cookie_request( $admin, 'GET', $path );
			$this->assertSame( 401, $response->get_status(), $path );
			$this->assertSame( 0, get_current_user_id(), 'the request continues as the anonymous user' );
		}
		$this->assertSame( 401, $this->cookie_request( $admin, 'POST', '/galleries', array(), array(), array( 'title' => 'CSRF' ) )->get_status() );
		$this->assertSame( 401, $this->cookie_request( $admin, 'DELETE', '/galleries/' . $id, array(), array( 'expectedRevision' => 1, 'force' => 'true' ) )->get_status() );
		$this->assertSame( 1, $this->revisionOf( $id ), 'nothing changed' );
		$this->assertSame( 'draft', get_post_status( $id ) );
	}

	public function test_cookie_with_an_invalid_nonce_is_refused(): void {
		$admin = $this->makeUser( 'administrator' );
		$id    = $this->makeGallery( $admin );
		foreach ( array( 'garbage', '0', wp_create_nonce( 'something_else' ), str_repeat( 'a', 10000 ) ) as $nonce ) {
			$response = $this->cookie_request( $admin, 'DELETE', '/galleries/' . $id, array( 'X-WP-Nonce' => $nonce ), array( 'expectedRevision' => 1, 'force' => 'true' ) );
			$this->assertSame( 403, $response->get_status() );
			$this->assertSame( 'rest_cookie_invalid_nonce', $response->get_data()['code'] );
		}
		$this->assertNotNull( get_post( $id ), 'the gallery survived every forged request' );
	}

	public function test_a_nonce_of_another_user_does_not_authenticate(): void {
		$admin  = $this->makeUser( 'administrator' );
		$author = $this->makeUser( 'author' );
		wp_set_current_user( $author );
		$foreign = wp_create_nonce( 'wp_rest' );
		$response = $this->cookie_request( $admin, 'GET', '/status', array( 'X-WP-Nonce' => $foreign ) );
		$this->assertSame( 403, $response->get_status(), 'a nonce is bound to the user session' );
	}

	public function test_cookie_with_a_valid_nonce_works_and_object_checks_still_apply(): void {
		$owner    = $this->makeUser( 'author' );
		$stranger = $this->makeUser( 'author' );
		$id       = $this->makeGallery( $owner );

		wp_set_current_user( $owner );
		$ok = $this->cookie_request( $owner, 'GET', '/galleries/' . $id, array( 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) ) );
		$this->assertSame( 200, $ok->get_status() );

		wp_set_current_user( $stranger );
		$nonce   = wp_create_nonce( 'wp_rest' );
		$refused = $this->cookie_request( $stranger, 'GET', '/galleries/' . $id, array( 'X-WP-Nonce' => $nonce ) );
		$this->assertSame( 404, $refused->get_status(), 'a valid nonce is not a license for foreign objects' );
	}

	public function test_application_password_authenticates_as_the_real_user_with_the_same_object_checks(): void {
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'application_password_is_api_request', '__return_true' );
		$owner    = $this->makeUser( 'author' );
		$stranger = $this->makeUser( 'author' );
		$id       = $this->makeGallery( $owner );

		$credentials = array();
		foreach ( array( 'owner' => $owner, 'stranger' => $stranger ) as $label => $user_id ) {
			[ $password, ] = WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => 'security test ' . $label ) );
			$credentials[ $label ] = array( get_userdata( $user_id )->user_login, $password );
		}

		$statuses = array();
		foreach ( $credentials as $label => $pair ) {
			wp_set_current_user( 0 );
			unset( $GLOBALS['wp_rest_auth_cookie'], $_SERVER['HTTP_X_WP_NONCE'] );
			$_SERVER['PHP_AUTH_USER'] = $pair[0];
			$_SERVER['PHP_AUTH_PW']   = $pair[1];
			$user                     = apply_filters( 'determine_current_user', false );
			wp_set_current_user( (int) $user );
			$statuses[ $label ] = rest_do_request( new WP_REST_Request( 'GET', '/swg/v1/galleries/' . $id ) )->get_status();
		}
		$this->assertSame( array( 'owner' => 200, 'stranger' => 404 ), $statuses );

		// A wrong password never authenticates.
		wp_set_current_user( 0 );
		$_SERVER['PHP_AUTH_USER'] = $credentials['owner'][0];
		$_SERVER['PHP_AUTH_PW']   = 'wrong wrong wrong wrong';
		$this->assertFalse( (bool) apply_filters( 'determine_current_user', false ) );
	}

	public function test_no_state_change_is_reachable_through_safe_methods(): void {
		foreach ( rest_get_server()->get_routes( 'swg/v1' ) as $route => $handlers ) {
			if ( '/swg/v1' === $route ) {
				continue;
			}
			foreach ( $handlers as $handler ) {
				$methods = array_keys( (array) $handler['methods'] );
				if ( ! in_array( 'GET', $methods, true ) && ! in_array( 'HEAD', $methods, true ) ) {
					continue;
				}
				$this->assertSame( array( 'GET' ), $methods, $route . ': a readable handler serves nothing else' );
				$callback = $handler['callback'];
				$this->assertIsArray( $callback );
				$this->assertMatchesRegularExpression( '/^get_items?$/', $callback[1], $route . ': the GET handler is a read method' );
			}
		}
	}

	public function test_wrong_methods_are_refused_before_any_handler_runs(): void {
		$admin = $this->makeUser( 'administrator' );
		$id    = $this->makeGallery( $admin );
		$cases = array(
			array( 'PUT', '/galleries/' . $id ),
			array( 'POST', '/galleries/' . $id ),
			array( 'GET', '/galleries/' . $id . '/restore' ),
			array( 'DELETE', '/galleries' ),
			array( 'PATCH', '/status' ),
			array( 'POST', '/layouts' ),
			array( 'DELETE', '/media' ),
		);
		foreach ( $cases as $case ) {
			$response = $this->call( $case[0], $case[1], array(), $admin );
			$this->assertSame( 404, $response->get_status(), $case[0] . ' ' . $case[1] );
			$this->assertSame( 'rest_no_route', $response->get_data()['code'] );
		}
		$this->assertSame( 1, $this->revisionOf( $id ) );
	}

	public function test_method_override_cannot_skip_the_nonce_or_the_object_checks(): void {
		$owner    = $this->makeUser( 'author' );
		$stranger = $this->makeUser( 'author' );
		$id       = $this->makeGallery( $owner, GalleryStatus::Draft );

		// WordPress core lets `?_method=` and `X-HTTP-Method-Override` change the method: a forged cross-site GET with a
		// cookie but no nonce is anonymous before the override matters.
		$forged = $this->cookie_request( $owner, 'DELETE', '/galleries/' . $id, array( 'X-HTTP-Method-Override' => 'DELETE' ), array( 'expectedRevision' => 1, 'force' => 'true', '_method' => 'DELETE' ) );
		$this->assertSame( 401, $forged->get_status() );
		$this->assertSame( 'draft', get_post_status( $id ) );

		// With a nonce the overridden method meets the object check of that method.
		wp_set_current_user( $stranger );
		$nonce    = wp_create_nonce( 'wp_rest' );
		$response = $this->cookie_request( $stranger, 'DELETE', '/galleries/' . $id, array( 'X-WP-Nonce' => $nonce, 'X-HTTP-Method-Override' => 'DELETE' ), array( 'expectedRevision' => 1, 'force' => 'true' ) );
		$this->assertSame( 404, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertNotNull( get_post( $id ) );
	}
}
